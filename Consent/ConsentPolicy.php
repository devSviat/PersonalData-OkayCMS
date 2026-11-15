<?php

namespace Okay\Modules\Sviat\PersonalData\Consent;

use Okay\Modules\Sviat\PersonalData\Core\SchemaState;
use Okay\Modules\Sviat\PersonalData\Forms\FormRegistry;
use Okay\Modules\Sviat\PersonalData\Forms\FormSettings;

/**
 * Чи діє згода у формі: схему оновлено, форма ввімкнена й доступна. Вимагається вона лише тоді, коли в цій
 * сесії сервер показав покупцеві чекбокс цієї форми; покупця, якому чекбокса не показували, згода не блокує.
 */
final readonly class ConsentPolicy
{
    public function __construct(
        private SchemaState $schemaState,
        private FormRegistry $forms,
        private FormSettings $formSettings,
        private RenderProof $proof,
    ) {}

    public function applies(string $form): bool
    {
        return $this->schemaState->isMigrated()
            && $this->formSettings->isEnabled($form)
            && $this->forms->isAvailable($form);
    }

    public function isRequired(string $form): bool
    {
        return $this->applies($form) && $this->proof->shown($form) !== null;
    }
}
