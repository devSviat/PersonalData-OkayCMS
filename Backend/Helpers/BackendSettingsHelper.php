<?php

namespace Okay\Modules\Sviat\PersonalData\Backend\Helpers;

use Okay\Core\BackendTranslations;
use Okay\Core\EntityFactory;
use Okay\Core\Modules\Extender\ExtenderFacade;
use Okay\Core\Request;
use Okay\Entities\PagesEntity;
use Okay\Modules\Sviat\PersonalData\Entities\ConsentsEntity;
use Okay\Modules\Sviat\PersonalData\Forms\FormDescriptor;
use Okay\Modules\Sviat\PersonalData\Forms\FormRegistry;
use Okay\Modules\Sviat\PersonalData\Forms\FormSettings;

/** Сторінка модуля: які форми вимагають згоду, сторінка політики й скільки зберігати знеособлені згоди. */
class BackendSettingsHelper
{
    public function __construct(
        private readonly Request $request,
        private readonly FormRegistry $forms,
        private readonly FormSettings $formSettings,
        private readonly EntityFactory $entityFactory,
        private readonly BackendTranslations $translations,
    ) {}

    /**
     * Стан форм, сторінка політики й строк зберігання з POST. Пишуться лише ключі реєстру. Форма, чий модуль
     * зараз недоступний, лишається як була: її перемикач вимкнений і в POST не потрапляє.
     * Сторінка, якої немає в магазині, не зберігається; 0 знімає вибір.
     */
    public function save(): void
    {
        $posted = $this->request->post('forms');
        $posted = is_array($posted) ? $posted : [];
        foreach ($this->forms->all() as $form) {
            if (!$this->forms->isAvailable($form->key)) {
                continue;
            }
            if (!empty($posted[$form->key])) {
                $this->formSettings->enable($form->key);
            } else {
                $this->formSettings->disable($form->key);
            }
        }

        $pageId = $this->request->post('policy_page_id');
        $pageId = is_string($pageId) ? filter_var(trim($pageId), FILTER_VALIDATE_INT) : false;
        if ($pageId === 0 || (is_int($pageId) && $pageId > 0 && $this->pages()->findOne(['id' => $pageId]))) {
            $this->formSettings->setPolicyPageId($pageId);
        }

        $years = $this->request->post('retention_years');
        $years = is_string($years) ? filter_var(trim($years), FILTER_VALIDATE_INT) : false;
        if ($years !== false) {
            $this->formSettings->setRetentionYears($years);
        }

        ExtenderFacade::execute(__METHOD__, null, func_get_args());
    }

    /**
     * Рядки вкладки за групами реєстру.
     *
     * @return array<string, list<array{key: string, title: string, collects: string, enabled: bool, available: bool, requires: string|null, lastConsentAt: string|null}>>
     */
    public function formRows(): array
    {
        /** @var ConsentsEntity $consents */
        $consents = $this->entityFactory->get(ConsentsEntity::class);
        $lastGiven = $consents->lastGivenByForm();

        $groups = array_fill_keys(FormDescriptor::GROUPS, []);
        foreach ($this->forms->all() as $form) {
            $available = $this->forms->isAvailable($form->key);
            $groups[$form->group][] = [
                'key' => $form->key,
                'title' => $this->translate('sviat__personal_data__form_title_' . $form->key),
                'collects' => implode(', ', array_map(
                    fn(string $field) => $this->translate('sviat__personal_data__field_' . $field),
                    $form->collects,
                )),
                'enabled' => $available && $this->formSettings->isEnabled($form->key),
                'available' => $available,
                'requires' => $available ? null : $form->requiredModule,
                'lastConsentAt' => $lastGiven[$form->key] ?? null,
            ];
        }

        return ExtenderFacade::execute(__METHOD__, array_filter($groups), func_get_args());
    }

    public function retentionYears(): int
    {
        return ExtenderFacade::execute(__METHOD__, $this->formSettings->retentionYears(), func_get_args());
    }

    public function policyPageId(): ?int
    {
        return ExtenderFacade::execute(__METHOD__, $this->formSettings->policyPageId(), func_get_args());
    }

    /**
     * Сторінки з текстом для вибору політики: без службових (PagesEntity::getSystemPages() і їхніх
     * підшляхів на кшталт `user/login`) і без порожніх. Видимі спершу; прихована лишається з позначкою,
     * бо політику можна підготувати до публікації, а доки вона прихована, мітка згоди без посилання.
     * Вибрана сторінка показується завжди, навіть поза цим відбором.
     *
     * @return list<array{id: int, name: string, visible: bool}>
     */
    public function policyPages(): array
    {
        $selectedId = $this->formSettings->policyPageId();
        $entity = $this->pages();
        $system = array_filter($entity->getSystemPages(), static fn(string $url) => $url !== '');
        $pages = [];
        foreach ($entity->noLimit()->find() ?: [] as $page) {
            $url = (string) $page->url;
            $isSystem = $url === '' || array_any($system, static fn(string $s) => $url === $s || str_starts_with($url, $s . '/'));
            $hasContent = trim(strip_tags((string) ($page->description ?? ''))) !== '';
            if ((int) $page->id === $selectedId || (!$isSystem && $hasContent)) {
                $pages[] = ['id' => (int) $page->id, 'name' => (string) $page->name, 'visible' => (bool) $page->visible];
            }
        }
        usort($pages, static fn(array $a, array $b) => $b['visible'] <=> $a['visible']);

        return ExtenderFacade::execute(__METHOD__, $pages, func_get_args());
    }

    private function pages(): PagesEntity
    {
        return $this->entityFactory->get(PagesEntity::class);
    }

    private function translate(string $key): string
    {
        return (string) $this->translations->getTranslation($key);
    }
}
