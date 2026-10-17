<?php
/**
 * Methods extracted from the latest functional main implementation.
 * The methods execute in the LogicboxesEmail module context.
 */
trait ServiceSyncTrait
{

    public function install()
    {
        $this->registerSyncCronTask();
    }

    public function upgrade($current_version)
    {
        // Always ensure the task exists and is enabled when upgrading from any
        // previous 0.5.x version, including installations already on 0.5.8.
        $this->registerSyncCronTask();
    }

    public function uninstall($module_id, $last_instance)
    {
        if (!$last_instance) {
            return;
        }
        try {
            Loader::loadModels($this, ['CronTasks']);
            $run = $this->CronTasks->getTaskRunByKey('sync_orderbox_services', 'logicboxes_email', false, 'module');
            if ($run) {
                $this->CronTasks->deleteTaskRun($run->task_run_id);
            }
            $task = $this->CronTasks->getByKey('sync_orderbox_services', 'logicboxes_email', 'module');
            if ($task) {
                $this->CronTasks->deleteTask($task->id, 'module', 'logicboxes_email');
            }
        } catch (Throwable $e) {
            $this->logEmailDiagnostic('Could not remove OrderBox sync cron task: ' . $e->getMessage());
        }
    }

    private function registerSyncCronTask()
    {
        try {
            Loader::loadModels($this, ['CronTasks']);
            $key = 'sync_orderbox_services';
            $task = $this->CronTasks->getByKey($key, 'logicboxes_email', 'module');
            if (!$task) {
                $taskId = $this->CronTasks->add([
                    'key' => $key,
                    'task_type' => 'module',
                    'dir' => 'logicboxes_email',
                    'name' => 'LogicBoxes Email: synchronize services',
                    'description' => 'Synchronize mailbox and extra-storage quantities from OrderBox to Blesta.',
                    'type' => 'interval'
                ]);
                if (!$taskId) {
                    $task = $this->CronTasks->getByKey($key, 'logicboxes_email', 'module');
                    $taskId = $task->id ?? null;
                }
            } else {
                $taskId = $task->id;
            }
            if (!$taskId) {
                $this->logEmailDiagnostic('Could not register OrderBox synchronization cron task.');
                return;
            }
            $run = $this->CronTasks->getTaskRunByKey($key, 'logicboxes_email', false, 'module');
            if (!$run) {
                $this->CronTasks->addTaskRun($taskId, ['interval' => '5', 'enabled' => 1]);
            } else {
                $this->CronTasks->editTaskRun($run->task_run_id, ['interval' => '5', 'enabled' => 1]);
            }
        } catch (Throwable $e) {
            $this->logEmailDiagnostic('Could not register OrderBox synchronization cron task: ' . $e->getMessage());
        }
    }

    public function cron($key)
    {
        if ($key !== 'sync_orderbox_services') {
            return;
        }
        $this->logEmailDiagnostic('Cron sync_orderbox_services started.');
        try {
            Loader::loadModels($this, ['Services', 'Invoices', 'Packages']);
            if (!method_exists($this->Services, 'getAll')) {
                $this->logEmailDiagnostic('Automatic sync skipped: Services::getAll() is unavailable in this Blesta version.');
                return;
            }
            $checked = 0;
            $synced = 0;
            foreach ((array)$this->Services->getAll() as $service) {
                $serviceId = (int)($service->id ?? 0);
                if ($serviceId < 1) continue;
                $fullService = $this->Services->get($serviceId);
                if (!is_object($fullService)) continue;
                $fields = $this->serviceFieldsToObject($fullService->fields ?? []);
                if (trim((string)($fields->email_order_id ?? '')) === '') continue;

                // Deferred checkout change: apply only after the invoice created for
                // THIS change is paid (date_closed set). See processDeferredChange().
                if ($this->isTruthyFlag($fields->email_pending_change ?? '0')) {
                    if (!$this->processDeferredChange($fullService, $fields)) {
                        continue;
                    }
                    $fullService = $this->Services->get($serviceId);
                    if (!is_object($fullService)) continue;
                    $fields = $this->serviceFieldsToObject($fullService->fields ?? []);
                }
                if (!in_array(strtolower(trim((string)($fields->email_sync_pending ?? '0'))), ['1', 'true', 'yes'], true)) continue;
                $checked++;
                if ($this->syncMailboxConfigOptionFromOrderBox($fullService)) {
                    $result = $this->Services->editField($serviceId, [
                        'key' => 'email_sync_pending', 'value' => '0', 'encrypted' => 'false'
                    ]);
                    if ($result !== false) $synced++;
                    else $this->logEmailDiagnostic('Could not clear email_sync_pending for service ' . $serviceId . '; it will be retried.');
                }
            }
            // A normal cron completion is module activity, not a system error.
            $this->log('OrderBox scheduled reconciliation', 'Finished; pending checked=' . $checked . ', synchronized=' . $synced . '.', 'output', true);
        } catch (Throwable $e) {
            $this->logEmailDiagnostic('Scheduled OrderBox reconciliation failed: ' . $e->getMessage());
        }
    }

