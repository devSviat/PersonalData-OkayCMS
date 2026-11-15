<?php

namespace Okay\Modules\Sviat\PersonalData\Backend\Controllers;

use Okay\Admin\Controllers\IndexAdmin;
use Okay\Modules\Sviat\PersonalData\Backend\Helpers\BackendSettingsHelper;
use Okay\Modules\Sviat\PersonalData\Core\SchemaState;

/** Сторінка модуля: форми й налаштування. */
class DescriptionAdmin extends IndexAdmin
{
    public function fetch(BackendSettingsHelper $backendSettingsHelper, SchemaState $schemaState): void
    {
        $this->design->assign('sviat_pd_tab', 'forms');
        // До «Оновити» нових таблиць ще може не бути: сторінка показує лише банер і не читає їх.
        if ($schemaState->needsUpdateInAdmin()) {
            $this->design->assign('sviat_pd_needs_update', true);
            $this->response->setContent($this->design->fetch('description.tpl'));
            return;
        }

        if ($this->request->method('post')) {
            if ($this->request->checkSession()) {
                $backendSettingsHelper->save();
                $this->design->assign('message_success', 'saved');
            } else {
                $this->design->assign('message_error', 'session');
            }
        }

        $this->design->assign('sviat_pd_groups', $backendSettingsHelper->formRows());
        $this->design->assign('sviat_pd_retention', $backendSettingsHelper->retentionYears());
        $this->design->assign('sviat_pd_policy_page_id', $backendSettingsHelper->policyPageId());
        $this->design->assign('sviat_pd_pages', $backendSettingsHelper->policyPages());
        $this->response->setContent($this->design->fetch('description.tpl'));
    }
}
