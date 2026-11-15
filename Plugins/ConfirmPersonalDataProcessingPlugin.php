<?php

namespace Okay\Modules\Sviat\PersonalData\Plugins;

use Okay\Core\Design;
use Okay\Core\FrontTranslations;
use Okay\Core\Languages;
use Okay\Core\SmartyPlugins\Func;
use Okay\Modules\Sviat\PersonalData\Consent\ErrorText;
use Okay\Modules\Sviat\PersonalData\Consent\RenderProof;
use Okay\Modules\Sviat\PersonalData\Consent\RequestState;
use Okay\Modules\Sviat\PersonalData\Core\SchemaState;
use Okay\Modules\Sviat\PersonalData\Forms\FormRegistry;
use Okay\Modules\Sviat\PersonalData\Forms\FormSettings;
use Okay\Modules\Sviat\PersonalData\Policy\PolicyLink;

/**
 * `{confirm_personal_data_processing form="X" [instance="…"]}` — чекбокс згоди форми X.
 *
 * Порожній рядок, доки схему не оновлено, і для невідомої, вимкненої чи недоступної форми.
 * Без `instance` форма малюється один раз на запит. Мітка — з мовних файлів модуля; її кінець веде на
 * сторінку політики з налаштувань, а без видимої сторінки лишається простим текстом.
 * Показ пише в сесію доказ, а в базу — нічого.
 */
class ConfirmPersonalDataProcessingPlugin extends Func
{
    private const string TEMPLATE = 'confirm_personal_data_processing.tpl';
    // Мова магазину без мовного файла модуля не має лишити чекбокс без підпису.
    private const string BUILT_IN_AGREE = 'Погоджуюся з';
    private const string BUILT_IN_TERMS = 'умовами обробки персональних даних';

    protected $tag = 'confirm_personal_data_processing';

    /** @var array<string, true> */
    private array $renderedIds = [];

    public function __construct(
        private readonly Design $design,
        private readonly SchemaState $schemaState,
        private readonly FormRegistry $forms,
        private readonly FormSettings $formSettings,
        private readonly Languages $languages,
        private readonly FrontTranslations $translations,
        private readonly RenderProof $proof,
        private readonly RequestState $requestState,
        private readonly ErrorText $errorText,
    ) {}

    /** @param array<string, mixed> $params */
    public function run(array $params, $smarty = null): string
    {
        // Плагін реєструється й до «Оновити», а згода до нього ніде не діє.
        if (!$this->schemaState->isMigrated()) {
            return '';
        }
        $key = is_string($params['form'] ?? null) ? $params['form'] : '';
        $form = $this->forms->get($key);
        if ($form === null || !$this->formSettings->isEnabled($key) || !$this->forms->isAvailable($key)) {
            return '';
        }

        $id = 'sviat-pd-' . $key . self::instanceSuffix($params['instance'] ?? null);
        if (isset($this->renderedIds[$id])) {
            return '';
        }

        $this->renderedIds[$id] = true;
        $policyPageId = $this->formSettings->policyPageId();
        $policyHref = PolicyLink::href($policyPageId, $this->visiblePages());
        $this->proof->record($key, (int) $this->languages->getLangId(), $policyHref === null ? null : $policyPageId);

        $this->design->assign('sviat_pd', [
            'id' => $id,
            'agree' => $this->translate('sviat__personal_data__agree') ?: self::BUILT_IN_AGREE,
            'terms' => $this->translate('sviat__personal_data__terms') ?: self::BUILT_IN_TERMS,
            'policy_href' => $policyHref,
            'error' => $this->errorText->get(),
            'refused' => $this->requestState->isRefused($key),
        ]);

        return $this->design->fetch(self::TEMPLATE);
    }

    /** Частина id з `instance`: лише символи, безпечні в id і селекторі; порожньо — без суфікса. */
    private static function instanceSuffix(mixed $instance): string
    {
        if (!is_scalar($instance)) {
            return '';
        }
        $instance = trim((string) preg_replace('~[^A-Za-z0-9_-]+~', '-', (string) $instance), '-');

        return $instance === '' ? '' : '-' . $instance;
    }

    private function translate(string $key): string
    {
        $value = $this->translations->getTranslation($key);

        return is_string($value) ? $value : '';
    }

    /** @return iterable<mixed>|null */
    private function visiblePages(): ?iterable
    {
        $pages = $this->design->getVar('pages');

        return is_iterable($pages) ? $pages : null;
    }
}
