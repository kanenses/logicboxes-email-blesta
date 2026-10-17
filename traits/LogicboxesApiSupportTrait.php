<?php
/**
 * Methods extracted from the latest functional main implementation.
 * The methods execute in the LogicboxesEmail module context.
 */
trait LogicboxesApiSupportTrait
{

    private function changeOrderStatus($package, $service, $action)
    {
        $fields = $this->serviceFieldsToObject($service->fields);
        if (empty($fields->email_order_id)) {
            $this->setModuleError('order_id', Language::_('LogicboxesEmail.provision.order_id_missing', true));
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
        if (is_object($error)) return (string)($error->message ?? $error->error ?? $error->actionstatusdesc ?? Language::_('LogicboxesEmail.api.generic_error', true));
        return sprintf(Language::_('LogicboxesEmail.api.invalid_response', true), substr((string)$response->raw(), 0, 500));
    }

    private function setModuleError($key, $message)
    {
        // Blesta Input errors must contain scalar message strings. Nested arrays here
        // can reach AppController::setMessage() and crash Html::safe() with a TypeError.
        $this->Input->setErrors(['module' => [$key => (string)$message]]);
    }

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

    private function getLogicboxesApi($resellerId, $key, $sandbox)
    {
        Loader::load(dirname(__DIR__) . DS . 'apis' . DS . 'logicboxes_api.php');
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
}
