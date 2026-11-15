<?php

namespace Okay\Modules\Sviat\PersonalData\Backend\Controllers;

use Okay\Admin\Controllers\IndexAdmin;
use Okay\Core\Request;
use Okay\Modules\Sviat\PersonalData\Backend\Helpers\ConsentLogHelper;
use Okay\Modules\Sviat\PersonalData\Core\SchemaState;

/** «Журнал»: згоди з фільтрами, експорт CSV, відкликання й знеособлення рядка. */
class ConsentLogAdmin extends IndexAdmin
{
    private const array ACTIONS = ['withdraw' => 'withdrawn', 'anonymize' => 'anonymized'];

    public function fetch(ConsentLogHelper $consentLogHelper, SchemaState $schemaState): void
    {
        $this->design->assign('sviat_pd_tab', 'log');
        if ($schemaState->needsUpdateInAdmin()) {
            $this->design->assign('sviat_pd_needs_update', true);
            $this->response->setContent($this->design->fetch('consent_log.tpl'));
            return;
        }

        if ($this->request->method('post')) {
            if (!$this->request->checkSession()) {
                $this->design->assign('message_error', 'session');
            } elseif ($this->request->post('action') === 'export') {
                $this->sendExport($consentLogHelper);
                return;
            } else {
                $this->runAction($consentLogHelper);
                return;
            }
        }

        $filter = $consentLogHelper->filterFromRequest();
        if (isset($filter['consent_id'])) {
            // Без consent_id у адресі: пагінація ядра переносить усі параметри, і перехід на іншу сторінку
            // знову відкривав би цю. Рядок підсвічує :target.
            $this->postRedirectGet->redirect(
                Request::getRootUrl() . $this->request->url(['consent_id' => null, 'page' => $filter['page']]) . '#sviat-pd-row-' . $filter['consent_id']
            );
            return;
        }

        $total = $consentLogHelper->total($filter);
        $this->design->assign('sviat_pd_rows', $consentLogHelper->rows($filter));
        $this->design->assign('sviat_pd_total', $total);
        $this->design->assign('pages_count', (int) ceil($total / ConsentLogHelper::PER_PAGE));
        $this->design->assign('current_page', $filter['page'] ?? 1);
        $this->design->assign('sviat_pd_filtered', array_diff_key($filter, ['page' => 0, 'limit' => 0]) !== []);
        $this->design->assign('sviat_pd_values', [
            'form' => $filter['form'] ?? '',
            'from' => $filter['given_from'] ?? '',
            'to' => $filter['given_to'] ?? '',
            'lang_id' => $filter['lang_id'] ?? '',
            'state' => $filter['state'] ?? '',
            'record' => $filter['record_ids'][0] ?? '',
        ]);
        $this->design->assign('sviat_pd_forms', $consentLogHelper->formOptions());
        $this->design->assign('sviat_pd_languages', $consentLogHelper->languageOptions());
        $this->response->setContent($this->design->fetch('consent_log.tpl'));
    }

    /** PRG: після дії — редірект на ту саму адресу з фільтрами; повідомлення з сесії присвоює IndexAdmin::onInit(). */
    private function runAction(ConsentLogHelper $consentLogHelper): void
    {
        $action = $this->request->post('action');
        $id = (int) $this->request->post('id', 'integer');
        $done = match ($action) {
            'withdraw' => $consentLogHelper->withdraw($id, (string) $this->manager->login),
            'anonymize' => $consentLogHelper->anonymize($id),
            default => false,
        };

        if ($done) {
            $this->postRedirectGet->storeMessageSuccess(self::ACTIONS[$action]);
        } else {
            $this->postRedirectGet->storeMessageError('refused');
        }
        $this->postRedirectGet->redirect();
    }

    /** Фільтр експорту — той самий, що в адресі сторінки: форма експорту шле POST на поточну адресу. */
    private function sendExport(ConsentLogHelper $consentLogHelper): void
    {
        $filter = $consentLogHelper->filterFromRequest();
        $this->response->setContentType(RESPONSE_TEXT);
        $this->response->addHeader('Content-Disposition: attachment; filename="consents-' . date('Y-m-d') . '.csv"');
        $this->response->addHeader('Cache-Control: no-store');
        $this->response->sendHeaders();
        // Після sendHeaders(): адаптер відповіді додає свій Content-Type останнім.
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
        }

        foreach ($consentLogHelper->export($filter, (string) $this->manager->login) as $chunk) {
            $this->response->sendStream($chunk);
        }
    }
}
