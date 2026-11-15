<?php

namespace Okay\Modules\Sviat\PersonalData\Forms\Adapters;

use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentGate;
use Okay\Modules\Sviat\PersonalData\Forms\FormSettings;

/** Згода в реєстрації: ланка ValidateHelper::getUserRegisterError. */
final readonly class RegisterAdapter implements ExtensionInterface
{
    private const string FORM = 'register';

    public function __construct(
        private ConsentGate $gate,
        private FormSettings $settings,
    ) {}

    public function isActive(): bool
    {
        return $this->settings->isEnabled(self::FORM);
    }

    public function extendGetUserRegisterError(?string $error): ?string
    {
        return $this->gate->check($error, self::FORM);
    }
}
