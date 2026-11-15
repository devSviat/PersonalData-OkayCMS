<?php

namespace Okay\Modules\Sviat\PersonalData\Forms\Adapters;

use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentGate;
use Okay\Modules\Sviat\PersonalData\Forms\FormSettings;

/** Згода у формі зв'язку: ланка ValidateHelper::getFeedbackValidateError. */
final readonly class FeedbackAdapter implements ExtensionInterface
{
    private const string FORM = 'feedback';

    public function __construct(
        private ConsentGate $gate,
        private FormSettings $settings,
    ) {}

    public function isActive(): bool
    {
        return $this->settings->isEnabled(self::FORM);
    }

    public function extendGetFeedbackValidateError(?string $error): ?string
    {
        return $this->gate->check($error, self::FORM);
    }
}
