<?php

namespace Okay\Modules\Sviat\PersonalData\Forms\Adapters;

use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentGate;
use Okay\Modules\Sviat\PersonalData\Forms\FormSettings;

/** Згода в замовленні дзвінка: ланка ValidateHelper::getCallbackValidateError. */
final readonly class CallbackAdapter implements ExtensionInterface
{
    private const string FORM = 'callback';

    public function __construct(
        private ConsentGate $gate,
        private FormSettings $settings,
    ) {}

    public function isActive(): bool
    {
        return $this->settings->isEnabled(self::FORM);
    }

    public function extendGetCallbackValidateError(?string $error): ?string
    {
        return $this->gate->check($error, self::FORM);
    }
}