    private function isTruthyFlag($value)
    {
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes'], true);
    }

    private function moduleLog($message, $success = true)
    {
        try {
            $this->log('LogicBoxes Email: deferred upgrade', (string)$message, 'output', (bool)$success);
        } catch (Throwable $e) {
            // Logging must never break processing.
        }
        $this->logEmailDiagnostic($message);
    }

    private function resolveServicePackage($service)
    {
        Loader::loadModels($this, ['Packages']);
        $packageId = (int)($service->package->id ?? ($service->package_id ?? 0));
        if ($packageId > 0) {
            $package = $this->Packages->get($packageId);
            if ($package) return $package;
        }
        if (!empty($service->pricing_id) && method_exists($this->Packages, 'getByPricingId')) {
            $package = $this->Packages->getByPricingId((int)$service->pricing_id);
            if ($package) return $package;
        }
        return null;
    }

    private function findLatestServiceInvoice($serviceId)
    {
        Loader::loadComponents($this, ['Record']);
        $row = $this->Record->query(
            "SELECT i.id, i.id_value, i.status, i.date_billed, i.date_closed
               FROM invoice_lines AS l
              INNER JOIN invoices AS i ON i.id = l.invoice_id
              WHERE l.service_id = ? AND i.status IN ('active', 'proforma', 'void')
              ORDER BY i.id DESC
              LIMIT 1",
            (int)$serviceId
        )->fetch();
        return $row ? (object)$row : null;
    }

    private function saveServiceFields($serviceId, array $resultFields)
    {
        Loader::loadModels($this, ['Services']);
        $existing = [];
        $service = $this->Services->get($serviceId);
        foreach ((array)($service->fields ?? []) as $f) {
            if (isset($f->key)) $existing[$f->key] = true;
        }
        $ok = true;
        foreach ($resultFields as $field) {
            if (!isset($field['key'])) continue;
            $data = [
                'key' => $field['key'],
                'value' => (string)($field['value'] ?? ''),
                'encrypted' => !empty($field['encrypted']) ? 'true' : 'false'
            ];
            $r = isset($existing[$field['key']])
                ? $this->Services->editField($serviceId, $data)
                : $this->Services->addField($serviceId, $data);
            if ($r === false) $ok = false;
        }
        return $ok;
    }

    private function findServiceInvoicesSince($serviceId, $sinceUtc)
    {
        Loader::loadComponents($this, ['Record']);
        $rows = $this->Record->query(
            "SELECT DISTINCT i.id, i.id_value, i.status, i.date_billed, i.date_closed
               FROM invoice_lines AS l
              INNER JOIN invoices AS i ON i.id = l.invoice_id
              WHERE l.service_id = ? AND i.status IN ('active', 'proforma', 'void')
                AND i.date_billed >= ?
              ORDER BY i.id ASC",
            (int)$serviceId,
            $sinceUtc
        )->fetchAll();
        $out = [];
        foreach ((array)$rows as $r) $out[] = (object)$r;
        return $out;
    }

    private function clearPendingChange($serviceId)
    {
        return $this->saveServiceFields($serviceId, [
            ['key' => 'email_pending_change', 'value' => '0', 'encrypted' => 0],
            ['key' => 'email_pending_accounts', 'value' => '', 'encrypted' => 0],
            ['key' => 'email_pending_storage_blocks', 'value' => '', 'encrypted' => 0],
            ['key' => 'email_pending_since', 'value' => '', 'encrypted' => 0]
        ]);
    }

