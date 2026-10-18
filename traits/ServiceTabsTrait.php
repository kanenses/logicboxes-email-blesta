<?php
/**
 * Methods extracted from the latest functional main implementation.
 * The methods execute in the LogicboxesEmail module context.
 */
trait ServiceTabsTrait
{

    public function getClientServiceTabs($service)
    {
        return [
            'tabEmailAccounts' => ['name' => Language::_('LogicboxesEmail.tab_email_accounts', true)],
            'tabDnsSetup' => ['name' => Language::_('LogicboxesEmail.tab_dns_setup', true)]
        ];
    }

    public function getAdminServiceTabs($service)
    {
        return [
            'tabEmailAccounts' => Language::_('LogicboxesEmail.tab_email_accounts', true),
            'tabDnsSetup' => Language::_('LogicboxesEmail.tab_dns_setup', true),
            'tabImportOrder' => Language::_('LogicboxesEmail.tab_import_order', true)
        ];
    }

    public function tabImportOrder($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
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
            $error = Language::_('LogicboxesEmail.msg.api_not_configured', true);
        } elseif (!empty($post['import_existing_order'])) {
            if ($serviceId < 1 || $orderIdInput === '' || !ctype_digit($orderIdInput)) {
                $error = Language::_('LogicboxesEmail.msg.order_id_invalid', true);
            } else {
                try {
                    $api = $this->getLogicboxesApi($row->meta->reseller_id, $row->meta->key, ($row->meta->sandbox ?? 'false') === 'true');
                    $prefix = $productInput === 'enterprise_email' ? 'enterpriseemail/us' : 'eelite/us';
                    $response = $api->submit($prefix . '/details', ['order-id' => $orderIdInput], 'GET');
                    $this->logApiResponse($api, $response);
                    if (!$this->isSuccessfulResponse($response)) {
                        $error = sprintf(Language::_('LogicboxesEmail.msg.order_not_confirmed', true), $this->responseError($response));
                    } else {
                        $details = $response->response();
                        if (is_object($details) && isset($details->response)) $details = $details->response;
                        if (is_array($details) && isset($details['response'])) $details = $details['response'];
                        if (is_object($details)) $details = (array)$details;
                        if (!is_array($details) || !$details) {
                            $error = Language::_('LogicboxesEmail.msg.order_empty', true);
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
                                // On a confirmed OrderBox order, an unreported storage add-on means
                                // there are no separately purchased email_storage_5 blocks. Keep the
                                // import usable for orders whose API details omit zero-quantity add-ons.
                                $blocksRaw = 0;
                            }
                            $productMismatch = ($productInput === 'enterprise_email' && $providerProductKey !== '' && strpos($providerProductKey, 'enterprise') === false)
                                || ($productInput === 'business_email' && $providerProductKey !== '' && strpos($providerProductKey, 'enterprise') !== false);
                            if ($productMismatch) {
                                $error = sprintf(Language::_('LogicboxesEmail.msg.order_product_mismatch', true), $providerProductKey);
                            } elseif ($domain === '') {
                                $error = Language::_('LogicboxesEmail.msg.order_domain_missing', true);
                            } elseif (!is_numeric($accountsRaw) || (int)$accountsRaw < 1) {
                                $error = Language::_('LogicboxesEmail.msg.order_accounts_missing', true);
                            } elseif (!is_numeric($blocksRaw) || (int)$blocksRaw < 0) {
                                $error = Language::_('LogicboxesEmail.msg.order_storage_missing', true);
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
                                    $error = Language::_('LogicboxesEmail.msg.order_save_failed', true);
                                    $this->logEmailDiagnostic('Import order ' . $orderIdInput . ' into Blesta service ' . $serviceId . ' had one or more editField failures.');
                                } else {
                                    // Defer reconciliation to the scheduled task; do not issue a second
                                    // OrderBox request in the same admin page request.
                                    $this->Services->editField($serviceId, [
                                        'key' => 'email_sync_pending', 'value' => '1', 'encrypted' => 'false'
                                    ]);
                                    $message = sprintf(Language::_('LogicboxesEmail.msg.order_associated', true), $orderIdInput, $serviceId);
                                }
                            }
                        }
                    }
                } catch (Throwable $e) {
                    $this->logEmailDiagnostic('Import existing OrderBox order failed for Blesta service ' . $serviceId . ': ' . $e->getMessage());
                    $error = Language::_('LogicboxesEmail.msg.order_import_failed', true);
                }
            }
        }

        $e = static function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $csrf = '';
        try { Loader::loadHelpers($this, ['Form']); $csrf = $this->Form->getCsrfToken(); } catch (Throwable $ignore) {}
        $html = '<section class="logicboxes-email-import"><h3>' . Language::_('LogicboxesEmail.import.title', true) . '</h3>';
        $html .= '<p>' . Language::_('LogicboxesEmail.import.description', true) . '</p>';
        if ($message) $html .= '<div class="alert alert-success">' . $e($message) . '</div>';
        if ($error) $html .= '<div class="alert alert-danger">' . $e($error) . '</div>';
        $html .= '<form method="post">';
        $html .= '<input type="hidden" name="_csrf_token" value="' . $e($csrf) . '">';
        $html .= '<div class="form-group"><label for="logicboxes_email_order_id">' . Language::_('LogicboxesEmail.import.order_id', true) . '</label><input class="form-control" id="logicboxes_email_order_id" name="order_id" value="' . $e($orderIdInput) . '" required inputmode="numeric"></div>';
        $html .= '<div class="form-group"><label for="logicboxes_email_product">' . Language::_('LogicboxesEmail.import.product', true) . '</label><select class="form-control" id="logicboxes_email_product" name="email_product">';
        $html .= '<option value="business_email"' . ($productInput === 'business_email' ? ' selected' : '') . '>' . Language::_('LogicboxesEmail.business_email', true) . '</option>';
        $html .= '<option value="enterprise_email"' . ($productInput === 'enterprise_email' ? ' selected' : '') . '>' . Language::_('LogicboxesEmail.enterprise_email', true) . '</option></select></div>';
        $html .= '<button class="btn btn-primary" type="submit" name="import_existing_order" value="1">' . Language::_('LogicboxesEmail.import.button', true) . '</button></form></section>';
        return $html;
    }

    public function tabEmailAccounts($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
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
            $error = Language::_('LogicboxesEmail.msg.service_order_missing', true);
        } else {
            try {
                $api = $this->getLogicboxesApi($row->meta->reseller_id, $row->meta->key, ($row->meta->sandbox ?? 'false') === 'true');
                if (!empty($post['email_action'])) {
                    $action = (string)$post['email_action'];
                    if ($action === 'delete_licenses') {
                        $deleteCount = filter_var($post['accounts_to_delete'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                        $prefix = $product === 'enterprise_email' ? 'enterpriseemail/us' : 'eelite/us';
                        if ($deleteCount === false) {
                            $error = Language::_('LogicboxesEmail.msg.invalid_delete_count', true);
                        } else {
                            // Re-check purchased licences and active mailboxes immediately before deletion.
                            $detailsResponse = $api->submit($prefix . '/details', ['order-id' => $orderId], 'GET');
                            $usageResponse = $api->submit('mail/users/search', ['order-id' => $orderId, 'page-no' => 1], 'GET');
                            if (!$this->isSuccessfulResponse($detailsResponse) || !$this->isSuccessfulResponse($usageResponse)) {
                                $error = Language::_('LogicboxesEmail.msg.verify_licences_usage_failed', true);
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
                                    $error = Language::_('LogicboxesEmail.msg.license_count_missing', true);
                                } else {
                                    $purchasedNow = (int)$purchasedRaw;
                                    $unused = max(0, $purchasedNow - $usedMailboxes);
                                    if ($deleteCount > $unused) {
                                        $error = sprintf(Language::_('LogicboxesEmail.msg.only_unused_licenses', true), $unused, $usedMailboxes);
                                    } else {
                                        $deleteResponse = $api->submit($prefix . '/delete-email-account', [
                                            'order-id' => $orderId,
                                            'no-of-accounts' => $deleteCount
                                        ], 'POST');
                                        $this->logApiResponse($api, $deleteResponse);
                                        if ($this->isSuccessfulResponse($deleteResponse)) {
                                            $deleteData = $deleteResponse->response();
                                            $actionId = $this->firstApiValue($deleteData, ['eaqid', 'actionid', 'action-id']);
                                            $message = sprintf(Language::_('LogicboxesEmail.msg.delete_request_submitted', true), $deleteCount);
                                            if ($actionId !== '') $message .= sprintf(Language::_('LogicboxesEmail.msg.reference', true), $actionId);
                                        } else {
                                            $error = sprintf(Language::_('LogicboxesEmail.msg.license_delete_failed', true), $this->responseError($deleteResponse));
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
                            $customerDetails = $api->submit('customers/details-by-id', ['customer-id' => $customerId], 'GET');
                            $this->logApiResponse($api, $customerDetails);
                            if ($this->isSuccessfulResponse($customerDetails)) {
                                $details = $customerDetails->response();
                                $canonicalUsername = trim((string)$this->firstApiValue($details, ['username', 'customer.username', 'customer-username', 'customer_username']));
                                if ($canonicalUsername !== '') $customerUsername = $canonicalUsername;
                            }
                        }

                        if ($customerId === '' || $customerUsername === '') {
                            $error = Language::_('LogicboxesEmail.msg.customer_recovery_failed', true);
                        } elseif (!filter_var($clientIp, FILTER_VALIDATE_IP)) {
                            $error = Language::_('LogicboxesEmail.msg.client_ip_missing', true);
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
                                $error = sprintf(Language::_('LogicboxesEmail.msg.login_token_failed', true), $this->responseError($tokenResponse));
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
                                    $error = Language::_('LogicboxesEmail.msg.branding_url_missing', true);
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
                                    $message = Language::_('LogicboxesEmail.msg.panel_access_generated', true);
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
                            $error = Language::_('LogicboxesEmail.msg.invalid_mailbox_name', true);
                        } elseif (strlen($password) < 8 || $first === '' || $last === '' || !filter_var($notify, FILTER_VALIDATE_EMAIL)) {
                            $error = Language::_('LogicboxesEmail.msg.mailbox_fields_required', true);
                        } elseif (
                            (strlen($local) >= 2 && stripos($password, $local) !== false) ||
                            (strlen((string)strtok($domain, '.')) >= 2 && stripos($password, (string)strtok($domain, '.')) !== false)
                        ) {
                            $domainPrefix = (string)strtok($domain, '.');
                            $error = sprintf(Language::_('LogicboxesEmail.msg.password_contains_name', true), $local, $domainPrefix);
                        } else {
                            $countResponse = $api->submit('mail/users/search', ['order-id' => $orderId, 'page-no' => 1], 'GET');
                            if (!$this->isSuccessfulResponse($countResponse)) {
                                $error = sprintf(Language::_('LogicboxesEmail.msg.verify_mailbox_usage_failed', true), $this->responseError($countResponse));
                            } else {
                                $current = $this->collectEmailUsers($countResponse->response());
                                $mailboxCount = 0;
                                foreach ($current as $u) {
                                    $type = strtolower($this->firstApiValue($u, ['user.accounttype','accounttype','account-type','account_type']));
                                    if ($type === '' || strpos($type, 'forward') === false) $mailboxCount++;
                                }
                                if ($mailboxCount >= $maxMailboxes) {
                                    $error = sprintf(Language::_('LogicboxesEmail.msg.mailbox_limit_reached', true), $maxMailboxes);
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
                                        $message = sprintf(Language::_('LogicboxesEmail.msg.mailbox_created', true), $email);
                                    } else {
                                        $error = sprintf(Language::_('LogicboxesEmail.msg.mailbox_create_failed', true), $this->responseError($r));
                                    }
                                }
                            }
                        }
                    } elseif ($action === 'add_forward') {
                        $local = strtolower(trim((string)($post['forward_local_part'] ?? '')));
                        $targets = preg_split('/[\r\n,;]+/', (string)($post['forwards'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
                        $targets = array_values(array_unique(array_filter(array_map('trim', $targets), function ($v) { return filter_var($v, FILTER_VALIDATE_EMAIL); })));
                        if (!preg_match('/^[a-z0-9._%+\-]+$/i', $local) || $local === '' || !$targets) {
                            $error = Language::_('LogicboxesEmail.msg.forward_invalid', true);
                        } else {
                            $r = $api->submit('mail/user/add-forward-only-account', [
                                'order-id' => $orderId, 'email' => $local . '@' . $domain, 'forwards' => implode(',', $targets)
                            ], 'POST');
                            if ($this->isSuccessfulResponse($r)) $message = sprintf(Language::_('LogicboxesEmail.msg.forward_created', true), $local . '@' . $domain);
                            else $error = sprintf(Language::_('LogicboxesEmail.msg.forward_create_failed', true), $this->responseError($r));
                        }
                    } elseif ($action === 'delete_user') {
                        $email = trim((string)($post['email'] ?? ''));
                        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || substr(strrchr($email, '@'), 1) !== $domain) {
                            $error = Language::_('LogicboxesEmail.msg.email_invalid', true);
                        } else {
                            $r = $api->submit('mail/user/delete', ['order-id' => $orderId, 'email' => $email], 'POST');
                            if ($this->isSuccessfulResponse($r)) $message = sprintf(Language::_('LogicboxesEmail.msg.email_deleted', true), $email);
                            else $error = sprintf(Language::_('LogicboxesEmail.msg.delete_failed', true), $this->responseError($r));
                        }
                    }
                }
                $list = $api->submit('mail/users/search', ['order-id' => $orderId, 'page-no' => 1], 'GET');
				$this->logApiResponse($api, $list);
                if ($this->isSuccessfulResponse($list)) $users = $this->collectEmailUsers($list->response());
                else $error = $error ?: (sprintf(Language::_('LogicboxesEmail.msg.account_list_failed', true), $this->responseError($list)));
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
                $error = Language::_('LogicboxesEmail.msg.provider_unreachable', true);
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
            $output .= '<h3>' . Language::_('LogicboxesEmail.panel.title', true) . '</h3>';
            $output .= '<p>' . Language::_('LogicboxesEmail.panel.description', true) . '</p><br>';
            if ($panelLaunchUrl !== null) {
                // The public ResellerClub WHMCS module opens this exact servlet using
                // a GET URL; the service-name selects the mail hosting panel.
                // The form that requested the token opens in a new tab; send that tab straight to the panel.
                $output .= '<script>window.location.replace(' . json_encode($panelLaunchUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ');</script>';
                $output .= '<p>' . Language::_('LogicboxesEmail.panel.token_notice', true) . '</p>';
                $output .= '<a class="btn btn-primary logicboxes-email-panel-launch" href="' . $escapePanel($panelLaunchUrl) . '" target="_blank" rel="noopener noreferrer">' . Language::_('LogicboxesEmail.panel.continue', true) . '</a>';
            } else {
                $panelButtons = ['email' => Language::_('LogicboxesEmail.panel.open_email', true), 'dns' => Language::_('LogicboxesEmail.panel.manage_dns', true)];
                foreach ($panelButtons as $panelTargetKey => $panelButtonLabel) {
                    $output .= '<form method="post" target="logicboxesEmailPanel" class="logicboxes-email-panel-request" style="display:inline-block;margin-right:8px;" onsubmit="window.open(\'\', \'logicboxesEmailPanel\', \'width=900,height=650,resizable=yes,scrollbars=yes\');">';
                    $output .= '<input type="hidden" name="_csrf_token" value="' . $escapePanel($csrfPanel) . '">';
                    $output .= '<input type="hidden" name="email_action" value="manage_panel">';
                    $output .= '<input type="hidden" name="panel_target" value="' . $panelTargetKey . '">';
                    $output .= '<button type="submit" class="btn btn-primary">' . $escapePanel($panelButtonLabel) . '</button></form>';
                }
            }
            $output .= '</section>';
            $output .= <<<'HTML'
<script>
(function () {
    if (window.logicboxesEmailPanelHandlerReady) return;
    window.logicboxesEmailPanelHandlerReady = true;

    function showPopupMessage(popup, title, message) {
        try {
            popup.document.open();
            popup.document.write('<!doctype html><html lang="pt"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>KanumHost</title><style>body{margin:0;background:#f5f7fa;color:#202938;font:15px/1.5 Arial,sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center}.card{box-sizing:border-box;width:min(440px,calc(100% - 32px));padding:32px;background:#fff;border:1px solid #e3e7ed;border-radius:12px;box-shadow:0 8px 28px rgba(20,35,55,.08);text-align:center}.brand{font-weight:700;font-size:20px;color:#172b4d;margin-bottom:20px}.spinner{width:28px;height:28px;margin:0 auto 18px;border:3px solid #dce3ec;border-top-color:#2463eb;border-radius:50%;animation:spin .8s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}h1{font-size:18px;margin:0 0 10px}p{margin:0;color:#596579}button{margin-top:20px;padding:9px 16px;border:0;border-radius:6px;background:#245de8;color:white;cursor:pointer}</style></head><body><main class="card"><div class="brand">KanumHost</div><div class="spinner" id="spinner"></div><h1 id="title">A ligar ao painel…</h1><p id="message">Estamos a preparar o acesso seguro. Aguarda, por favor.</p><button id="close" type="button" hidden>Fechar janela</button></main></body></html>');
            popup.document.close();
            var ui = {
                title: popup.document.getElementById('title'),
                message: popup.document.getElementById('message'),
                spinner: popup.document.getElementById('spinner'),
                close: popup.document.getElementById('close')
            };
            if (ui.title) ui.title.textContent = title;
            if (ui.message) ui.message.textContent = message;
            return ui;
        } catch (error) {
            return null;
        }
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || !form.matches || !form.matches('form.logicboxes-email-panel-request')) return;
        if (!window.fetch || !window.FormData || !window.DOMParser) return;

        event.preventDefault();
        var popup = window.open('', 'logicboxesEmailPanel', 'width=900,height=650,resizable=yes,scrollbars=yes');
        if (!popup) {
            window.alert('Permite pop-ups para abrir o painel de email ou DNS.');
            return;
        }

        var ui = showPopupMessage(popup, 'A ligar ao painel…', 'Estamos a preparar o acesso seguro. Aguarda, por favor.');
        if (!ui) {
            popup.close();
            window.alert('Não foi possível abrir a janela de ligação. Tenta novamente.');
            return;
        }

        fetch(form.action || window.location.href, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        }).then(function (response) {
            return response.text().then(function (html) {
                return {ok: response.ok, status: response.status, url: response.url, html: html};
            });
        }).then(function (result) {
            var doc = new DOMParser().parseFromString(result.html, 'text/html');
            var link = doc.querySelector('a.logicboxes-email-panel-launch');
            if (result.ok && link) {
                try {
                    var destination = new URL(link.getAttribute('href'), result.url);
                    if (destination.protocol === 'https:' && destination.hostname) {
                        popup.location.replace(destination.href);
                        return;
                    }
                } catch (error) {
                    // Show a diagnostic below if the URL is malformed.
                }
            }

            var errorNode = doc.querySelector('.logicboxes-email-control-panel .alert-danger, .logicboxes-email-control-panel .alert-warning, .alert-danger');
            var message;
            if (errorNode && errorNode.textContent.trim()) {
                message = errorNode.textContent.trim();
            } else if (!result.ok) {
                message = 'O pedido ao Blesta falhou (HTTP ' + result.status + ').';
            } else if (!link) {
                message = 'O Blesta respondeu, mas não devolveu o link de acesso ao OrderBox.';
            } else {
                message = 'O Blesta devolveu um link de acesso inválido.';
            }
            ui = showPopupMessage(popup, 'Não foi possível abrir o painel', message + ' Fecha esta janela e tenta novamente.');
            if (ui) {
                ui.spinner.hidden = true;
                ui.close.hidden = false;
                ui.close.addEventListener('click', function () { popup.close(); });
            }
        }).catch(function () {
            ui = showPopupMessage(popup, 'Falha na ligação', 'O pedido falhou antes de obter o link do painel. Verifica a ligação e tenta novamente.');
            if (ui) {
                ui.spinner.hidden = true;
                ui.close.hidden = false;
                ui.close.addEventListener('click', function () { popup.close(); });
            }
        });
    });
})();
</script>
HTML;

        }

        return $output;
    }

    public function tabDnsSetup($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        $fields = $this->serviceFieldsToObject($service->fields ?? []);
        $row = $this->getModuleRow($service->module_row_id ?? null);
        $orderId = (string)($fields->email_order_id ?? '');
        $domain = $this->getEffectiveEmailDomain((string)($fields->email_domain ?? ''), $row);
        $product = (string)($fields->email_product ?? 'business_email');
        $records = [];
        $error = null;
        if (!$row || empty($row->meta->reseller_id) || empty($row->meta->key) || $orderId === '') {
            $error = Language::_('LogicboxesEmail.msg.service_order_missing', true);
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
                } else $error = sprintf(Language::_('LogicboxesEmail.msg.dns_records_failed', true), $this->responseError($r));
            } catch (Throwable $e) {
                $this->logEmailDiagnostic('DNS tab exception for service ' . (int)($service->id ?? 0) . ': ' . $e->getMessage());
                $error = Language::_('LogicboxesEmail.msg.provider_unreachable', true);
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
        $html .= '<h3>' . Language::_('LogicboxesEmail.manage.title', true) . '</h3>';
        $html .= '<div class="table-responsive"><table class="table table-striped">';
        $html .= '<tbody>';
        $html .= '<tr><th>' . Language::_('LogicboxesEmail.manage.domain', true) . '</th><td>' . $e($domain ?: '—') . '</td></tr>';
        $html .= '</tbody></table></div>';

        if (!$orderId || !$row || empty($row->meta->reseller_id) || empty($row->meta->key)) {
            $html .= '<div class="alert alert-warning">' . Language::_('LogicboxesEmail.manage.incomplete', true) . '</div>';
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
                $html .= '<div class="alert alert-warning">' . Language::_('LogicboxesEmail.manage.provider_error', true) . '</div>';
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

            $html .= '<h4>' . Language::_('LogicboxesEmail.manage.capacity_title', true) . '</h4>';
            $html .= '<div class="table-responsive"><table class="table table-striped"><tbody>';
            $html .= '<tr><th>' . Language::_('LogicboxesEmail.manage.total_accounts', true) . '</th><td>' . $e($purchasedAccounts) . '</td></tr>';
            $html .= '<tr><th>' . Language::_('LogicboxesEmail.manage.accounts_created', true) . '</th><td>' . $e($mailboxCount) . '</td></tr>';
            $html .= '<tr><th>' . Language::_('LogicboxesEmail.manage.accounts_available', true) . '</th><td>' . $e($accountsAvailable) . '</td></tr>';
            $html .= '<tr><th>' . Language::_('LogicboxesEmail.manage.forwarders_created', true) . '</th><td>' . $e($forwarderCount) . '</td></tr>';
            $html .= '<tr><th>' . Language::_('LogicboxesEmail.manage.storage_blocks_total', true) . '</th><td>' . $e($storageBlocks) . '</td></tr>';
            $html .= '<tr><th>' . Language::_('LogicboxesEmail.manage.storage_blocks_available', true) . '</th><td>' . Language::_('LogicboxesEmail.manage.storage_blocks_note', true) . '</td></tr>';
            $html .= '</tbody></table></div>';

            $html .= '<h4>' . Language::_('LogicboxesEmail.manage.accounts_title', true) . '</h4>';
            if (!$users) {
                $html .= '<p>' . Language::_('LogicboxesEmail.manage.no_accounts', true) . '</p>';
            } else {
                $html .= '<p>' . sprintf(Language::_('LogicboxesEmail.manage.accounts_count', true), count($users)) . '</p>';
                $html .= '<div class="table-responsive"><table class="table table-striped">';
                $html .= '<thead><tr><th>' . Language::_('LogicboxesEmail.manage.table_email', true) . '</th><th>' . Language::_('LogicboxesEmail.manage.table_status', true) . '</th><th>' . Language::_('LogicboxesEmail.manage.table_type', true) . '</th><th>' . Language::_('LogicboxesEmail.manage.table_usage_quota', true) . '</th></tr></thead><tbody>';
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
            $html .= '<div class="alert alert-warning">' . Language::_('LogicboxesEmail.manage.provider_error_persistent', true) . '</div>';
        }

        $html .= '<p class="small text-muted">' . Language::_('LogicboxesEmail.manage.capacity_note', true) . '</p>';
        return $html . '</section>';
    }

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

            $hasAddonsList = false;
            $matchedStorageAddon = false;

            // OrderBox /details returns addons as a list. If the list is present
            // but email_storage_5 is absent, the order has zero blocks for this add-on.
            // If the matching entry exists but its quantity is malformed, keep the
            // quantity unknown instead of silently treating it as zero.
            foreach ($node as $key => $value) {
                $normalized = strtolower(str_replace(['_', '-', ' '], '', (string)$key));
                if ($normalized === 'addons' && is_array($value)) {
                    $hasAddonsList = true;
                    foreach ($value as $addonEntry) {
                        if (is_object($addonEntry)) $addonEntry = (array)$addonEntry;
                        if (!is_array($addonEntry)) continue;
                        $addonName = $addonEntry['addon_name'] ?? $addonEntry['addon-name'] ?? $addonEntry['name'] ?? '';
                        if (strtolower(trim((string)$addonName)) !== 'email_storage_5') continue;
                        $matchedStorageAddon = true;
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

            if ($hasAddonsList && !$matchedStorageAddon) {
                return 0;
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
}
