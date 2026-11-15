<?php

namespace Okay\Modules\Sviat\PersonalData\Forms\Adapters;

use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentGate;
use Okay\Modules\Sviat\PersonalData\Forms\FormSettings;

/**
 * Згода в кошику: ланка ValidateHelper::getCartSecurityError. Помилку цієї перевірки ядро показує
 * раніше за помилку контактів і жодна ланка її не знімає, тож згода не залежить від модуля, що робить
 * email необов'язковим, і від порядку ланок.
 */
final readonly class CartAdapter implements ExtensionInterface
{
    private const string FORM = 'cart';

    public function __construct(
        private ConsentGate $gate,
        private FormSettings $settings,
    ) {}

    public function isActive(): bool
    {
        return $this->settings->isEnabled(self::FORM);
    }

    public function extendGetCartSecurityError(?string $error): ?string
    {
        return $this->gate->check($error, self::FORM);
    }
}
