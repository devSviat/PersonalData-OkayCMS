<?php


namespace Okay\Modules\Sviat\PersonalData\Backend\Controllers;


use Okay\Admin\Controllers\IndexAdmin;
use Okay\Modules\Sviat\PersonalData\Backend\Helpers\BackendSettingsHelper;

class DescriptionAdmin extends IndexAdmin
{
    public function fetch(
        BackendSettingsHelper  $backendSettingsHelper
    )
    {
        if ($this->request->method('post')) {
            $backendSettingsHelper->updateGeneralSettings();
            $this->design->assign('message_success', 'saved');
        }
        $this->response->setContent($this->design->fetch('description.tpl'));
    }
}