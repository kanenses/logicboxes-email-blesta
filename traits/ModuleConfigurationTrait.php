<?php
/**
 * Methods extracted from the latest functional main implementation.
 * The methods execute in the LogicboxesEmail module context.
 */
trait ModuleConfigurationTrait
{

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

    private function getRowRules()
    {
        return [
            'account_name' => ['empty' => ['rule' => 'isEmpty', 'negate' => true, 'message' => 'Account Name is required.']],
            'reseller_id' => ['empty' => ['rule' => 'isEmpty', 'negate' => true, 'message' => 'Reseller ID is required.']],
            'key' => ['empty' => ['rule' => 'isEmpty', 'negate' => true, 'message' => 'API Key is required.']]
        ];
    }
}