    private function processDeferredChange($service, $fields)
    {
        $serviceId = (int)$service->id;

        $curAccounts = (int)($fields->email_accounts ?? 0);
        $curBlocks = (int)($fields->email_storage_blocks ?? 0);
        $acc = trim((string)($fields->email_pending_accounts ?? ''));
        $blk = trim((string)($fields->email_pending_storage_blocks ?? ''));
        $targetAccounts = (int)($acc !== '' ? $acc : $curAccounts);
        $targetBlocks = (int)($blk !== '' ? $blk : $curBlocks);

        if ($targetAccounts === $curAccounts && $targetBlocks === $curBlocks) {
            $this->clearPendingChange($serviceId);
            $this->moduleLog('Service ' . $serviceId . ': pending change equals current quantities; cleared.', true);
            return false;
        }

        $isIncrease = ($targetAccounts > $curAccounts || $targetBlocks > $curBlocks);
        $label = $isIncrease ? 'paid upgrade' : 'downgrade';
        $number = '-';

        if ($isIncrease) {
            $since = trim((string)($fields->email_pending_since ?? ''));
            $sinceTs = $since !== '' ? strtotime($since . ' UTC') : false;
            if ($sinceTs === false) {
                $this->moduleLog('Service ' . $serviceId . ': pending upgrade has no valid email_pending_since; waiting (apply manually with Save).', false);
                return false;
            }
            try {
                $invoices = $this->findServiceInvoicesSince($serviceId, gmdate('Y-m-d H:i:s', $sinceTs - 600));
            } catch (Throwable $e) {
                $this->moduleLog('Service ' . $serviceId . ': could not read invoices: ' . $e->getMessage(), false);
                return false;
            }
            $active = [];
            $unpaid = [];
            foreach ($invoices as $inv) {
                if ($inv->status === 'void') continue;
                $active[] = $inv;
                if (empty($inv->date_closed)) $unpaid[] = (string)($inv->id_value ?? $inv->id);
            }
            if (empty($invoices)) {
                $this->logEmailDiagnostic('Service ' . $serviceId . ': pending upgrade, no invoice since ' . $since . ' UTC yet; waiting.');
                return false;
            }
            if (empty($active)) {
                $this->clearPendingChange($serviceId);
                $this->moduleLog('Service ' . $serviceId . ': all invoices for the pending change were voided; change cancelled.', true);
                return false;
            }
            if (!empty($unpaid)) {
                $this->logEmailDiagnostic('Service ' . $serviceId . ': invoice(s) ' . implode(', ', $unpaid) . ' not paid yet; waiting.');
                return false;
            }
            $nums = [];
            foreach ($active as $inv) $nums[] = (string)($inv->id_value ?? $inv->id);
            $number = implode(', ', $nums);
        }

        $package = $this->resolveServicePackage($service);
        if (!$package) {
            $this->moduleLog('Service ' . $serviceId . ': ' . $label . ' ready, but the package could not be loaded.', false);
            return false;
        }

        try {
            $result = $this->editService($package, $service, [
                'mailboxes' => $targetAccounts,
                'extra storage' => $targetBlocks,
                'use_module' => 'true'
            ]);
        } catch (Throwable $e) {
            $this->moduleLog('Service ' . $serviceId . ': exception while applying ' . $label . ': ' . $e->getMessage(), false);
            return false;
        }
        if (!is_array($result)) {
            $errors = method_exists($this->Input, 'errors') ? $this->Input->errors() : [];
            $this->moduleLog('Service ' . $serviceId . ': ' . $label . ' failed in OrderBox: ' . json_encode($errors), false);
            return false;
        }
        if (!$this->saveServiceFields($serviceId, $result)) {
            $this->moduleLog('Service ' . $serviceId . ': OrderBox updated, but saving Blesta service fields failed.', false);
            return false;
        }
        $this->moduleLog('Service ' . $serviceId . ': applied ' . $label
            . ($isIncrease ? ' from invoice(s) ' . $number : '')
            . ' (accounts=' . $targetAccounts . ', storage blocks=' . $targetBlocks . ').', true);
        return true;
    }

