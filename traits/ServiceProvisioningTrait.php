<?php
/**
 * Methods extracted from the latest functional main implementation.
 * The methods execute in the LogicboxesEmail module context.
 */
trait ServiceProvisioningTrait
{

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
            // Keep the time of the FIRST pending request so that every invoice
            // issued since then must be paid before an increase is provisioned.
            $hasChange = ($requestedAccounts !== $currentAccounts || $requestedBlocks !== $currentBlocks);
            $prevSince = trim((string)($fields->email_pending_since ?? ''));
            $pendingSince = !$hasChange ? ''
                : (($this->isTruthyFlag($fields->email_pending_change ?? '0') && $prevSince !== '') ? $prevSince : gmdate('Y-m-d H:i:s'));
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
                ['key' => 'email_pending_since', 'value' => $pendingSince, 'encrypted' => 0],
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
            ['key' => 'email_pending_since', 'value' => '', 'encrypted' => 0],
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
		$fields = $this->serviceFieldsToObject($service->fields);
	
		if (empty($fields->email_order_id)) {
			$this->setModuleError(
				'order_id',
				'The LogicBoxes order ID is missing from this service.'
			);
			return;
		}
	
		$row = $this->getModuleRow($package->module_row);
	
		if (!$row || empty($row->meta->reseller_id) || empty($row->meta->key)) {
			$this->setModuleError(
				'cancel',
				'LogicBoxes reseller credentials are missing.'
			);
			return;
		}
	
		$api = $this->getLogicboxesApi(
			$row->meta->reseller_id,
			$row->meta->key,
			($row->meta->sandbox ?? 'false') === 'true'
		);
	
		$prefix = (
			($fields->email_product ?? 'business_email') === 'enterprise_email'
		) ? 'enterpriseemail/us' : 'eelite/us';
	
		$response = $api->submit(
			$prefix . '/delete',
			[
				'order-id' => $fields->email_order_id
			],
			'POST'
		);
	
		$this->logApiResponse($api, $response);
	
		if (!$this->isSuccessfulResponse($response)) {
			$this->setModuleError(
				'cancel',
				$this->responseError($response)
			);
		}
	
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
}
