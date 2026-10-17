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
            'tabEmailAccounts' => ['name' => 'Email accounts'],
            'tabDnsSetup' => ['name' => 'DNS configuration']
        ];
    }

    public function getAdminServiceTabs($service)
    {
        return [
            'tabEmailAccounts' => 'Email accounts',
            'tabDnsSetup' => 'DNS configuration',
            'tabImportOrder' => 'Import OrderBox order'
        ];
    }

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
            $output .= '<p>Manage mailboxes, storage allocation and email settings in the provider control panel.</p><br>';
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
                    $output .= '<button type="submit" class="btn btn-primary">' . $escapePanel($panelButtonLabel) . '</button></form>';
                }
            }
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
}