    private function syncMailboxConfigOptionFromOrderBox($service, $package = null)
    {
        $serviceId = (int)($service->id ?? 0);
        if ($serviceId < 1) {
            return false;
        }

        // The object passed by Blesta varies by page and may not contain fields,
        // package_id, or module_row_id. Load the canonical service record instead
        // of silently skipping synchronization when a partial object is supplied.
        try {
            Loader::loadModels($this, ['Services']);
            $fullService = $this->Services->get($serviceId);
            if (is_object($fullService)) {
                $service = $fullService;
            }
        } catch (Throwable $e) {
            $this->logEmailDiagnostic('Could not load full service ' . $serviceId . ' before configurable-option sync: ' . $e->getMessage());
        }

        $serviceId = (int)($service->id ?? $serviceId);
        // Admin/client tab callbacks sometimes supply a partial service object;
        // the package argument is authoritative when available.
        $packageId = (int)($package->id ?? ($package->package_id ?? ($service->package_id ?? ($service->package->id ?? ($service->package->package_id ?? 0)))));
        if ($packageId < 1 && isset($service->package) && is_numeric($service->package)) {
            $packageId = (int)$service->package;
        }
        $fields = $this->serviceFieldsToObject($service->fields ?? []);
        $orderId = trim((string)($fields->email_order_id ?? ''));
        $product = (string)($fields->email_product ?? 'business_email');
        $rowId = $service->module_row_id ?? $service->module_row ?? ($service->package->module_row ?? null);
        // Services::get() does not expose the module row consistently in every
        // Blesta view. Fall back to the package record before giving up.
        if (!$rowId && $packageId > 0) {
            try {
                Loader::loadModels($this, ['Packages']);
                $packageRecord = $this->Packages->get($packageId);
                if (is_object($packageRecord)) {
                    $rowId = $packageRecord->module_row ?? $packageRecord->module_row_id ?? null;
                    if (!$rowId && isset($packageRecord->module_group_id)) $rowId = $packageRecord->module_group_id;
                }
            } catch (Throwable $e) {
                $this->logEmailDiagnostic('Could not resolve module row from package ' . $packageId . ': ' . $e->getMessage());
            }
        }
        // Some Blesta views return a service object without package_id even after
        // Services::get(). Resolve the package through the canonical package record
        // when possible before abandoning the sync.
        if ($packageId < 1 && $serviceId > 0) {
            try {
                Loader::loadModels($this, ['Services']);
                foreach (['get', 'getById'] as $method) {
                    if (method_exists($this->Services, $method)) {
                        $candidate = $this->Services->{$method}($serviceId);
                        if (is_object($candidate)) {
                            $packageId = (int)($candidate->package_id ?? ($candidate->package->id ?? ($candidate->package->package_id ?? 0)));
                            if ($packageId > 0) break;
                        }
                    }
                }
            } catch (Throwable $e) {
                $this->logEmailDiagnostic('Could not resolve package ID for service ' . $serviceId . ': ' . $e->getMessage());
            }
        }
        $row = $this->getModuleRow($rowId);

        if ($serviceId < 1 || $packageId < 1 || $orderId === '' || !$row
            || empty($row->meta->reseller_id) || empty($row->meta->key)) {
            $this->logEmailDiagnostic('Skipped configurable-option sync because service data is incomplete. service=' . $serviceId . ', package=' . $packageId . ', order=' . ($orderId !== '' ? 'present' : 'missing') . ', row=' . (is_object($row) ? 'present' : 'missing'));
            return false;
        }

        try {
            $api = $this->getLogicboxesApi(
                $row->meta->reseller_id,
                $row->meta->key,
                ($row->meta->sandbox ?? 'false') === 'true'
            );
            $prefix = $product === 'enterprise_email' ? 'enterpriseemail/us' : 'eelite/us';
            $response = $api->submit($prefix . '/details', ['order-id' => $orderId], 'GET');
            $this->logApiResponse($api, $response);
            if (!$this->isSuccessfulResponse($response)) {
                $this->logEmailDiagnostic('OrderBox details request failed during configurable-option sync for service ' . $serviceId . ': ' . $this->responseError($response));
                return false;
            }

            $details = $response->response();
            if (is_object($details) && isset($details->response) && is_object($details->response)) {
                $details = $details->response;
            }

            // Do not reconcile local quantities while an OrderBox action is pending.
            // Some responses may retain actiontype after completion, so the presence
            // of actiontype alone is not sufficient to classify an action as pending.
            $actionType = is_object($details) ? ($details->actiontype ?? '') : (is_array($details) ? ($details['actiontype'] ?? '') : '');
            $actionStatus = is_object($details) ? ($details->actionstatus ?? '') : (is_array($details) ? ($details['actionstatus'] ?? '') : '');
            $actionCompleted = is_object($details)
                ? ($details->actioncompleted ?? $details->{'action-completed'} ?? '')
                : (is_array($details) ? ($details['actioncompleted'] ?? $details['action-completed'] ?? '') : '');
            $normalizedActionStatus = strtolower(trim((string)$actionStatus));
            $normalizedActionCompleted = strtolower(trim((string)$actionCompleted));
            $pendingActionStatuses = [
                'begunexecution', 'pending', 'processing', 'inprogress', 'in progress',
                'queued', 'waiting', 'waiting to be processed'
            ];
            $completedActionStatuses = ['completed', 'complete', 'success', 'successful', 'failed', 'cancelled', 'canceled'];
            $hasPendingAction = in_array($normalizedActionStatus, $pendingActionStatuses, true)
                || ($actionType !== '' && in_array($normalizedActionCompleted, ['false', '0', 'no'], true))
                || ($actionType !== '' && $normalizedActionStatus === '' && $normalizedActionCompleted === '');
            if ($hasPendingAction && !in_array($normalizedActionStatus, $completedActionStatuses, true)) {
                $this->logEmailDiagnostic(
                    'Skipped configurable-option sync for service ' . $serviceId
                    . ': OrderBox action still pending (actiontype=' . (string)$actionType
                    . ', actionstatus=' . (string)$actionStatus
                    . ', actioncompleted=' . (string)$actionCompleted
                    . '). The sync will be retried automatically the next time the client/admin service tabs are loaded.'
                );
                return false;
            }

            $providerAccounts = $this->firstApiValue($details, [
                'emailaccounts', 'email-accounts', 'email_accounts',
                'numberofemailaccounts', 'number-of-email-accounts'
            ]);
            if ($providerAccounts === '' || !is_numeric($providerAccounts) || (int)$providerAccounts < 1) {
                $this->logEmailDiagnostic('Could not read contracted emailaccounts from OrderBox details for service ' . $serviceId . '; response=' . json_encode($details));
                return false;
            }
            $providerAccounts = (int)$providerAccounts;
            // OrderBox returns storage add-ons nested under addon.email_storage_5.quantity.
            // Do not infer it from service fields: those may be stale after a manual change.
            $providerBlocks = null;
            $storageQty = $this->extractStorageBlocksFromOrderDetails($details);
            if (is_numeric($storageQty) && (int)$storageQty >= 0) {
                $providerBlocks = (int)$storageQty;
            } else {
                $detailsArray = is_object($details) ? (array)$details : (is_array($details) ? $details : []);
                // A successful details response with an explicit empty addons list means zero add-on blocks.
                // Missing/malformed addons remain unknown; never infer zero in that case.
                if ($providerBlocks === null && array_key_exists('addons', $detailsArray)
                    && is_array($detailsArray['addons']) && count($detailsArray['addons']) === 0) {
                    $providerBlocks = 0;
                    $this->logEmailDiagnostic('OrderBox returned an empty addons list for service ' . $serviceId . '; treating email_storage_5 quantity as 0.');
                } else {
                    $this->logEmailDiagnostic('OrderBox details did not expose email_storage_5.quantity for service ' . $serviceId . '; details=' . json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }
            }

            Loader::loadModels($this, ['Services', 'PackageOptions']);
            // Keep the module's own service fields aligned too, so capacity summaries
            // and subsequent edits use the provider's real contracted quantities.
            $this->Services->editField($serviceId, [
                'key' => 'email_accounts', 'value' => (string)$providerAccounts, 'encrypted' => 'false'
            ]);
            if ($providerBlocks !== null) {
                $this->Services->editField($serviceId, [
                    'key' => 'email_storage_blocks', 'value' => (string)$providerBlocks, 'encrypted' => 'false'
                ]);
            }
            $serviceOptions = $this->Services->getOptions($serviceId);
            if (!is_array($serviceOptions)) {
                $this->logEmailDiagnostic('Could not read current configurable options for service ' . $serviceId . '.');
                return false;
            }
			$formattedOptions = $this->PackageOptions->formatServiceOptions($serviceOptions);
			
			$configOptions = (
			    is_array($formattedOptions)
			    && isset($formattedOptions['configoptions'])
			    && is_array($formattedOptions['configoptions'])
			)
			    ? $formattedOptions['configoptions']
			    : [];
            $normalizeOptionName = function ($value) {
                $value = strtolower(trim((string)$value));
                $value = str_replace(['_', '-'], ' ', $value);
                return preg_replace('/\s+/', ' ', $value);
            };
            $wanted = [
                'mailboxes' => ['logicboxes email mailboxes', 'mailboxes', 'mailbox', 'email accounts', 'number of email accounts'],
                'storage' => ['logicboxes email extra storage', 'extra storage (5 gb blocks)', 'extra storage', 'storage blocks']
            ];
            $optionIds = ['mailboxes' => null, 'storage' => null];
            $packageOptions = $this->PackageOptions->getByPackageId($packageId);
            foreach ((array)$packageOptions as $option) {
                $record = is_object($option) ? (array)$option : (array)$option;
                $optionId = $record['id'] ?? $record['option_id'] ?? $record['package_option_id'] ?? null;
                $names = [];
                foreach (['name', 'label', 'option_name', 'key'] as $nameKey) {
                    if (isset($record[$nameKey]) && is_scalar($record[$nameKey])) $names[] = $normalizeOptionName($record[$nameKey]);
                }
                foreach (['option', 'package_option', 'field'] as $nestedKey) {
                    if (isset($record[$nestedKey])) {
                        $nested = is_object($record[$nestedKey]) ? (array)$record[$nestedKey] : (array)$record[$nestedKey];
                        foreach (['name', 'label', 'option_name', 'key'] as $nameKey) {
                            if (isset($nested[$nameKey]) && is_scalar($nested[$nameKey])) $names[] = $normalizeOptionName($nested[$nameKey]);
                        }
                        if (!$optionId) $optionId = $nested['id'] ?? $nested['option_id'] ?? $nested['package_option_id'] ?? null;
                    }
                }
                if (!$optionId || !is_numeric($optionId)) continue;
                foreach ($wanted as $type => $aliases) {
                    $normalizedAliases = array_map($normalizeOptionName, $aliases);
                    if (!$optionIds[$type] && array_intersect($names, $normalizedAliases)) $optionIds[$type] = (int)$optionId;
                }
            }
            if (!$optionIds['mailboxes']) {
                $this->logEmailDiagnostic('Could not identify the Mailboxes configurable option for package ' . $packageId . '; package option structure: ' . json_encode($packageOptions));
                return false;
            }
            if ($providerBlocks !== null && !$optionIds['storage']) {
                $this->logEmailDiagnostic('Could not identify the Extra storage configurable option for package ' . $packageId . '; storage quantity will not be synced.');
            }

            $changed = false;
            $changes = [];
            $mailboxOptionId = $optionIds['mailboxes'];
            $current = $configOptions[(string)$mailboxOptionId] ?? $configOptions[$mailboxOptionId] ?? null;
            if (!is_numeric($current) || (int)$current !== $providerAccounts) {
                $configOptions[$mailboxOptionId] = $providerAccounts;
                $changes['mailboxes'] = ['option_id' => $mailboxOptionId, 'old' => $current, 'new' => $providerAccounts];
                $changed = true;
            }
            if ($providerBlocks !== null && $optionIds['storage']) {
                $storageOptionId = $optionIds['storage'];
                $currentStorage = $configOptions[(string)$storageOptionId] ?? $configOptions[$storageOptionId] ?? 0;
                if (!is_numeric($currentStorage) || (int)$currentStorage !== $providerBlocks) {
                    $configOptions[$storageOptionId] = $providerBlocks;
                    $changes['storage'] = ['option_id' => $storageOptionId, 'old' => $currentStorage, 'new' => $providerBlocks];
                    $changed = true;
                }
            }
            if (!$changed) return true;
            $this->logEmailDiagnostic('Updating Blesta configurable options for service ' . $serviceId . ' from OrderBox: ' . json_encode($changes) . '; options=' . json_encode($configOptions));
            // Do not pass bypass_module=true here. Blesta's Services::edit() only
            // calls setServiceOptions() inside its module-processing branch, so the
            // bypass would skip persistence of configurable options altogether.
            // use_module=false is passed to editService() to suppress provider writes.
            $result = $this->Services->edit($serviceId, [
                'configoptions' => $configOptions,
                'use_module' => 'false'
            ]);
            if (!$result) {
                $errors = method_exists($this->Services, 'errors') ? $this->Services->errors() : [];
                $this->logEmailDiagnostic('Blesta rejected configurable-option sync for service ' . $serviceId . '. Result=' . var_export($result, true) . '; service_errors=' . json_encode($errors) . '; input_errors=' . json_encode(method_exists($this->Input, 'errors') ? $this->Input->errors() : []));
            } else {
                $this->logEmailDiagnostic('Synchronized Blesta configurable options for service ' . $serviceId . ' from OrderBox. Result=' . var_export($result, true));
                return true;
            }
            return false;
        } catch (Throwable $e) {
            $this->logEmailDiagnostic('Configurable-option sync failed for service ' . $serviceId . ': ' . $e->getMessage());
            return false;
        }
    }
}
