<?php
/**
 * KanumHost LogicBoxes Email module prototype.
 * Independent from the LogicBoxes domain registrar module.
 */
class LogicboxesEmail extends Module
{
    public function __construct()
    {
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');
        Loader::loadComponents($this, ['Input']);
        Language::loadLang('logicboxes_email', null, dirname(__FILE__) . DS . 'language' . DS);
    }

    /** Render the module account list, following the LogicBoxes domain module pattern. */
    public function manageModule($module, array &$vars)
    {
        $this->view = new View('manage', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'logicboxes_email' . DS);
        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);
        $this->view->set('module', $module);
        return $this->view->fetch();
    }

    public function manageAddRow(array &$vars)
    {
        $this->view = new View('add_row', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'logicboxes_email' . DS);
        Loader::loadHelpers($this, ['Form', 'Html', 'Javascript', 'Widget']);
        if (!empty($vars) && empty($vars['sandbox'])) {
            $vars['sandbox'] = 'false';
        }
        Loader::loadModels($this, ['ModuleManager']);
        $module = $this->ModuleManager->getByClass('logicboxes_email', Configure::get('Blesta.company_id'));
        $this->view->set('module', (object)($module[0] ?? []));
        $this->view->set('vars', (object)$vars);
        return $this->view->fetch();
    }

    public function manageEditRow($module_row, array &$vars)
    {
        $this->view = new View('edit_row', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'logicboxes_email' . DS);
        Loader::loadHelpers($this, ['Form', 'Html', 'Javascript', 'Widget']);
        if (empty($vars)) {
            $vars = (array)$module_row->meta;
        } elseif (empty($vars['sandbox'])) {
            $vars['sandbox'] = 'false';
        }
        Loader::loadModels($this, ['ModuleManager']);
        $module = $this->ModuleManager->getByClass('logicboxes_email', Configure::get('Blesta.company_id'));
        $this->view->set('module', (object)($module[0] ?? []));
        $this->view->set('vars', (object)$vars);
        return $this->view->fetch();
    }

    public function getName()
    {
        return Language::_('LogicboxesEmail.name', true);
    }

    public function getVersion()
    {
        return '0.5.11';
    }

    public function getAuthors()
    {
        return [['name' => 'KanumHost']];
    }

    /** Register automatic OrderBox reconciliation on install and upgrade. */
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

    /**
     * Remove this module's scheduled task only when Blesta removes the last
     * instance of the module.
     */
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

    /** Called by Blesta automation. Only services explicitly marked for reconciliation contact OrderBox. */
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

                // Process deferred checkout changes only when an invoice linked to this
                // service is closed (Blesta's paid/closed invoice state), never while open.
                if (in_array(strtolower(trim((string)($fields->email_pending_change ?? '0'))), ['1', 'true', 'yes'], true)) {
                    $paidForService = false;
                    try {
                        $clientId = (int)($fullService->client_id ?? 0);
                        // Blesta provides a service-specific invoice query. Use it
                        // instead of scanning a client's generic invoice list, which
                        // can return no records for this service in some versions.
                        if (method_exists($this->Invoices, 'getAllWithService')) {
                            $relatedInvoices = $this->Invoices->getAllWithService($serviceId, $clientId ?: null, 'closed');
                        } else {
                            $relatedInvoices = $this->Invoices->getAll($clientId ?: null, 'closed');
                        }
                        if (is_object($relatedInvoices)) {
                            foreach (['invoices', 'results', 'records'] as $property) {
                                if (isset($relatedInvoices->{$property}) && is_array($relatedInvoices->{$property})) {
                                    $relatedInvoices = $relatedInvoices->{$property};
                                    break;
                                }
                            }
                        }
                        $invoiceCount = is_array($relatedInvoices) ? count($relatedInvoices) : 0;
                        foreach ((array)$relatedInvoices as $invoice) {
                            if (!is_object($invoice)) $invoice = (object)$invoice;
                            $status = strtolower(trim((string)($invoice->status ?? $invoice->status_name ?? 'closed')));
                            if (!in_array($status, ['closed', 'paid'], true)) continue;
                            $invoiceId = (int)($invoice->id ?? $invoice->id_code ?? $invoice->invoice_id ?? 0);
                            if ($invoiceId > 0) {
                                $paidForService = true;
                                $this->logEmailDiagnostic('Found closed invoice ' . $invoiceId . ' for deferred service ' . $serviceId . ' (status=' . $status . ').');
                                break;
                            }
                        }
                        $this->logEmailDiagnostic('Deferred service ' . $serviceId . ': service-specific invoice query returned ' . $invoiceCount . ' invoice record(s); paid match=' . ($paidForService ? 'yes' : 'no') . '.');
                    } catch (Throwable $e) {
                        $this->logEmailDiagnostic('Could not verify paid invoice for deferred service ' . $serviceId . ': ' . $e->getMessage());
                    }
                    if ($paidForService) {
                        try {
                            // Services::get() may expose the package nested on the
                            // service instead of a top-level package_id.
                            $packageId = (int)($fullService->package_id ?? $fullService->package->id ?? 0);
                            $package = $packageId > 0 ? $this->Packages->get($packageId) : null;
                            if (!$package && isset($fullService->package) && is_object($fullService->package)) {
                                $package = $fullService->package;
                            }
                            if (!$package) throw new RuntimeException('Could not load package for deferred service ' . $serviceId . '; package_id=' . $packageId . '.');
                            $resultFields = $this->editService($package, $fullService, [
                                'mailboxes' => (int)($fields->email_pending_accounts ?? $fields->email_accounts ?? 0),
                                'extra storage' => (int)($fields->email_pending_storage_blocks ?? $fields->email_storage_blocks ?? 0),
                                'use_module' => 'true'
                            ]);
                            if (is_array($resultFields)) {
                                $saved = true;
                                foreach ($resultFields as $field) {
                                    if (!isset($field['key'])) continue;
                                    $r = $this->Services->editField($serviceId, [
                                        'key' => $field['key'], 'value' => (string)($field['value'] ?? ''),
                                        'encrypted' => !empty($field['encrypted']) ? 'true' : 'false'
                                    ]);
                                    if ($r === false) $saved = false;
                                }
                                if ($saved) {
                                    $this->logEmailDiagnostic('Applied deferred paid upgrade for service ' . $serviceId . '.');
                                    $fields = $this->serviceFieldsToObject($this->Services->get($serviceId)->fields ?? []);
                                } else {
                                    $this->logEmailDiagnostic('OrderBox change was attempted for service ' . $serviceId . ', but saving service fields failed.');
                                    continue;
                                }
                            } else {
                                $this->logEmailDiagnostic('Deferred upgrade for service ' . $serviceId . ' was not applied; editService returned no fields.');
                                continue;
                            }
                        } catch (Throwable $e) {
                            $this->logEmailDiagnostic('Deferred paid upgrade failed for service ' . $serviceId . ': ' . $e->getMessage());
                            continue;
                        }
                    } else {
                        // Leave the request pending until a closed invoice exists.
                        continue;
                    }
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

    public function getModuleRowFields($vars = null)
    {
        $vars = (array)$vars;
        $fields = [];
        $fields[] = $this->Form->label(Language::_('LogicboxesEmail.account_name', true), 'account_name');
        $fields[] = $this->Form->fieldText('account_name', $vars['account_name'] ?? '', ['id' => 'account_name']);
        $fields[] = $this->Form->label(Language::_('LogicboxesEmail.reseller_id', true), 'reseller_id');
        $fields[] = $this->Form->fieldText('reseller_id', $vars['reseller_id'] ?? '', ['id' => 'reseller_id']);
        $fields[] = $this->Form->label(Language::_('LogicboxesEmail.key', true), 'key');
        $fields[] = $this->Form->fieldPassword('key', $vars['key'] ?? '', ['id' => 'key', 'value' => $vars['key'] ?? '']);
        $fields[] = $this->Form->label(Language::_('LogicboxesEmail.sandbox', true), 'sandbox');
        $fields[] = $this->Form->fieldCheckbox('sandbox', 'true', isset($vars['sandbox']) && $vars['sandbox'] === 'true', ['id' => 'sandbox']);
        return $fields;
    }

    public function addModuleRow(array &$vars)
    {
        $this->Input->setRules($this->getRowRules());
        if ($this->Input->validates($vars)) {
            // Re-enable/recreate the synchronization task whenever the module
            // account settings are saved. This repairs installations where the
            // task registration failed during install/upgrade.
            $this->registerSyncCronTask();
            return [
                ['key' => 'account_name', 'value' => trim($vars['account_name']), 'encrypted' => 0],
                ['key' => 'reseller_id', 'value' => trim($vars['reseller_id']), 'encrypted' => 0],
                ['key' => 'key', 'value' => $vars['key'], 'encrypted' => 1],
                ['key' => 'sandbox', 'value' => !empty($vars['sandbox']) ? 'true' : 'false', 'encrypted' => 0]
            ];
        }
        return;
    }

    public function editModuleRow($module_row, array &$vars)
    {
        // An empty password field means "keep the current API key".
        if (empty($vars['key'])) {
            $vars['key'] = $module_row->meta->key ?? '';
        }
        return $this->addModuleRow($vars);
    }

    public function getModuleRowFieldsForEdit($vars)
    {
        return $this->getModuleRowFields($vars);
    }

    public function getPackageFields($vars = null)
    {
        // Blesta expects ModuleFields here; $this->Form is not loaded in the
        // package editor and package settings are stored under meta[...].
        $fields = new ModuleFields();
        $vars = is_object($vars) ? $vars : (object)(array)$vars;
        $meta = isset($vars->meta) ? (array)$vars->meta : (array)$vars;

        $product = $fields->label(Language::_('LogicboxesEmail.product', true), 'logicboxes_email_product');
        $product->attach($fields->fieldSelect(
            'meta[email_product]',
            [
                'business_email' => Language::_('LogicboxesEmail.business_email', true),
                'enterprise_email' => Language::_('LogicboxesEmail.enterprise_email', true)
            ],
            $meta['email_product'] ?? 'business_email',
            ['id' => 'logicboxes_email_product']
        ));
        $fields->setField($product);



        return $fields;
    }

    /** Client service tabs for interactive email management. */
    public function getClientServiceTabs($service)
    {
        return [
            'tabEmailAccounts' => ['name' => 'Email accounts'],
            'tabDnsSetup' => ['name' => 'DNS configuration']
        ];
    }

    /**
     * Synchronize contracted mailbox and storage quantities from OrderBox into
     * Blesta service fields and configurable options. Unrelated options are preserved.
     * Uses Services::edit() with use_module=false: Blesta still persists the
     * configurable options, while editService() is prevented from mutating OrderBox.
     */
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


    /** Admin gets the same read/manage views when opening a service. */
    public function getAdminServiceTabs($service)
    {
        return [
            'tabEmailAccounts' => 'Email accounts',
            'tabDnsSetup' => 'DNS configuration',
            'tabImportOrder' => 'Import OrderBox order'
        ];
    }

    /**
     * Link an already-existing OrderBox order to a Blesta service created manually.
     * This operation is read-only at OrderBox: it never purchases or changes licenses.
     */
    public function tabImportOrder($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $post = (array)$post;
        $serviceId = (int)($service->id ?? 0);
        $fields = $this->serviceFieldsToObject($service->fields ?? []);
        $rowId = $service->module_row_id ?? ($package->module_row ?? null);
        $row = $this->getModuleRow($rowId);
        $message = null;
        $error = null;
        $orderIdInput = trim((string)($post['order_id'] ?? ($fields->email_order_id ?? '')));
        $productInput = (string)($post['email_product'] ?? ($fields->email_product ?? ($package->meta->email_product ?? 'business_email')));
        if (!in_array($productInput, ['business_email', 'enterprise_email'], true)) {
            $productInput = 'business_email';
        }

        if (!$row || empty($row->meta->reseller_id) || empty($row->meta->key)) {
            $error = 'A conta/API LogicBoxes não está configurada para este serviço.';
        } elseif (!empty($post['import_existing_order'])) {
            if ($serviceId < 1 || $orderIdInput === '' || !ctype_digit($orderIdInput)) {
                $error = 'Indica um ID de encomenda OrderBox válido.';
            } else {
                try {
                    $api = $this->getLogicboxesApi($row->meta->reseller_id, $row->meta->key, ($row->meta->sandbox ?? 'false') === 'true');
                    $prefix = $productInput === 'enterprise_email' ? 'enterpriseemail/us' : 'eelite/us';
                    $response = $api->submit($prefix . '/details', ['order-id' => $orderIdInput], 'GET');
                    $this->logApiResponse($api, $response);
                    if (!$this->isSuccessfulResponse($response)) {
                        $error = 'A OrderBox não confirmou essa encomenda: ' . $this->responseError($response);
                    } else {
                        $details = $response->response();
                        if (is_object($details) && isset($details->response)) $details = $details->response;
                        if (is_array($details) && isset($details['response'])) $details = $details['response'];
                        if (is_object($details)) $details = (array)$details;
                        if (!is_array($details) || !$details) {
                            $error = 'A OrderBox devolveu uma resposta vazia; o serviço não foi alterado.';
                        } else {
                            $getVal = static function ($data, array $keys) {
                                foreach ($keys as $key) {
                                    if (is_array($data) && isset($data[$key]) && is_scalar($data[$key])) return trim((string)$data[$key]);
                                    if (is_object($data) && isset($data->{$key}) && is_scalar($data->{$key})) return trim((string)$data->{$key});
                                }
                                return '';
                            };
                            $domain = strtolower($getVal($details, ['domainname', 'domain-name', 'domain_name', 'domain']));
                            $providerProductKey = strtolower($getVal($details, ['productkey', 'product-key', 'product_key']));
                            $accountsRaw = $getVal($details, ['emailaccounts', 'email-accounts', 'email_accounts', 'numberofemailaccounts', 'number-of-email-accounts']);
                            $blocksRaw = $this->extractStorageBlocksFromOrderDetails($details);
                            if (!is_numeric($blocksRaw)) {
                                $this->logEmailDiagnostic('OrderBox details did not expose email_storage_5.quantity for import; order=' . $orderIdInput . '; top-level keys=' . implode(',', array_keys($details)) . '; details=' . json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                            }
                            $productMismatch = ($productInput === 'enterprise_email' && $providerProductKey !== '' && strpos($providerProductKey, 'enterprise') === false)
                                || ($productInput === 'business_email' && $providerProductKey !== '' && strpos($providerProductKey, 'enterprise') !== false);
                            if ($productMismatch) {
                                $error = 'O produto selecionado não corresponde ao produto devolvido pela OrderBox (' . $providerProductKey . '). Escolhe o produto correto; o serviço não foi alterado.';
                            } elseif ($domain === '') {
                                $error = 'A resposta da OrderBox não contém o domínio da encomenda. O serviço não foi alterado.';
                            } elseif (!is_numeric($accountsRaw) || (int)$accountsRaw < 1) {
                                $error = 'A resposta da OrderBox não contém a quantidade contratada de mailboxes. O serviço não foi alterado.';
                            } elseif (!is_numeric($blocksRaw) || (int)$blocksRaw < 0) {
                                $error = 'A resposta da OrderBox não contém a quantidade de armazenamento. O serviço não foi alterado.';
                            } else {
                                Loader::loadModels($this, ['Services']);
                                $save = [
                                    'email_order_id' => $orderIdInput,
                                    'email_product' => $productInput,
                                    'email_domain' => $domain,
                                    'email_accounts' => (string)(int)$accountsRaw,
                                    'email_storage_blocks' => (string)(int)$blocksRaw
                                ];
                                // Keep existing credentials/customer fields; fill username from the linked Blesta client if absent.
                                $customerUsername = trim((string)($fields->email_customer_username ?? ''));
                                if ($customerUsername === '' && !empty($service->client_id)) {
                                    Loader::loadModels($this, ['Clients']);
                                    $linkedClient = $this->Clients->get((int)$service->client_id);
                                    if ($linkedClient && !empty($linkedClient->email)) $customerUsername = trim((string)$linkedClient->email);
                                }
                                if ($customerUsername !== '') $save['email_customer_username'] = $customerUsername;
                                $allSaved = true;
                                foreach ($save as $key => $value) {
                                    $result = $this->Services->editField($serviceId, [
                                        'key' => $key,
                                        'value' => $value,
                                        'encrypted' => ($key === 'email_customer_password') ? 'true' : 'false'
                                    ]);
                                    if ($result === false) $allSaved = false;
                                }
                                if (!$allSaved) {
                                    $error = 'A OrderBox foi validada, mas o Blesta não confirmou a gravação de todos os campos. Verifica os campos do serviço antes de repetir.';
                                    $this->logEmailDiagnostic('Import order ' . $orderIdInput . ' into Blesta service ' . $serviceId . ' had one or more editField failures.');
                                } else {
                                    // Defer reconciliation to the scheduled task; do not issue a second
                                    // OrderBox request in the same admin page request.
                                    $this->Services->editField($serviceId, [
                                        'key' => 'email_sync_pending', 'value' => '1', 'encrypted' => 'false'
                                    ]);
                                    $message = 'Encomenda OrderBox ' . $orderIdInput . ' associada ao serviço Blesta ' . $serviceId . '. Não foi criada nenhuma encomenda nova nem alterada qualquer licença.';
                                    $this->logEmailDiagnostic('Imported existing OrderBox order ' . $orderIdInput . ' into Blesta service ' . $serviceId . ' (product=' . $productInput . ', accounts=' . (int)$accountsRaw . ', storage_blocks=' . (int)$blocksRaw . ').');
                                }
                            }
                        }
                    }
                } catch (Throwable $e) {
                    $this->logEmailDiagnostic('Import existing OrderBox order failed for Blesta service ' . $serviceId . ': ' . $e->getMessage());
                    $error = 'Falha ao consultar/importar a encomenda OrderBox. Consulta o log do módulo para ver o erro.';
                }
            }
        }

        $e = static function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $csrf = '';
        try { Loader::loadHelpers($this, ['Form']); $csrf = $this->Form->getCsrfToken(); } catch (Throwable $ignore) {}
        $html = '<section class="logicboxes-email-import"><h3>Importar encomenda existente da OrderBox</h3>';
        $html .= '<p>Associa uma encomenda que já existe na tua conta OrderBox a este serviço Blesta. Esta ação apenas consulta a OrderBox; não compra nem altera licenças.</p>';
        if ($message) $html .= '<div class="alert alert-success">' . $e($message) . '</div>';
        if ($error) $html .= '<div class="alert alert-danger">' . $e($error) . '</div>';
        $html .= '<form method="post">';
        $html .= '<input type="hidden" name="_csrf_token" value="' . $e($csrf) . '">';
        $html .= '<div class="form-group"><label for="logicboxes_email_order_id">ID da encomenda OrderBox</label><input class="form-control" id="logicboxes_email_order_id" name="order_id" value="' . $e($orderIdInput) . '" required inputmode="numeric"></div>';
        $html .= '<div class="form-group"><label for="logicboxes_email_product">Produto</label><select class="form-control" id="logicboxes_email_product" name="email_product">';
        $html .= '<option value="business_email"' . ($productInput === 'business_email' ? ' selected' : '') . '>Business Email</option>';
        $html .= '<option value="enterprise_email"' . ($productInput === 'enterprise_email' ? ' selected' : '') . '>Enterprise Email</option></select></div>';
        $html .= '<button class="btn btn-primary" type="submit" name="import_existing_order" value="1">Validar e associar encomenda</button></form></section>';
        return $html;
    }

    public function tabEmailAccounts($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $post = (array)$post;
        $fields = $this->serviceFieldsToObject($service->fields ?? []);
        $row = $this->getModuleRow($service->module_row_id ?? null);
        $orderId = (string)($fields->email_order_id ?? '');
        $domain = $this->getEffectiveEmailDomain((string)($fields->email_domain ?? ''), $row);
        $product = (string)($fields->email_product ?? 'business_email');
        $maxMailboxes = max(1, (int)($fields->email_accounts ?? 1));
        $users = [];
        $message = null;
        $error = null;
        $api = null;
        $panelLaunchToken = null;
        $panelLaunchUrl = null;
        $purchasedLicenses = null;
        $usedLicenses = 0;
        $unusedLicenses = 0;

        if (!$row || empty($row->meta->reseller_id) || empty($row->meta->key) || $orderId === '') {
            $error = 'The service is missing its LogicBoxes account or order ID.';
        } else {
            try {
                $api = $this->getLogicboxesApi($row->meta->reseller_id, $row->meta->key, ($row->meta->sandbox ?? 'false') === 'true');
                if (!empty($post['email_action'])) {
                    $action = (string)$post['email_action'];
                    if ($action === 'delete_licenses') {
                        $deleteCount = filter_var($post['accounts_to_delete'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                        $prefix = $product === 'enterprise_email' ? 'enterpriseemail/us' : 'eelite/us';
                        if ($deleteCount === false) {
                            $error = 'Indica um número válido de contas a eliminar.';
                        } else {
                            // Re-check purchased licences and active mailboxes immediately before deletion.
                            $detailsResponse = $api->submit($prefix . '/details', ['order-id' => $orderId], 'GET');
                            $usageResponse = $api->submit('mail/users/search', ['order-id' => $orderId, 'page-no' => 1], 'GET');
                            if (!$this->isSuccessfulResponse($detailsResponse) || !$this->isSuccessfulResponse($usageResponse)) {
                                $error = 'Não foi possível confirmar as licenças e a utilização atuais. Nenhuma licença foi eliminada.';
                            } else {
                                $details = $detailsResponse->response();
                                if (is_object($details) && isset($details->response)) $details = $details->response;
                                elseif (is_array($details) && isset($details['response'])) $details = $details['response'];
                                $purchasedRaw = $this->firstApiValue($details, ['emailaccounts', 'email-accounts', 'email_accounts', 'numberofemailaccounts', 'number-of-email-accounts']);
                                $currentUsers = $this->collectEmailUsers($usageResponse->response());
                                $usedMailboxes = 0;
                                foreach ($currentUsers as $currentUser) {
                                    $accountType = strtolower($this->firstApiValue($currentUser, ['user.accounttype', 'user.account-type', 'user.account_type', 'accountType', 'accounttype', 'account-type', 'account_type']));
                                    if (strpos($accountType, 'forward') === false) $usedMailboxes++;
                                }
                                if ($purchasedRaw === '' || !is_numeric($purchasedRaw)) {
                                    $error = 'A OrderBox não devolveu o número de licenças contratadas. Nenhuma licença foi eliminada.';
                                } else {
                                    $purchasedNow = (int)$purchasedRaw;
                                    $unused = max(0, $purchasedNow - $usedMailboxes);
                                    if ($deleteCount > $unused) {
                                        $error = 'Só podes eliminar ' . $unused . ' licença(s) não utilizadas. Existem ' . $usedMailboxes . ' mailbox(es) em utilização.';
                                    } else {
                                        $deleteResponse = $api->submit($prefix . '/delete-email-account', [
                                            'order-id' => $orderId,
                                            'no-of-accounts' => $deleteCount
                                        ], 'POST');
                                        $this->logApiResponse($api, $deleteResponse);
                                        if ($this->isSuccessfulResponse($deleteResponse)) {
                                            $deleteData = $deleteResponse->response();
                                            $actionId = $this->firstApiValue($deleteData, ['eaqid', 'actionid', 'action-id']);
                                            $message = 'Pedido de eliminação de ' . $deleteCount . ' licença(s) enviado à OrderBox. A operação pode ficar pendente; não repitas o pedido enquanto estiver pendente.';
                                            if ($actionId !== '') $message .= ' Referência: ' . $actionId . '.';
                                        } else {
                                            $error = 'A OrderBox recusou a eliminação: ' . $this->responseError($deleteResponse);
                                        }
                                    }
                                }
                            }
                        }
                    } elseif ($action === 'manage_panel') {
                        $panelTarget = (($post['panel_target'] ?? '') === 'dns') ? 'dns' : 'email';
                        $customerId = trim((string)($fields->email_customer_id ?? ''));
                        $customerUsername = trim((string)($fields->email_customer_username ?? ''));
                        $customerPassword = trim((string)($fields->email_customer_password ?? ''));
                        $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');

                        // Legacy services may predate storage of the LogicBoxes customer fields.
                        // Recover the username from the linked Blesta client and look up the existing
                        // OrderBox customer. Never create a second customer from the management tab.
                        if ($customerUsername === '' && !empty($service->client_id)) {
                            Loader::loadModels($this, ['Clients']);
                            $linkedClient = $this->Clients->get($service->client_id);
                            if ($linkedClient && !empty($linkedClient->email)) {
                                $customerUsername = trim((string)$linkedClient->email);
                            }
                        }
                        // Always resolve the canonical OrderBox username. A legacy service may
                        // have a customer ID but a missing/stale username; the API's username is
                        // the credential identifier required by generate-token.
                        $customerSearch = $api->submit('customers/search', [
                            'username' => $customerUsername,
                            'no-of-records' => 10,
                            'page-no' => 1
                        ], 'GET');
                        $this->logApiResponse($api, $customerSearch);
                        if ($this->isSuccessfulResponse($customerSearch)) {
                            foreach ((array)$customerSearch->response() as $customerRecord) {
                                $record = (array)$customerRecord;
                                $recordUsername = trim((string)($record['customer.username'] ?? $record['username'] ?? ''));
                                $recordId = trim((string)($record['customer.customerid'] ?? $record['customerid'] ?? $record['customer-id'] ?? $record['entityid'] ?? ''));
                                // Prefer the exact existing customer ID when one is already linked;
                                // otherwise match the linked client's email to avoid choosing another account.
                                $sameId = $customerId !== '' && $recordId !== '' && $recordId === $customerId;
                                $sameUsername = $recordUsername !== '' && $customerUsername !== '' && strcasecmp($recordUsername, $customerUsername) === 0;
                                if ($sameId || ($customerId === '' && $sameUsername)) {
                                    if ($recordId !== '') $customerId = $recordId;
                                    if ($recordUsername !== '') $customerUsername = $recordUsername;
                                    break;
                                }
                            }
                        }

                        // If a stored customer ID was present but search-by-username did not
                        // return it, ask OrderBox for that customer's authoritative details.
                        if ($customerId !== '') {
                            $customerDetails = $api->submit('customers/details', ['customer-id' => $customerId], 'GET');
                            $this->logApiResponse($api, $customerDetails);
                            if ($this->isSuccessfulResponse($customerDetails)) {
                                $details = $customerDetails->response();
                                $canonicalUsername = trim((string)$this->firstApiValue($details, ['username', 'customer.username', 'customer-username', 'customer_username']));
                                if ($canonicalUsername !== '') $customerUsername = $canonicalUsername;
                            }
                        }

                        if ($customerId === '' || $customerUsername === '') {
                            $error = 'Could not recover the LogicBoxes customer ID or username for this existing service. Check the linked Blesta client email and the customers/search API response in the module log.';
                        } elseif (!filter_var($clientIp, FILTER_VALIDATE_IP)) {
                            $error = 'Could not determine the client IP address required by the provider to generate a temporary SSO token.';
                        } else {
                            // Documented password-less route (ResellerClub API: Generating a Login Token).
                            // Needs only the customer ID and the customer's IP; no password involved.
                            $tokenResponse = $api->submit('customers/generate-login-token', [
                                'customer-id' => $customerId,
                                'ip' => $clientIp
                            ], 'GET');
                            // Do not log a successful response: it contains a short-lived token.
                            $tokenData = $tokenResponse->response();
                            $token = '';
                            if (is_string($tokenData) || is_numeric($tokenData)) {
                                $token = trim((string)$tokenData);
                            } elseif (is_object($tokenData) || is_array($tokenData)) {
                                $tokenData = (array)$tokenData;
                                foreach (['auth-token', 'auth_token', 'token'] as $tokenKey) {
                                    if (isset($tokenData[$tokenKey]) && is_scalar($tokenData[$tokenKey])) {
                                        $token = trim((string)$tokenData[$tokenKey]);
                                        if ($token !== '') break;
                                    }
                                }
                            }
                            if (!$this->isSuccessfulResponse($tokenResponse) || $token === '') {
                                $this->logApiResponse($api, $tokenResponse);
                                $error = 'LogicBoxes could not generate the login token: ' . $this->responseError($tokenResponse);
                            } else {
                                $resellerResponse = $api->submit('resellers/details', [], 'GET');
                                $resellerData = $resellerResponse->response();
                                $brandingUrl = '';
                                if ($this->isSuccessfulResponse($resellerResponse)) {
                                    $brandingUrl = trim((string)$this->firstApiValue($resellerData, ['brandingurl', 'branding-url', 'branding_url']));
                                }
                                $brandingUrl = preg_replace('~^https?://~i', '', $brandingUrl);
                                $brandingUrl = rtrim($brandingUrl, '/');
                                if ($brandingUrl === '' || preg_match('/[^a-zA-Z0-9.\-]/', $brandingUrl)) {
                                    $error = 'Could not resolve the branded OrderBox URL. Check the resellers/details API response in the Blesta module log.';
                                } else {
                                    if ($panelTarget === 'dns') {
                                        // Documented service-name for the DNS service (ResellerClub KB: Managing DNS,
                                        // Email, Hosting... through your HTTP API Integration).
                                        $panelLaunchUrl = 'https://' . $brandingUrl . '/servlet/ManageServiceServletForAPI?' . http_build_query([
                                            'auth-token' => $token,
                                            'orderid' => $orderId,
                                            'service-name' => 'dns'
                                        ], '', '&', PHP_QUERY_RFC3986);
                                    } else {
                                        // The OrderBox "Manage Email Account(s)" action uses these product IDs:
                                        // Business Email: servicename/productkey = eeliteus.
                                        // Enterprise Email: servicename/productkey = enterpriseemailus.
                                        // ManageServiceServletForAPI is the token-based API variant of that action.
                                        $emailServiceName = ($product === 'enterprise_email') ? 'enterpriseemailus' : 'eeliteus';
                                        $panelLaunchUrl = 'https://' . $brandingUrl . '/servlet/ManageServiceServletForAPI?' . http_build_query([
                                            'auth-token' => $token,
                                            'orderid' => $orderId,
                                            'service-name' => $emailServiceName
                                        ], '', '&', PHP_QUERY_RFC3986);
                                    }
                                    $message = 'Control-panel access generated. Open the panel now; the token expires in 120 seconds.';
                                }
                            }
                        }
                    } elseif ($action === 'add_mailbox') {
                        $local = strtolower(trim((string)($post['local_part'] ?? '')));
                        $email = $local . '@' . $domain;
                        $password = (string)($post['password'] ?? '');
                        $first = trim((string)($post['first_name'] ?? ''));
                        $last = trim((string)($post['last_name'] ?? ''));
                        $notify = trim((string)($post['notification_email'] ?? ''));
                        $country = strtoupper(trim((string)($post['country_code'] ?? 'PT')));
                        $language = trim((string)($post['language_code'] ?? 'en'));
                        if (!preg_match('/^[a-z0-9._%+\-]+$/i', $local) || $local === '') {
                            $error = 'Enter a valid mailbox name (the part before @).';
                        } elseif (strlen($password) < 8 || $first === '' || $last === '' || !filter_var($notify, FILTER_VALIDATE_EMAIL)) {
                            $error = 'Complete the name, a password of at least 8 characters and a valid notification email.';
                        } elseif (
                            (strlen($local) >= 2 && stripos($password, $local) !== false) ||
                            (strlen((string)strtok($domain, '.')) >= 2 && stripos($password, (string)strtok($domain, '.')) !== false)
                        ) {
                            $domainPrefix = (string)strtok($domain, '.');
                            $error = 'A password não pode conter o nome da mailbox (' . $local . ') nem o prefixo do domínio (' . $domainPrefix . '). Escolhe uma password diferente.';
                        } else {
                            $countResponse = $api->submit('mail/users/search', ['order-id' => $orderId, 'page-no' => 1], 'GET');
                            if (!$this->isSuccessfulResponse($countResponse)) {
                                $error = 'Could not verify current mailbox usage. No account was created. ' . $this->responseError($countResponse);
                            } else {
                                $current = $this->collectEmailUsers($countResponse->response());
                                $mailboxCount = 0;
                                foreach ($current as $u) {
                                    $type = strtolower($this->firstApiValue($u, ['user.accounttype','accounttype','account-type','account_type']));
                                    if ($type === '' || strpos($type, 'forward') === false) $mailboxCount++;
                                }
                                if ($mailboxCount >= $maxMailboxes) {
                                    $error = 'The service has reached its purchased mailbox limit (' . $maxMailboxes . ').';
                                } else {
                                    $r = $api->submit('mail/user/add', [
                                        'order-id' => $orderId, 'email' => $email, 'passwd' => $password,
                                        'notification-email' => $notify, 'first-name' => $first, 'last-name' => $last,
                                        'country-code' => $country, 'language-code' => $language
                                    ], 'POST');

                                    // Log the provider's actual response so mailbox creation can be diagnosed.
                                    $this->logApiResponse($api, $r);

                                    // Do not claim that the mailbox was created just because the API
                                    // returned HTTP/API status OK. Show the provider response so we can
                                    // identify pending actions or application-level errors.
                                    $apiResponse = $r->response();
                                    $apiResponseText = json_encode($apiResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                                    if ($apiResponseText === false || $apiResponseText === 'null') {
                                        $apiResponseText = substr((string)$r->raw(), 0, 1500);
                                    }
                                    $apiResponseText = preg_replace('/("(?:passwd|password|api-key|auth-token|auth_token)"\s*:\s*)"[^"]*"/i', '$1"[REDACTED]"', $apiResponseText);
                                    if ($this->isSuccessfulResponse($r)) {
                                        $message = 'Mailbox criada com sucesso: ' . $email;
                                    } else {
                                        $error = 'Não foi possível criar a mailbox: ' . $this->responseError($r);
                                    }
                                }
                            }
                        }
                    } elseif ($action === 'add_forward') {
                        $local = strtolower(trim((string)($post['forward_local_part'] ?? '')));
                        $targets = preg_split('/[\r\n,;]+/', (string)($post['forwards'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
                        $targets = array_values(array_unique(array_filter(array_map('trim', $targets), function ($v) { return filter_var($v, FILTER_VALIDATE_EMAIL); })));
                        if (!preg_match('/^[a-z0-9._%+\-]+$/i', $local) || $local === '' || !$targets) {
                            $error = 'Enter a valid forward address name and at least one valid destination email address.';
                        } else {
                            $r = $api->submit('mail/user/add-forward-only-account', [
                                'order-id' => $orderId, 'email' => $local . '@' . $domain, 'forwards' => implode(',', $targets)
                            ], 'POST');
                            if ($this->isSuccessfulResponse($r)) $message = 'Forward-only account created: ' . $local . '@' . $domain;
                            else $error = 'Forward-only account creation failed: ' . $this->responseError($r);
                        }
                    } elseif ($action === 'delete_user') {
                        $email = trim((string)($post['email'] ?? ''));
                        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || substr(strrchr($email, '@'), 1) !== $domain) {
                            $error = 'Invalid email address for this service.';
                        } else {
                            $r = $api->submit('mail/user/delete', ['order-id' => $orderId, 'email' => $email], 'POST');
                            if ($this->isSuccessfulResponse($r)) $message = 'Email account deleted: ' . $email;
                            else $error = 'Deletion failed: ' . $this->responseError($r);
                        }
                    }
                }
                $list = $api->submit('mail/users/search', ['order-id' => $orderId, 'page-no' => 1], 'GET');
				$this->logApiResponse($api, $list);
                if ($this->isSuccessfulResponse($list)) $users = $this->collectEmailUsers($list->response());
                else $error = $error ?: ('Could not retrieve email accounts: ' . $this->responseError($list));
                $prefix = $product === 'enterprise_email' ? 'enterpriseemail/us' : 'eelite/us';
                $licenseDetailsResponse = $api->submit($prefix . '/details', ['order-id' => $orderId], 'GET');
                $purchasedLicenses = null;
                if ($this->isSuccessfulResponse($licenseDetailsResponse)) {
                    $licenseDetails = $licenseDetailsResponse->response();
                    if (is_object($licenseDetails) && isset($licenseDetails->response)) $licenseDetails = $licenseDetails->response;
                    elseif (is_array($licenseDetails) && isset($licenseDetails['response'])) $licenseDetails = $licenseDetails['response'];
                    $purchasedRaw = $this->firstApiValue($licenseDetails, ['emailaccounts', 'email-accounts', 'email_accounts', 'numberofemailaccounts', 'number-of-email-accounts']);
                    if ($purchasedRaw !== '' && is_numeric($purchasedRaw)) $purchasedLicenses = (int)$purchasedRaw;
                }
                $usedLicenses = 0;
                foreach ($users as $licenseUser) {
                    $accountType = strtolower($this->firstApiValue($licenseUser, ['user.accounttype', 'user.account-type', 'user.account_type', 'accountType', 'accounttype', 'account-type', 'account_type']));
                    if (strpos($accountType, 'forward') === false) $usedLicenses++;
                }
                $unusedLicenses = $purchasedLicenses === null ? 0 : max(0, $purchasedLicenses - $usedLicenses);
            } catch (Throwable $e) {
                $this->logEmailDiagnostic('Client email tab exception for service ' . (int)($service->id ?? 0) . ': ' . $e->getMessage());
                $error = 'The email provider could not be reached. Please try again later.';
            }
        }

        $this->view = new View('client_email_accounts', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'logicboxes_email' . DS);
        Loader::loadHelpers($this, ['Form', 'Html']);
        $this->view->set('domain', $domain);
        $this->view->set('product', $product);
        $this->view->set('users', $users);
        $this->view->set('max_mailboxes', $maxMailboxes);
        $this->view->set('message', $message);
        $this->view->set('error', $error);
        $output = $this->view->fetch();

        // Generate a fresh provider token only after an explicit client action.
        // The credential is stored encrypted in the Blesta service fields for new customers.
        $escapePanel = static function ($value) {
            return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        if ($orderId !== '') {
            $csrfPanel = $this->Form->getCsrfToken();
            $output .= '<section class="logicboxes-email-control-panel">';
            $output .= '<h3>Email control panel</h3>';
            $output .= '<p>Manage mailboxes, storage allocation and email settings in the provider control panel.</p>';
            if ($panelLaunchUrl !== null) {
                // The public ResellerClub WHMCS module opens this exact servlet using
                // a GET URL; the service-name selects the mail hosting panel.
                // The form that requested the token opens in a new tab; send that tab straight to the panel.
                $output .= '<script>window.location.replace(' . json_encode($panelLaunchUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ');</script>';
                $output .= '<p>The secure panel token expires shortly. Continue now.</p>';
                $output .= '<a class="btn btn-primary logicboxes-email-panel-launch" href="' . $escapePanel($panelLaunchUrl) . '" target="_blank" rel="noopener noreferrer">Continue to email control panel</a>';
            } else {
                $panelButtons = ['email' => 'Open email control panel', 'dns' => 'Manage DNS'];
                foreach ($panelButtons as $panelTargetKey => $panelButtonLabel) {
                    $output .= '<form method="post" target="logicboxesEmailPanel" class="logicboxes-email-panel-request" style="display:inline-block;margin-right:8px;" onsubmit="window.open(\'\', \'logicboxesEmailPanel\', \'width=900,height=650,resizable=yes,scrollbars=yes\');">';
                    $output .= '<input type="hidden" name="_csrf_token" value="' . $escapePanel($csrfPanel) . '">';
                    $output .= '<input type="hidden" name="email_action" value="manage_panel">';
                    $output .= '<input type="hidden" name="panel_target" value="' . $panelTargetKey . '">';
                    $output .= '<button type="submit" class="btn btn-default">' . $escapePanel($panelButtonLabel) . '</button></form>';
                }
            }
            $output .= '</section>';

            // OrderBox-style purchased-license deletion dialog. This deletes unused
            // purchased licences, not individual mailbox users, and is non-refundable.
            $output .= '<section class="logicboxes-email-delete-licenses" style="margin-top:18px;">';
            $output .= '<button type="button" class="btn btn-default" onclick="document.getElementById(&quot;logicboxesDeleteLicensesModal&quot;).style.display=&quot;block&quot;">Delete Email Account(s)</button>';
            $output .= '<div id="logicboxesDeleteLicensesModal" role="dialog" aria-modal="true" aria-labelledby="logicboxesDeleteLicensesTitle" style="display:none;position:fixed;z-index:99999;inset:0;background:rgba(0,0,0,.55);padding:8vh 12px 12px;overflow:auto;">';
            $output .= '<div style="max-width:560px;margin:0 auto;background:#fff;color:#333;border:1px solid #aaa;border-radius:7px;box-shadow:0 8px 28px rgba(0,0,0,.35);overflow:hidden;">';
            $output .= '<div style="background:#f2f2f2;padding:12px 16px;border-bottom:1px solid #ddd;"><button type="button" aria-label="Fechar" onclick="document.getElementById(&quot;logicboxesDeleteLicensesModal&quot;).style.display=&quot;none&quot;" style="float:right;border:0;background:transparent;font-size:20px;line-height:1;">&times;</button><strong id="logicboxesDeleteLicensesTitle">Delete Email Account(s)</strong><div>' . $escapePanel($domain) . '</div></div>';
            $output .= '<form method="post" style="padding:16px;">';
            $output .= '<input type="hidden" name="_csrf_token" value="' . $escapePanel($csrfPanel) . '"><input type="hidden" name="email_action" value="delete_licenses">';
            $output .= '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px 18px;align-items:center;">';
            $output .= '<div><strong>Purchased Accounts</strong></div><div>' . ($purchasedLicenses === null ? 'Indisponível' : $escapePanel($purchasedLicenses)) . '</div>';
            $output .= '<div><strong>Currently Used</strong></div><div>' . $escapePanel($usedLicenses) . '</div>';
            $output .= '<label for="logicboxesAccountsToDelete"><strong>Accounts to be deleted</strong></label><div><input id="logicboxesAccountsToDelete" type="number" name="accounts_to_delete" min="1" max="' . $escapePanel($unusedLicenses) . '" required ' . ($unusedLicenses < 1 ? 'disabled' : '') . ' style="width:100%;" value="' . ($unusedLicenses > 0 ? '1' : '') . '"></div>';
            $output .= '</div><p style="margin-top:18px;color:#555;">Deleting email accounts will not result in a refund. Licenças em utilização não podem ser removidas aqui; elimina primeiro as mailboxes no painel de email.</p>';
            $output .= '<div style="text-align:right;margin-top:16px;"><button type="button" class="btn btn-default" onclick="document.getElementById(&quot;logicboxesDeleteLicensesModal&quot;).style.display=&quot;none&quot;">Cancelar</button> <button type="submit" class="btn btn-danger" ' . ($unusedLicenses < 1 || $purchasedLicenses === null ? 'disabled' : '') . ' onclick="return confirm(&quot;Tens a certeza de que queres eliminar licenças contratadas? Esta operação não tem reembolso.&quot;);">Delete Now</button></div>';
            $output .= '</form></div></div>';
            $output .= '<script>(function(){var m=document.getElementById("logicboxesDeleteLicensesModal");if(m){m.addEventListener("click",function(e){if(e.target===m)m.style.display="none";});document.addEventListener("keydown",function(e){if(e.key==="Escape")m.style.display="none";});}})();</script>';
            $output .= '</section>';
        }

        return $output;
    }

    public function tabDnsSetup($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $fields = $this->serviceFieldsToObject($service->fields ?? []);
        $row = $this->getModuleRow($service->module_row_id ?? null);
        $orderId = (string)($fields->email_order_id ?? '');
        $domain = $this->getEffectiveEmailDomain((string)($fields->email_domain ?? ''), $row);
        $product = (string)($fields->email_product ?? 'business_email');
        $records = [];
        $error = null;
        if (!$row || empty($row->meta->reseller_id) || empty($row->meta->key) || $orderId === '') {
            $error = 'The service is missing its LogicBoxes account or order ID.';
        } else {
            try {
                $api = $this->getLogicboxesApi($row->meta->reseller_id, $row->meta->key, ($row->meta->sandbox ?? 'false') === 'true');
                $endpoint = $product === 'enterprise_email' ? 'enterpriseemail/us/dns-record' : 'eelite/us/dns-record';
                $r = $api->submit($endpoint, ['order-id' => $orderId], 'GET');
                if ($this->isSuccessfulResponse($r)) {
                    $data = $r->response();
                    if (is_object($data)) $data = (array)$data;
                    if (is_array($data)) {
                        foreach ($data as $item) {
                            if (is_object($item)) $item = (array)$item;
                            if (is_array($item)) $records[] = $item;
                        }
                    }
                } else $error = 'Could not retrieve DNS records: ' . $this->responseError($r);
            } catch (Throwable $e) {
                $this->logEmailDiagnostic('DNS tab exception for service ' . (int)($service->id ?? 0) . ': ' . $e->getMessage());
                $error = 'The email provider could not be reached. Please try again later.';
            }
        }
        $this->view = new View('client_dns_setup', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'logicboxes_email' . DS);
        Loader::loadHelpers($this, ['Html']);
        $this->view->set('domain', $domain);
        $this->view->set('records', $records);
        $this->view->set('error', $error);
        return $this->view->fetch();
    }

    /**
     * Show live mailbox information in the client's service management page.
     * This is read-only: it never creates or changes a LogicBoxes order.
     */
    public function getClientManagementContent($package, $service)
    {
        $fields = $this->serviceFieldsToObject($service->fields ?? []);
        $orderId = (string)($fields->email_order_id ?? '');
        $product = (string)($fields->email_product ?? 'business_email');
        $rowId = $service->module_row_id ?? null;
        $row = $this->getModuleRow($rowId);
        $domain = $this->getEffectiveEmailDomain((string)($fields->email_domain ?? ''), $row);

        $e = static function ($value) {
            return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };

        $html = '<section class="logicboxes-email-management">';
        $html .= '<h3>Email Hosting</h3>';
        $html .= '<div class="table-responsive"><table class="table table-striped">';
        $html .= '<tbody>';
        $html .= '<tr><th>Domínio</th><td>' . $e($domain ?: '—') . '</td></tr>';
        $html .= '</tbody></table></div>';

        if (!$orderId || !$row || empty($row->meta->reseller_id) || empty($row->meta->key)) {
            $html .= '<div class="alert alert-warning">Mailbox information is not available because the service or LogicBoxes account configuration is incomplete.</div>';
            return $html . '</section>';
        }

        try {
            $api = $this->getLogicboxesApi(
                $row->meta->reseller_id,
                $row->meta->key,
                ($row->meta->sandbox ?? 'false') === 'true'
            );
            // ResellerClub/OrderBox documents this endpoint for Email Hosting and Business/Enterprise Email orders.
            $response = $api->submit('mail/users/search', [
                'order-id' => $orderId,
                'page-no' => 1
            ], 'GET');
            $this->logApiResponse($api, $response);

            if (!$this->isSuccessfulResponse($response)) {
                $this->logEmailDiagnostic('Mailbox list failed for service ' . (int)($service->id ?? 0) . ', order ' . $orderId . ': ' . $this->responseError($response));
                $html .= '<div class="alert alert-warning">We could not retrieve the mailbox list from the email provider. Please try again later or contact support.</div>';
                return $html . '</section>';
            }

            $users = $this->collectEmailUsers($response->response());

            // Order details are the authoritative source for the number of purchased
            // licenses. The user search only reports mailboxes that have actually been created.
            $prefix = $product === 'enterprise_email' ? 'enterpriseemail/us' : 'eelite/us';
            $orderDetails = $api->submit($prefix . '/details', ['order-id' => $orderId], 'GET');
            $this->logApiResponse($api, $orderDetails);
            $purchasedAccounts = (int)($fields->email_accounts ?? 0);
            if ($this->isSuccessfulResponse($orderDetails)) {
                $details = $orderDetails->response();
                // OrderBox may wrap the order fields inside a nested `response` object.
                // Unwrap it before reading emailaccounts, otherwise Blesta falls back
                // to the stale email_accounts service field (e.g. 2 instead of 5).
                if (is_object($details) && isset($details->response) && (is_object($details->response) || is_array($details->response))) {
                    $details = $details->response;
                } elseif (is_array($details) && isset($details['response']) && (is_object($details['response']) || is_array($details['response']))) {
                    $details = $details['response'];
                }
                $apiAccountCount = $this->firstApiValue($details, ['emailaccounts', 'email-accounts', 'email_accounts', 'numberofemailaccounts', 'number-of-email-accounts']);
                if ($apiAccountCount !== '' && is_numeric($apiAccountCount)) {
                    $purchasedAccounts = (int)$apiAccountCount;
                } else {
                    $this->logEmailDiagnostic('Order details did not contain a readable emailaccounts value for order ' . $orderId . '; falling back to email_accounts service field.');
                }
            }
            if ($purchasedAccounts < count($users)) {
                // Avoid showing a negative available count if legacy service fields or
                // an incomplete provider response temporarily disagree.
                $purchasedAccounts = count($users);
            }

            $mailboxCount = 0;
            $forwarderCount = 0;
            foreach ($users as $user) {
                $type = strtolower($this->firstApiValue($user, [
                    'user.accounttype', 'user.account-type', 'user.account_type',
                    'accountType', 'accounttype', 'account-type', 'account_type'
                ]));
                if (strpos($type, 'forward') !== false) {
                    $forwarderCount++;
                } else {
                    $mailboxCount++;
                }
            }
            $accountsAvailable = max(0, $purchasedAccounts - $mailboxCount);
            $storageBlocks = max(0, (int)($fields->email_storage_blocks ?? 0));

            $html .= '<h4>Account &amp; Storage Capacity</h4>';
            $html .= '<div class="table-responsive"><table class="table table-striped"><tbody>';
            $html .= '<tr><th>Total Email Accounts</th><td>' . $e($purchasedAccounts) . '</td></tr>';
            $html .= '<tr><th>Email Accounts Created</th><td>' . $e($mailboxCount) . '</td></tr>';
            $html .= '<tr><th>Email Accounts Available</th><td>' . $e($accountsAvailable) . '</td></tr>';
            $html .= '<tr><th>Email Forwarders Created</th><td>' . $e($forwarderCount) . '</td></tr>';
            $html .= '<tr><th>Storage Blocks Total (5 GB)</th><td>' . $e($storageBlocks) . '</td></tr>';
            $html .= '<tr><th>Storage Blocks Available</th><td>See allocation per mailbox in OrderBox</td></tr>';
            $html .= '</tbody></table></div>';

            $html .= '<h4>Email Accounts</h4>';
            if (!$users) {
                $html .= '<p>No email accounts were returned by the provider for this order.</p>';
            } else {
                $html .= '<p>' . count($users) . ' account(s) returned (maximum 200 per page).</p>';
                $html .= '<div class="table-responsive"><table class="table table-striped">';
                $html .= '<thead><tr><th>Email address</th><th>Status</th><th>Account type</th><th>Usage / Quota</th></tr></thead><tbody>';
                foreach ($users as $user) {
                    $email = $this->getEmailAddressFromUser($user);
                    $status = $this->firstApiValue($user, ['user.status', 'status']);
                    // Support camelCase API fields as well as dotted/hyphenated variants.
                    $type = $this->firstApiValue($user, [
                        'user.accounttype', 'user.account-type', 'user.account_type',
                        'accountType', 'accounttype', 'account-type', 'account_type'
                    ]);
                    $used = $this->firstApiValue($user, [
                        'user.quotaUsed', 'user.quotaused', 'user.usedquota',
                        'quotaUsed', 'quotaused', 'usedquota', 'used-quota', 'used_quota'
                    ]);
                    $quota = $this->firstApiValue($user, [
                        'user.quotaLimit', 'user.quotalimit', 'user.storagequota',
                        'quotaLimit', 'quotalimit', 'storagequota', 'storage-quota',
                        'storage_quota', 'quota'
                    ]);
                    // The provider returns quota values in KB. Display them in MB,
                    // switching to GB when the value reaches 1 GB (base 1024).
                    $formatQuota = static function ($value) {
                        if ($value === '' || !is_numeric($value)) {
                            return '';
                        }

                        $kb = (float)$value;
                        if ($kb >= 1024 * 1024) {
                            $amount = $kb / (1024 * 1024);
                            $unit = 'GB';
                        } else {
                            $amount = $kb / 1024;
                            $unit = 'MB';
                        }

                        $formatted = rtrim(rtrim(number_format($amount, 2, ',', ' '), '0'), ',');
                        return $formatted . ' ' . $unit;
                    };

                    $usedText = $formatQuota($used);
                    $quotaText = $formatQuota($quota);
                    $quotaDisplay = ($usedText !== '' ? $usedText : '—')
                        . ' / '
                        . ($quotaText !== '' ? $quotaText : '—');

                    $html .= '<tr><td>' . $e($email ?: '—') . '</td><td>' . $e($status ?: '—') . '</td><td>' . $e($type ?: '—') . '</td><td>' . $e($quotaDisplay) . '</td></tr>';
                }
                $html .= '</tbody></table></div>';
            }
        } catch (Throwable $exception) {
            $this->logEmailDiagnostic('Mailbox list exception for service ' . (int)($service->id ?? 0) . ': ' . $exception->getMessage());
            $html .= '<div class="alert alert-warning">We could not retrieve the mailbox list from the email provider. Please contact support if the problem persists.</div>';
        }

        $html .= '<p class="small text-muted">Account counts are retrieved from OrderBox where available. Storage Blocks Total reflects the quantity recorded on this Blesta service; allocation availability is not exposed reliably by the current API response.</p>';
        return $html . '</section>';
    }

    /**
     * Normalize the OrderBox search response into rows containing user fields.
     * API response field names may be dotted (e.g. user.email).
     */
    private function collectEmailUsers($data)
    {
        if (is_object($data)) {
            $data = (array)$data;
        }
        if (!is_array($data)) {
            return [];
        }

        if ($this->getEmailAddressFromUser($data) !== '') {
            return [$data];
        }

        $users = [];
        foreach ($data as $value) {
            if (is_object($value)) {
                $value = (array)$value;
            }
            if (is_array($value)) {
                $users = array_merge($users, $this->collectEmailUsers($value));
            }
        }
        return $users;
    }

    /** Resolve mailbox addresses across the different OrderBox response formats. */
    private function getEmailAddressFromUser($data)
    {
        if (is_object($data)) {
            $data = (array)$data;
        }
        if (!is_array($data)) {
            return '';
        }

        // Normalize keys because OrderBox may return emailAddress, emailaddress,
        // dotted keys such as user.email, or a nested user object.
        $normalized = [];
        foreach ($data as $key => $value) {
            $normalized[strtolower(str_replace(['_', '-'], '', (string)$key))] = $value;
        }
        $keys = ['emailaddress', 'email', 'user.email', 'username', 'user.username', 'emailid'];
        foreach ($keys as $key) {
            $lookup = strtolower(str_replace(['_', '-'], '', $key));
            if (!array_key_exists($lookup, $normalized)) {
                continue;
            }
            $candidate = $normalized[$lookup];
            if (is_scalar($candidate)) {
                $candidate = trim((string)$candidate);
                // Do not require FILTER_VALIDATE_EMAIL here: sandbox/provider
                // responses can contain aliases that are still valid mailbox IDs.
                if ($candidate !== '' && strpos($candidate, '@') !== false) {
                    return $candidate;
                }
            }
        }

        if (isset($normalized['user'])) {
            $nested = is_object($normalized['user']) ? (array)$normalized['user'] : $normalized['user'];
            if (is_array($nested)) {
                foreach ($nested as $key => $value) {
                    $key = strtolower(str_replace(['_', '-'], '', (string)$key));
                    if (in_array($key, ['email', 'emailaddress', 'username', 'emailid'], true) && is_scalar($value)) {
                        $candidate = trim((string)$value);
                        if ($candidate !== '' && strpos($candidate, '@') !== false) {
                            return $candidate;
                        }
                    }
                }
            }
        }

        return '';
    }

    /**
     * Extract the contracted 5 GB storage add-on quantity from OrderBox details.
     * API wrappers may return arrays, objects, or nested response envelopes.
     * Never guess a quantity from Blesta fields when the provider omits it.
     */
    private function extractStorageBlocksFromOrderDetails($data)
    {
        $walk = function ($node, $depth = 0) use (&$walk) {
            if ($depth > 12) return null;
            if (is_object($node)) $node = (array)$node;
            if (is_string($node)) {
                $trimmed = trim($node);
                if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
                    $decoded = json_decode($trimmed, true);
                    if (json_last_error() === JSON_ERROR_NONE) return $walk($decoded, $depth + 1);
                }
                return null;
            }
            if (!is_array($node)) return null;

            // OrderBox /details returns addons as a LIST, for example:
            // "addons": [{"addon_name":"email_storage_5","quantity":1,"unused_quantity":1}]
            // This is different from the older/alternate shape addon.email_storage_5.quantity.
            foreach ($node as $key => $value) {
                $normalized = strtolower(str_replace(['_', '-', ' '], '', (string)$key));
                if ($normalized === 'addons' && is_array($value)) {
                    foreach ($value as $addonEntry) {
                        if (is_object($addonEntry)) $addonEntry = (array)$addonEntry;
                        if (!is_array($addonEntry)) continue;
                        $addonName = $addonEntry['addon_name'] ?? $addonEntry['addon-name'] ?? $addonEntry['name'] ?? '';
                        if (strtolower(trim((string)$addonName)) !== 'email_storage_5') continue;
                        $quantity = $addonEntry['quantity'] ?? null;
                        if (is_scalar($quantity) && is_numeric($quantity)) {
                            return max(0, (int)$quantity);
                        }
                    }
                }
                // Retain compatibility with responses that expose the add-on as a keyed object.
                if ($normalized === 'emailstorage5') {
                    if (is_object($value)) $value = (array)$value;
                    if (is_array($value)) {
                        foreach ($value as $quantityKey => $quantityValue) {
                            $qk = strtolower(str_replace(['_', '-', ' '], '', (string)$quantityKey));
                            if ($qk === 'quantity' && is_scalar($quantityValue) && is_numeric($quantityValue)) {
                                return max(0, (int)$quantityValue);
                            }
                        }
                    }
                }
            }
            foreach ($node as $value) {
                $found = $walk($value, $depth + 1);
                if ($found !== null) return $found;
            }
            return null;
        };
        return $walk($data);
    }

    private function firstApiValue($data, array $keys)
    {
        if (is_object($data)) {
            $data = (array)$data;
        }
        if (!is_array($data)) {
            return '';
        }
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                return trim((string)$data[$key]);
            }
        }
        return '';
    }

	    public function getAdminAddFields($package, $vars = null)
    {
        $vars = (array)$vars;
        $fields = new ModuleFields();

        $domain = $fields->label('Domínio de email', 'email_domain');
        $domain->attach($fields->fieldText(
            'email_domain',
            $vars['email_domain'] ?? ($vars['domain'] ?? ''),
            [
                'id' => 'email_domain',
                'class' => 'form-control',
                'placeholder' => 'empresa.pt'
            ]
        ));

        $fields->setField($domain);

        return $fields;
    }
	
    public function getClientAddFields($package, $vars = null)
    {
        $vars = (array)$vars;
        $fields = new ModuleFields();
        $domain = $fields->label(Language::_('LogicboxesEmail.email_domain', true), 'email_domain');
        $domain->attach($fields->fieldText('email_domain', $vars['email_domain'] ?? ($vars['domain'] ?? ''), [
            'id' => 'email_domain', 'class' => 'form-control', 'placeholder' => 'example.com'
        ]));
        $fields->setField($domain);
        return $fields;
    }

    /** Resolve the domain from Blesta service/order payloads, including nested field objects. */
    private function getDomainFromVars(array $vars)
    {
        $domainKeys = ['email_domain', 'domain', 'domain_name', 'domain-name'];
        $walk = function ($value, $key = '') use (&$walk, $domainKeys) {
            $normalizedKey = strtolower((string)$key);
            if (in_array($normalizedKey, $domainKeys, true)) {
                if (is_scalar($value)) {
                    $candidate = trim((string)$value);
                    if ($candidate !== '') return $candidate;
                }
                if (is_object($value)) $value = (array)$value;
                if (is_array($value)) {
                    foreach (['value', 'default', 'current'] as $valueKey) {
                        if (isset($value[$valueKey]) && is_scalar($value[$valueKey])) {
                            $candidate = trim((string)$value[$valueKey]);
                            if ($candidate !== '') return $candidate;
                        }
                    }
                }
            }
            if (is_object($value)) $value = (array)$value;
            if (is_array($value)) {
                // Blesta can supply fields as a keyed map or as [{key, value}, ...].
                if (isset($value['key'], $value['value']) && is_scalar($value['key'])
                    && in_array(strtolower((string)$value['key']), $domainKeys, true)
                    && is_scalar($value['value'])) {
                    $candidate = trim((string)$value['value']);
                    if ($candidate !== '') return $candidate;
                }
                foreach ($value as $childKey => $childValue) {
                    $found = $walk($childValue, (string)$childKey);
                    if (is_string($found) && $found !== '') return $found;
                }
            }
            return null;
        };
        return $walk($vars) ?? '';
    }

    public function validateService($package, array $vars = null)
    {
        $vars = (array)$vars;
        $domain = $this->getDomainFromVars($vars);
        $accounts = $this->getMailboxCount($vars, 1);
        $errors = [];

        // Blesta may call validateService() while an administrator activates an
        // existing service. That form may contain configurable options only, not
        // the original email_domain field. An absent domain is not invalid here;
        // addService() still performs strict validation before creating an order.
        if ($domain !== '' && !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $domain)) {
            $errors['email_domain'] = ['empty' => Language::_('LogicboxesEmail.invalid_domain', true)];
        }
        if ($accounts < 1 || $accounts > 10000) {
            $errors['no_of_accounts'] = ['valid' => Language::_('LogicboxesEmail.invalid_accounts', true)];
        }
        if ($errors) {
            $this->Input->setErrors($errors);
            return false;
        }
        return true;
    }

    public function addService($package, array $vars = null, $parent_package = null, $parent_service = null, $status = 'pending')
    {
        $vars = (array)$vars;
        $row = $this->getModuleRow($package->module_row);
        if (!$row || empty($row->meta->reseller_id) || empty($row->meta->key)) {
            $this->setModuleError('config', 'No LogicBoxes account is configured for this package.');
            return;
        }
        $domain = strtolower($this->getDomainFromVars($vars));
        if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $domain)) {
            $this->setModuleError('email_domain', Language::_('LogicboxesEmail.invalid_domain', true));
            return;
        }

        $product = (string)($package->meta->email_product ?? 'business_email');
        // Business Email and Enterprise Email use the classic US HTTP API.
        $endpoint = $product === 'enterprise_email' ? 'enterpriseemail/us/add' : 'eelite/us/add';
        $accounts = $this->getMailboxCount($vars, 1);
        $storageBlocks = $this->getStorageBlocks($vars);
        if ($accounts < 1 || $accounts > 10000) {
            $this->setModuleError('mailboxes', 'Mailbox quantity must be between 1 and 10000.');
            return;
        }
        $months = 1;
        foreach ((array)($package->pricing ?? []) as $pricing) {
            if (isset($vars['pricing_id']) && (string)$pricing->id === (string)$vars['pricing_id']) {
                $months = max(1, (int)$pricing->term);
                break;
            }
        }

		if (($vars['use_module'] ?? 'false') !== 'true') {
		    return [
		        ['key' => 'email_domain', 'value' => $domain, 'encrypted' => 0],
		        ['key' => 'email_product', 'value' => $product, 'encrypted' => 0],
		        ['key' => 'email_order_id', 'value' => '', 'encrypted' => 0],
		        ['key' => 'email_customer_id', 'value' => '', 'encrypted' => 0],
		        ['key' => 'email_accounts', 'value' => (string)$accounts, 'encrypted' => 0],
		        ['key' => 'email_storage_blocks', 'value' => (string)$storageBlocks, 'encrypted' => 0]
		    ];
		}
		
        try {
            Loader::loadModels($this, ['Clients', 'Contacts']);
            $client = $this->Clients->get($vars['client_id'] ?? null);
            if (!$client) {
                $this->setModuleError('client', 'Could not load the Blesta client for this email service.');
                return;
            }
            $api = $this->getLogicboxesApi($row->meta->reseller_id, $row->meta->key, ($row->meta->sandbox ?? 'false') === 'true');
            $customerInfo = $this->findOrCreateCustomer($api, $client);
            $customerId = is_array($customerInfo) ? (string)($customerInfo['customer_id'] ?? '') : '';
            $customerPassword = is_array($customerInfo) ? (string)($customerInfo['password'] ?? '') : '';
            if ($customerId === '') {
                $this->setModuleError('customer', 'LogicBoxes customer lookup/creation failed; see the Blesta module log for the API response.');
                return;
            }
            $api = $this->getLogicboxesApi($row->meta->reseller_id, $row->meta->key, ($row->meta->sandbox ?? 'false') === 'true');
            $response = $api->submit($endpoint, [
                'domain-name' => $domain,
                'customer-id' => $customerId,
                'months' => $months,
                'no-of-accounts' => $accounts,
                'auto-renew' => 'false',
                'invoice-option' => 'NoInvoice',
                'discount-amount' => '0.00'
            ], 'POST');
            $this->logApiResponse($api, $response);
            if (!$this->isSuccessfulResponse($response) || empty($response->response()->entityid)) {
                $this->setModuleError('provisioning', $this->responseError($response));
                return;
            }
            $data = $response->response();
            $orderId = (string)$data->entityid;
            // Storage is a separate billable API action; only call it after the order exists.
            if ($storageBlocks > 0) {
                $storageEndpoint = $product === 'enterprise_email' ? 'enterpriseemail/us/addon/add' : 'eelite/us/addon/add';
                $storageResponse = $api->submit($storageEndpoint, [
                    'order-id' => $orderId,
                    'addon' => 'email_storage_5',
                    'quantity' => $storageBlocks,
                    'invoice-option' => 'NoInvoice',
                    'discount-amount' => '0.00'
                ], 'POST');
                $this->logApiResponse($api, $storageResponse);
                if (!$this->isSuccessfulResponse($storageResponse)) {
                    // Keep the created order linked in Blesta to avoid duplicate order creation on retry.
                    $this->logEmailDiagnostic('WARNING: email order ' . $orderId . ' was created but adding ' . $storageBlocks . ' storage block(s) failed: ' . $this->responseError($storageResponse));
                }
            }
            return [
                ['key' => 'email_domain', 'value' => $domain, 'encrypted' => 0],
                ['key' => 'email_product', 'value' => $product, 'encrypted' => 0],
                ['key' => 'email_order_id', 'value' => $orderId, 'encrypted' => 0],
                ['key' => 'email_customer_id', 'value' => (string)$customerId, 'encrypted' => 0],
                ['key' => 'email_customer_username', 'value' => (string)$client->email, 'encrypted' => 0],
                ['key' => 'email_customer_password', 'value' => $customerPassword, 'encrypted' => 1],
                ['key' => 'email_accounts', 'value' => (string)$accounts, 'encrypted' => 0],
                ['key' => 'email_storage_blocks', 'value' => (string)$storageBlocks, 'encrypted' => 0]
            ];
        } catch (Throwable $e) {
            $this->logEmailDiagnostic($e->getMessage());
            $this->setModuleError('exception', $e->getMessage());
            return;
        }
    }

    /**
     * Called by Blesta when a service is edited, including when its configurable
     * options change (admin edit, client upgrade). Compares the new quantities with
     * the values stored on the service and buys or removes only the difference at LogicBoxes.
     *
     * Blesta stores the returned array as the new service fields, so every field
     * must be returned, not only the ones that changed.
     */
    public function editService($package, $service, array $vars = null, $parent_package = null, $parent_service = null)
    {
$this->logEmailDiagnostic(
    'editService called: service_id=' . (int)($service->id ?? 0)
    . '; vars=' . json_encode($vars)
);
        $vars = (array)$vars;
        $fields = $this->serviceFieldsToObject($service->fields);
        $product = (string)($fields->email_product ?? ($package->meta->email_product ?? 'business_email'));
        $orderId = (string)($fields->email_order_id ?? '');
        $currentAccounts = (int)($fields->email_accounts ?? 0);
        $currentBlocks = (int)($fields->email_storage_blocks ?? 0);

        // Blesta calls Services::edit from ClientServices::review while the
        // customer is still on the Review and Pay checkout step. In this path
        // use_module=true does NOT mean that payment has been completed.
        // Never send provisioning requests to OrderBox from this call stack.
        $isReviewCheckout = false;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $frame) {
            if (($frame['class'] ?? '') === 'Blesta\\App\\Controller\\ClientServices'
                && ($frame['function'] ?? '') === 'review') {
                $isReviewCheckout = true;
                break;
            }
            // Some Blesta versions/controllers expose the short class name.
            if (($frame['class'] ?? '') === 'ClientServices'
                && ($frame['function'] ?? '') === 'review') {
                $isReviewCheckout = true;
                break;
            }
        }
        if ($isReviewCheckout) {
            // Preserve requested quantities separately; actual fields remain the provider state.
            $requestedAccounts = $this->getPackageConfigOptionValue(
                $package, $vars,
                ['logicboxes_email_mailboxes', 'mailboxes', 'mailbox', 'email accounts', 'number of email accounts', 'no-of-accounts', 'no_of_accounts']
            );
            $requestedBlocks = $this->getPackageConfigOptionValue(
                $package, $vars,
                ['logicboxes_email_extra_storage', 'extra storage (5 gb blocks)', 'extra storage', 'storage blocks', 'no-of-storage5-blocks', 'no_of_storage5_blocks']
            );
            $requestedAccounts = $requestedAccounts === null ? $currentAccounts : (int)$requestedAccounts;
            $requestedBlocks = $requestedBlocks === null ? $currentBlocks : max(0, (int)$requestedBlocks);
            $this->logEmailDiagnostic('Deferred OrderBox provisioning during ClientServices::review for service '
                . (int)($service->id ?? 0) . '; target accounts=' . $requestedAccounts . ', target storage blocks=' . $requestedBlocks . '.');
            return [
                ['key' => 'email_domain', 'value' => (string)($fields->email_domain ?? ''), 'encrypted' => 0],
                ['key' => 'email_product', 'value' => $product, 'encrypted' => 0],
                ['key' => 'email_order_id', 'value' => $orderId, 'encrypted' => 0],
                ['key' => 'email_customer_id', 'value' => (string)($fields->email_customer_id ?? ''), 'encrypted' => 0],
                ['key' => 'email_customer_username', 'value' => (string)($fields->email_customer_username ?? ''), 'encrypted' => 0],
                ['key' => 'email_customer_password', 'value' => (string)($fields->email_customer_password ?? ''), 'encrypted' => 1],
                ['key' => 'email_accounts', 'value' => (string)$currentAccounts, 'encrypted' => 0],
                ['key' => 'email_storage_blocks', 'value' => (string)$currentBlocks, 'encrypted' => 0],
                ['key' => 'email_pending_accounts', 'value' => (string)$requestedAccounts, 'encrypted' => 0],
                ['key' => 'email_pending_storage_blocks', 'value' => (string)$requestedBlocks, 'encrypted' => 0],
                ['key' => 'email_pending_change', 'value' => (($requestedAccounts !== $currentAccounts || $requestedBlocks !== $currentBlocks) ? '1' : '0'), 'encrypted' => 0],
                ['key' => 'email_sync_pending', 'value' => (string)($fields->email_sync_pending ?? '0'), 'encrypted' => 0]
            ];
        }

        // null means the option was not part of this request: keep the current value.
        // Blesta sends configurable options as configoptions[OPTION_ID] = selected value.
        // Resolve the numeric option IDs against this package instead of relying only on
        // labels being present in $vars (they normally are not).
        $newAccounts = $this->getPackageConfigOptionValue(
            $package,
            $vars,
            ['logicboxes_email_mailboxes', 'mailboxes', 'mailbox', 'email accounts', 'number of email accounts', 'no-of-accounts', 'no_of_accounts']
        );
        $newBlocks = $this->getPackageConfigOptionValue(
            $package,
            $vars,
            ['logicboxes_email_extra_storage', 'extra storage (5 gb blocks)', 'extra storage', 'storage blocks', 'no-of-storage5-blocks', 'no_of_storage5_blocks']
        );
        $newAccounts = $newAccounts === null ? $currentAccounts : (int)$newAccounts;
        $newBlocks = $newBlocks === null ? $currentBlocks : max(0, (int)$newBlocks);

        $providerAccounts = null;
        // Blesta service fields can be stale (e.g. email_accounts=2 while
        // OrderBox has 5 purchased licenses). Use OrderBox as the baseline for
        // mailbox deltas so we never calculate a downgrade from stale data.
        $row = $this->getModuleRow($package->module_row ?? ($service->module_row_id ?? null));
        if ($orderId !== '' && $row && !empty($row->meta->reseller_id) && !empty($row->meta->key)) {
            try {
                $baselineApi = $this->getLogicboxesApi(
                    $row->meta->reseller_id,
                    $row->meta->key,
                    ($row->meta->sandbox ?? 'false') === 'true'
                );
                $prefix = $product === 'enterprise_email' ? 'enterpriseemail/us' : 'eelite/us';
                $detailsResponse = $baselineApi->submit($prefix . '/details', ['order-id' => $orderId], 'GET');
                $this->logApiResponse($baselineApi, $detailsResponse);
                if (!$this->isSuccessfulResponse($detailsResponse)) {
                    $this->setModuleError('order_details', 'Could not read the current LogicBoxes order. No change was submitted. ' . $this->responseError($detailsResponse));
                    return;
                }
                $details = $detailsResponse->response();
                if (is_object($details) && isset($details->response) && is_object($details->response)) {
                    $details = $details->response;
                }

                // Do not submit a second edit while OrderBox is still processing
                // a previous action. Its details may continue to expose the old
                // quantities until the pending action has completed.
                $actionStatus = strtolower(trim((string)$this->firstApiValue($details, ['actionstatus', 'action-status', 'actionstatusdesc', 'action-status-desc'])));
                $actionType = trim((string)$this->firstApiValue($details, ['actiontype', 'action-type']));
                $actionCompleted = strtolower(trim((string)$this->firstApiValue($details, ['actioncompleted', 'action-completed'])));
                $pendingActionStatuses = ['begunexecution', 'pending', 'processing', 'inprogress', 'in progress', 'queued', 'waiting', 'waiting to be processed'];
                $hasPendingAction = in_array($actionStatus, $pendingActionStatuses, true)
                    || ($actionType !== '' && $actionCompleted === 'false');
                if ($hasPendingAction) {
                    $this->logEmailDiagnostic('Rejected configurable-option edit for service ' . (int)($service->id ?? 0) . ': OrderBox action still in progress (actiontype=' . $actionType . ', actionstatus=' . $actionStatus . ', actioncompleted=' . $actionCompleted . ').');
                    $this->setModuleError('order_action_pending', 'A previous operation is still being processed by the email provider. Please wait a few minutes and try again. No new change was submitted.');
                    return;
                }

                $providerAccounts = $this->firstApiValue($details, ['emailaccounts', 'email-accounts', 'email_accounts', 'numberofemailaccounts', 'number-of-email-accounts']);
                if ($providerAccounts !== '' && is_numeric($providerAccounts)) {
                    $currentAccounts = (int)$providerAccounts;
                } else {
                    $this->setModuleError('order_details', 'OrderBox did not return the contracted mailbox count. No change was submitted to avoid removing the wrong number of licenses.');
                    return;
                }
                $storageQty = $this->extractStorageBlocksFromOrderDetails($details);
                if (is_numeric($storageQty) && (int)$storageQty >= 0) {
                    $currentBlocks = (int)$storageQty;
                } else {
                    $this->logEmailDiagnostic('OrderBox details did not include addon email_storage_5 quantity for service ' . (int)($service->id ?? 0) . '; using stored storage field as fallback.');
                }
            } catch (Throwable $e) {
                $this->logEmailDiagnostic('Could not read OrderBox order details before configurable-option change: ' . $e->getMessage());
                $this->setModuleError('order_details', 'Could not verify the current OrderBox mailbox count. No change was submitted.');
                return;
            }
        }

        // The package permits zero mailboxes in the edit form so customers can
        // request storage-only changes. For an existing service, zero means
        // "do not change mailbox count", not "remove all mailboxes".
        if ($newAccounts === 0 && $currentAccounts > 0) {
            $newAccounts = $currentAccounts;
        }

        // A service created before these fields were stored has no baseline. Adopt the
        // submitted value instead of treating it as a purchase (avoids buying a mailbox by accident).
        if ((!isset($fields->email_accounts) || $fields->email_accounts === '') && $providerAccounts === null) {
            $currentAccounts = $newAccounts;
        }
        if (!isset($fields->email_storage_blocks) || $fields->email_storage_blocks === '') {
            $currentBlocks = $newBlocks;
        }

        $addAccounts = $newAccounts - $currentAccounts;
        $addBlocks = $newBlocks - $currentBlocks;
        $delAccounts = max(0, -$addAccounts);
        $delBlocks = max(0, -$addBlocks);
        $syncPending = in_array(strtolower(trim((string)($fields->email_sync_pending ?? '0'))), ['1', 'true', 'yes'], true);

        // Storage downgrades use the provider's addon/delete operation for the selected product.
        // Let OrderBox validate support and surface its real API response instead of blocking Enterprise Email here.
        if ($newAccounts < 1 || $newAccounts > 10000) {
            $this->setModuleError('mailboxes', 'Mailbox quantity must be between 1 and 10000.');
            return;
        }

        // Provider changes require an explicit module-provisioning signal.
        // The checkout review path is blocked above even if Blesta sends true.
        $useModule = array_key_exists('use_module', $vars)
            && ($vars['use_module'] === 'true' || $vars['use_module'] === true || $vars['use_module'] === 1 || $vars['use_module'] === '1');
        if ($useModule && ($addAccounts !== 0 || $addBlocks !== 0)) {
            if ($orderId === '') {
                $this->setModuleError('order_id', 'The LogicBoxes order ID is missing from this service.');
                return;
            }
            $row = $this->getModuleRow($package->module_row);
            if (!$row || empty($row->meta->reseller_id) || empty($row->meta->key)) {
                $this->setModuleError('config', 'No LogicBoxes account is configured for this package.');
                return;
            }
            try {
                $api = $this->getLogicboxesApi($row->meta->reseller_id, $row->meta->key, ($row->meta->sandbox ?? 'false') === 'true');
                $prefix = $product === 'enterprise_email' ? 'enterpriseemail/us' : 'eelite/us';

                if ($addAccounts > 0) {
                    $response = $api->submit($prefix . '/add-email-account', [
                        'order-id' => $orderId,
                        'no-of-accounts' => $addAccounts,
                        'invoice-option' => 'NoInvoice',
                        'discount-amount' => '0.00'
                    ], 'POST');
                    $this->logApiResponse($api, $response);
                    if (!$this->isSuccessfulResponse($response)) {
                        $this->setModuleError('add_accounts', $this->responseError($response));
                        return;
                    }
                    $currentAccounts = $newAccounts;
                    $syncPending = true;
                }

                if ($addBlocks > 0) {
                    $response = $api->submit($prefix . '/addon/add', [
                        'order-id' => $orderId,
                        'addon' => 'email_storage_5',
                        'quantity' => $addBlocks,
                        'invoice-option' => 'NoInvoice',
                        'discount-amount' => '0.00'
                    ], 'POST');
                    $this->logApiResponse($api, $response);
                    if (!$this->isSuccessfulResponse($response)) {
                        // Accounts may already have been added above: store that, fail the rest.
                        $this->setModuleError('add_storage', $this->responseError($response));
                        return;
                    }
                    $currentBlocks = $newBlocks;
                    $syncPending = true;
                }

                // Downgrades. Mailboxes: POST {prefix}/delete-email-account (order-id, no-of-accounts).
                if ($delAccounts > 0) {
                    // delete-email-account removes purchased licenses, not a specific mailbox.
                    // Do not block reductions just because the order has unused licenses; the
                    // provider validates whether the requested reduction is permitted.
                    // Keep a safety check only when the new plan would be below actual mailboxes.
                    $countResponse = $api->submit('mail/users/search', ['order-id' => $orderId, 'page-no' => 1], 'GET');
                    if (!$this->isSuccessfulResponse($countResponse)) {
                        $this->setModuleError('verify_accounts', 'Could not verify current mailbox usage. No change was made. ' . $this->responseError($countResponse));
                        return;
                    }
                    $mailboxCount = 0;
                    foreach ($this->collectEmailUsers($countResponse->response()) as $u) {
                        $type = strtolower($this->firstApiValue($u, ['user.accounttype', 'accounttype', 'account-type', 'account_type']));
                        if ($type === '' || strpos($type, 'forward') === false) $mailboxCount++;
                    }
                    if ($mailboxCount > $newAccounts) {
                        $this->setModuleError('accounts_in_use', 'The new plan allows ' . $newAccounts . ' mailbox(es), but ' . $mailboxCount . ' mailbox(es) are currently created. Delete the extra mailbox(es) before reducing the plan.');
                        return;
                    }
                    $response = $api->submit($prefix . '/delete-email-account', [
                        'order-id' => $orderId,
                        'no-of-accounts' => $delAccounts
                    ], 'POST');
                    $this->logApiResponse($api, $response);
                    if (!$this->isSuccessfulResponse($response)) {
                        $this->setModuleError('delete_accounts', $this->responseError($response));
                        return;
                    }
                    $currentAccounts = $newAccounts;
                    $syncPending = true;
                }

                // Storage downgrade: POST {prefix}/addon/delete (order-id, addon, quantity); surface the provider response for both products.
                if ($delBlocks > 0) {
                    $response = $api->submit($prefix . '/addon/delete', [
                        'order-id' => $orderId,
                        'addon' => 'email_storage_5',
                        'quantity' => $delBlocks
                    ], 'POST');
                    $this->logApiResponse($api, $response);
                    if (!$this->isSuccessfulResponse($response)) {
                        $this->setModuleError('delete_storage', $this->responseError($response));
                        return;
                    }
                    $currentBlocks = $newBlocks;
                    $syncPending = true;
                }
            } catch (Throwable $e) {
                $this->logEmailDiagnostic($e->getMessage());
                $this->setModuleError('exception', $e->getMessage());
                return;
            }
        } else {
            // use_module is off (or nothing changed): only update what Blesta stores.
            $currentAccounts = $newAccounts;
            $currentBlocks = $newBlocks;
        }

        return [
            ['key' => 'email_domain', 'value' => (string)($fields->email_domain ?? ''), 'encrypted' => 0],
            ['key' => 'email_product', 'value' => $product, 'encrypted' => 0],
            ['key' => 'email_order_id', 'value' => $orderId, 'encrypted' => 0],
            ['key' => 'email_customer_id', 'value' => (string)($fields->email_customer_id ?? ''), 'encrypted' => 0],
            ['key' => 'email_customer_username', 'value' => (string)($fields->email_customer_username ?? ''), 'encrypted' => 0],
            ['key' => 'email_customer_password', 'value' => (string)($fields->email_customer_password ?? ''), 'encrypted' => 1],
            ['key' => 'email_accounts', 'value' => (string)$currentAccounts, 'encrypted' => 0],
            ['key' => 'email_storage_blocks', 'value' => (string)$currentBlocks, 'encrypted' => 0],
            ['key' => 'email_pending_accounts', 'value' => '', 'encrypted' => 0],
            ['key' => 'email_pending_storage_blocks', 'value' => '', 'encrypted' => 0],
            ['key' => 'email_pending_change', 'value' => '0', 'encrypted' => 0],
            ['key' => 'email_sync_pending', 'value' => $syncPending ? '1' : '0', 'encrypted' => 0]
        ];
    }

    public function suspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        return $this->changeOrderStatus($package, $service, 'suspend');
    }

    public function unsuspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        return $this->changeOrderStatus($package, $service, 'unsuspend');
    }

    public function cancelService($package, $service, $parent_package = null, $parent_service = null)
    {
        // Blesta cancellation may be scheduled for the end of the billing term.
        // Do not immediately delete a paid third-party order here.
        return null;
    }

    public function renewService($package, $service, $parent_package = null, $parent_service = null)
    {
        $fields = $this->serviceFieldsToObject($service->fields);
        if (empty($fields->email_order_id)) {
            $this->setModuleError('order_id', 'The LogicBoxes order ID is missing from this service.');
            return;
        }
        $months = 1;
        foreach ((array)($package->pricing ?? []) as $pricing) {
            if (isset($service->pricing_id) && (string)$pricing->id === (string)$service->pricing_id) {
                $months = max(1, (int)$pricing->term);
                break;
            }
        }
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getLogicboxesApi($row->meta->reseller_id, $row->meta->key, ($row->meta->sandbox ?? 'false') === 'true');
        $prefix = (($fields->email_product ?? 'business_email') === 'enterprise_email' ? 'enterpriseemail/us' : 'eelite/us');
        $params = [
            'order-id' => $fields->email_order_id,
            'months' => $months,
            'auto-renew' => 'false',
            'invoice-option' => 'NoInvoice',
            'discount-amount' => '0.00'
        ];
        $response = $api->submit($prefix . '/renew', $params, 'POST');
        $this->logApiResponse($api, $response);
        if (!$this->isSuccessfulResponse($response)) {
            $this->setModuleError('renew', $this->responseError($response));
        }
        return null;
    }

    private function changeOrderStatus($package, $service, $action)
    {
        $fields = $this->serviceFieldsToObject($service->fields);
        if (empty($fields->email_order_id)) {
            $this->setModuleError('order_id', 'The LogicBoxes order ID is missing from this service.');
            return;
        }
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getLogicboxesApi($row->meta->reseller_id, $row->meta->key, ($row->meta->sandbox ?? 'false') === 'true');
        $prefix = (($fields->email_product ?? 'business_email') === 'enterprise_email' ? 'enterpriseemail/us' : 'eelite/us');
        $response = $api->submit($prefix . '/' . $action, ['order-id' => $fields->email_order_id], 'POST');
        $this->logApiResponse($api, $response);
        if (!$this->isSuccessfulResponse($response)) {
            $this->setModuleError($action, $this->responseError($response));
        }
        return null;
    }

    private function findOrCreateCustomer($api, $client)
    {
        $search = $api->submit('customers/search', ['username' => $client->email, 'no-of-records' => 10, 'page-no' => 1], 'GET');
        $this->logApiResponse($api, $search);
        if ($search->status() === 'OK' && $search->response()) {
            foreach ((array)$search->response() as $item) {
                if (isset($item->{'customer.customerid'}) && strcasecmp((string)($item->{'customer.username'} ?? ''), (string)$client->email) === 0) {
                    return ['customer_id' => (string)$item->{'customer.customerid'}, 'password' => ''];
                }
            }
        }

        $numbers = $this->Contacts->getNumbers($client->contact_id, 'phone');
        $phone = isset($numbers[0]->number) ? preg_replace('/[^0-9]/', '', $numbers[0]->number) : '';
        if (strlen($phone) < 6) {
            $phone = '1234567';
        }
        $country = strtoupper((string)($client->country ?? 'PT'));
        $name = trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? '')) ?: (string)$client->email;
        $customerPassword = substr(bin2hex(random_bytes(8)), 0, 15);
        $signup = $api->submit('customers/signup', [
            'username' => (string)$client->email,
            'passwd' => $customerPassword,
            'name' => $name,
            'company' => !empty($client->company) ? (string)$client->company : 'Not Applicable',
            'address-line-1' => !empty($client->address1) ? (string)$client->address1 : 'Not Applicable',
            'address-line-2' => (string)($client->address2 ?? ''),
            'city' => !empty($client->city) ? (string)$client->city : 'Not Applicable',
            'state' => !empty($client->state) ? (string)$client->state : 'Not Applicable',
            'country' => $country,
            'zipcode' => !empty($client->zip) ? (string)$client->zip : '0000',
            'phone-cc' => $country === 'PT' ? '351' : '1',
            'phone' => $phone,
            'lang-pref' => 'en'
        ], 'POST');
        $this->logApiResponse($api, $signup);
        if ($signup->status() === 'OK') {
            $r = $signup->response();
            if (isset($r->customerid)) return ['customer_id' => (string)$r->customerid, 'password' => $customerPassword];
            if (isset($r->entityid)) return ['customer_id' => (string)$r->entityid, 'password' => $customerPassword];
        }
        // Re-search because signup can report a duplicate username after a race.
        $search = $api->submit('customers/search', ['username' => $client->email, 'no-of-records' => 10, 'page-no' => 1], 'GET');
        if ($search->status() === 'OK') {
            foreach ((array)$search->response() as $item) {
                if (isset($item->{'customer.customerid'}) && strcasecmp((string)($item->{'customer.username'} ?? ''), (string)$client->email) === 0) return ['customer_id' => (string)$item->{'customer.customerid'}, 'password' => ''];
            }
        }
        return null;
    }

    /**
     * OrderBox demo rewrites requested domains to <requested-domain>.onlyfordemo.com.
     * Use that provider-side domain for mailbox addresses only when sandbox mode is enabled.
     */
    private function getEffectiveEmailDomain($domain, $row)
    {
        $domain = strtolower(trim((string)$domain));
        $sandbox = isset($row->meta->sandbox) && $row->meta->sandbox === 'true';
        if ($sandbox && $domain !== '' && substr($domain, -17) !== '.onlyfordemo.com') {
            $domain .= '.onlyfordemo.com';
        }
        return $domain;
    }

    protected function serviceFieldsToObject($fields)
    {
        $result = new stdClass();
        foreach ((array)$fields as $field) {
            if (isset($field->key)) $result->{$field->key} = $field->value ?? null;
        }
        return $result;
    }

    private function isSuccessfulResponse($response)
    {
        if ($response->status() !== 'OK') return false;
        $data = $response->response();
        // LogicBoxes can return transport status OK while the nested operation failed.
        $result = (is_object($data) && isset($data->response) && is_object($data->response)) ? $data->response : $data;
        foreach ([$data, $result] as $candidate) {
            if (!is_object($candidate)) continue;
            foreach (['status', 'actionstatus'] as $statusKey) {
                if (isset($candidate->{$statusKey}) && in_array(strtolower((string)$candidate->{$statusKey}), ['failed', 'failure', 'error'], true)) {
                    return false;
                }
            }
        }
        return true;
    }

    private function responseError($response)
    {
        $data = $response->response();
        $result = (is_object($data) && isset($data->response) && is_object($data->response)) ? $data->response : $data;
        if (is_object($result)) {
            $message = $result->message ?? $result->error ?? $result->actionstatusdesc ?? null;
            if ($message !== null && $message !== '') return (string)$message;
            $code = $result->errorCode ?? $result->errorcode ?? null;
            if ($code !== null && $code !== '') return 'Erro devolvido pela LogicBoxes (' . (string)$code . ').';
        }
        $error = $response->errors();
        if (is_object($error)) return (string)($error->message ?? $error->error ?? $error->actionstatusdesc ?? 'LogicBoxes returned an error.');
        return 'LogicBoxes returned an invalid or unsuccessful response: ' . substr((string)$response->raw(), 0, 500);
    }

    private function setModuleError($key, $message)
    {
        // Blesta Input errors must contain scalar message strings. Nested arrays here
        // can reach AppController::setMessage() and crash Html::safe() with a TypeError.
        $this->Input->setErrors(['module' => [$key => (string)$message]]);
    }

    /**
     * Write API traffic to Blesta's native System > Logs > Module log, like other
     * first-party modules. Successful traffic must not be recorded as a system error.
     */
    private function logApiResponse($api, $response)
    {
        $request = $api->lastRequest();
        $url = (string)($request['url'] ?? 'LogicBoxes API');
        $args = (isset($request['args']) && is_array($request['args'])) ? $request['args'] : [];

        // Never persist API credentials or passwords in module logs.
        foreach (['api-key', 'passwd', 'password', 'auth-token', 'auth_token', 'token'] as $secretKey) {
            if (array_key_exists($secretKey, $args)) {
                $args[$secretKey] = '********';
            }
        }

        try {
            $successful = $this->isSuccessfulResponse($response);
        } catch (Throwable $e) {
            $successful = false;
        }

        $inputData = json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($inputData === false) {
            $inputData = serialize($args);
        }
        $outputData = substr((string)$response->raw(), 0, 10000);

        // Use Blesta's native Module::log() helper, as documented for modules.
        // It writes directly to System > Logs > Module and pairs entries by URL/group.
        $this->log($url, $inputData, 'input', $successful);
        $this->log($url, $outputData, 'output', $successful);

        // Keep genuine API failures visible in the system error log as well.
        if (!$successful) {
            $this->logEmailDiagnostic('API request failed: ' . $url . ' request=' . $inputData . ' response=' . substr($outputData, 0, 2000));
        }
    }

    /**
     * Validate LogicBoxes credentials using the read-only reseller details endpoint.
     * This performs no order mutations.
     */
    public function validateConnection($key, $reseller_id, $sandbox)
    {
        try {
            $api = $this->getLogicboxesApi($reseller_id, $key, $sandbox === 'true');
            $response = $api->submit('enterpriseemail/us/search', ['no-of-records' => 1, 'page-no' => 1], 'GET');
            return $response->status() === 'OK';
        } catch (Throwable $e) {
            $this->logEmailDiagnostic($e->getMessage());
            return false;
        }
    }

    /** Read mailbox quantity from Blesta configurable options. Create an option named "Mailboxes". */
    private function getMailboxCount(array $vars, $default = 1)
    {
        $value = $this->getNamedConfigOption($vars, ['logicboxes_email_mailboxes', 'mailboxes', 'mailbox', 'email accounts', 'number of email accounts', 'no-of-accounts', 'no_of_accounts']);
        if ($value === null) {
            foreach (['logicboxes_email_mailboxes', 'mailboxes', 'no_of_accounts', 'email_accounts'] as $key) {
                if (isset($vars[$key]) && is_numeric($vars[$key])) return (int)$vars[$key];
            }
            $quantities = $this->getNumericConfigOptionValues($vars);
            return isset($quantities[0]) ? (int)$quantities[0] : (int)$default;
        }
        return is_numeric($value) ? (int)$value : (int)$default;
    }

    /** Read number of 5 GB blocks from a configurable option named "Extra storage (5 GB blocks)". */
    private function getStorageBlocks(array $vars)
    {
        $value = $this->getNamedConfigOption($vars, ['logicboxes_email_extra_storage', 'extra storage (5 gb blocks)', 'extra storage', 'storage blocks', 'no-of-storage5-blocks', 'no_of_storage5_blocks']);
        if ($value === null) {
            foreach (['logicboxes_email_extra_storage', 'storage_blocks', 'no_of_storage5_blocks', 'email_storage_blocks'] as $key) {
                if (isset($vars[$key]) && is_numeric($vars[$key])) return max(0, (int)$vars[$key]);
            }
            $quantities = $this->getNumericConfigOptionValues($vars);
            return isset($quantities[1]) ? max(0, (int)$quantities[1]) : 0;
        }
        return is_numeric($value) ? max(0, (int)$value) : 0;
    }

    private function getNumericConfigOptionValues(array $vars)
    {
        $values = [];
        foreach (['configoptions', 'config_options', 'options'] as $container) {
            if (!isset($vars[$container]) || !is_array($vars[$container])) continue;
            foreach ($vars[$container] as $option) {
                if (is_array($option)) $option = $option['value'] ?? $option['qty'] ?? null;
                elseif (is_object($option)) $option = $option->value ?? $option->qty ?? null;
                if (is_numeric($option)) $values[] = (int)$option;
            }
        }
        return $values;
    }

    /**
     * Resolve a submitted Blesta configurable option by its configured name/label.
     * Blesta's configoptions payload is keyed by numeric package-option IDs, so we
     * must map those IDs back to PackageOptions before calculating service deltas.
     */
    private function getPackageConfigOptionValue($package, array $vars, array $names)
    {
        $direct = $this->getNamedConfigOption($vars, $names);
        if ($direct !== null) {
            return $direct;
        }

        if (!isset($vars['configoptions']) || !is_array($vars['configoptions']) || empty($package->id)) {
            return null;
        }

        try {
            Loader::loadModels($this, ['PackageOptions']);
            $packageOptions = $this->PackageOptions->getByPackageId((int)$package->id);
            $wanted = array_map(function ($name) {
                return strtolower(trim((string)$name));
            }, $names);

            $walk = function ($items) use (&$walk, $vars, $wanted) {
                if (is_object($items)) {
                    $items = (array)$items;
                }
                if (!is_array($items)) {
                    return null;
                }

                // A package option record may be returned directly or nested in a group.
                $id = $items['id'] ?? $items['option_id'] ?? $items['package_option_id'] ?? null;
                $name = strtolower(trim((string)($items['name'] ?? $items['label'] ?? $items['option_name'] ?? '')));
                if ($id !== null && $name !== '' && in_array($name, $wanted, true)) {
                    if (array_key_exists((string)$id, $vars['configoptions'])) {
                        $value = $vars['configoptions'][(string)$id];
                        return is_numeric($value) ? (int)$value : null;
                    }
                    if (array_key_exists((int)$id, $vars['configoptions'])) {
                        $value = $vars['configoptions'][(int)$id];
                        return is_numeric($value) ? (int)$value : null;
                    }
                }

                foreach ($items as $child) {
                    $found = $walk($child);
                    if ($found !== null) {
                        return $found;
                    }
                }
                return null;
            };

            return $walk($packageOptions);
        } catch (Throwable $e) {
            $this->logEmailDiagnostic('Could not map submitted configurable option IDs for package ' . (int)$package->id . ': ' . $e->getMessage());
            return null;
        }
    }

    private function getNamedConfigOption(array $vars, array $names)
    {
        // Blesta may pass configurable option values directly by the option's Name
        // (e.g. logicboxes_email_mailboxes), or nested in configoptions/options.
        foreach ($vars as $key => $value) {
            $normalizedKey = strtolower(trim((string)$key));
            if (in_array($normalizedKey, $names, true) && is_numeric($value)) {
                return (int)$value;
            }
        }
        foreach (['configoptions', 'config_options', 'options'] as $container) {
            if (!isset($vars[$container]) || !is_array($vars[$container])) continue;
            foreach ($vars[$container] as $key => $option) {
                $name = strtolower(trim((string)$key));
                $value = $option;
                if (is_array($option)) {
                    $name = strtolower(trim((string)($option['name'] ?? $option['label'] ?? $key)));
                    $value = $option['value'] ?? $option['qty'] ?? null;
                } elseif (is_object($option)) {
                    $name = strtolower(trim((string)($option->name ?? $option->label ?? $key)));
                    $value = $option->value ?? $option->qty ?? null;
                }
                if (in_array($name, $names, true) || in_array(strtolower(str_replace('_', ' ', $name)), $names, true)) {
                    if (is_numeric($value)) return (int)$value;
                }
            }
        }
        return null;
    }

    /**
     * Create the same HTTP API client used by the LogicBoxes domain module.
     */
    private function getLogicboxesApi($resellerId, $key, $sandbox)
    {
        Loader::load(dirname(__FILE__) . DS . 'apis' . DS . 'logicboxes_api.php');
        return new LogicboxesApi($resellerId, $key, $sandbox);
    }

    private function logEmailDiagnostic($message)
    {
        try {
            $logger = $this->getFromContainer('logger');
            $logger->error('[LogicBoxes Email] ' . $message);
        } catch (Throwable $e) {
            // Do not mask the original connection failure.
        }
    }

    private function getRowRules()
    {
        return [
            'account_name' => ['empty' => ['rule' => 'isEmpty', 'negate' => true, 'message' => 'Account Name is required.']],
            'reseller_id' => ['empty' => ['rule' => 'isEmpty', 'negate' => true, 'message' => 'Reseller ID is required.']],
            'key' => ['empty' => ['rule' => 'isEmpty', 'negate' => true, 'message' => 'API Key is required.']]
        ];
    }
}
