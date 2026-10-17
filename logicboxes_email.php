<?php
/**
 * KanumHost LogicBoxes Email module.
 *
 * Blesta entry point. Feature implementations are separated into traits so
 * the required public module methods remain available on this class.
 */
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'traits' . DIRECTORY_SEPARATOR . 'ModuleConfigurationTrait.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'traits' . DIRECTORY_SEPARATOR . 'ServiceSyncTrait.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'traits' . DIRECTORY_SEPARATOR . 'ServiceTabsTrait.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'traits' . DIRECTORY_SEPARATOR . 'ServiceProvisioningTrait.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'traits' . DIRECTORY_SEPARATOR . 'LogicboxesApiSupportTrait.php';

class LogicboxesEmail extends Module
{
    use ModuleConfigurationTrait;
    use ServiceSyncTrait;
    use ServiceTabsTrait;
    use ServiceProvisioningTrait;
    use LogicboxesApiSupportTrait;

    public function __construct()
    {
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');
        Loader::loadComponents($this, ['Input']);
        Language::loadLang('logicboxes_email', null, dirname(__FILE__) . DS . 'language' . DS);
    }

    public function getName()
    {
        return Language::_('LogicboxesEmail.name', true);
    }

    public function getVersion()
    {
        return '0.6.0';
    }

    public function getAuthors()
    {
        return [['name' => 'KanumHost']];
    }
}
