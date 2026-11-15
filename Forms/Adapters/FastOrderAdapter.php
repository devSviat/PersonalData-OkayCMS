<?php

namespace Okay\Modules\Sviat\PersonalData\Forms\Adapters;

use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentGate;
use Okay\Modules\Sviat\PersonalData\Forms\FormRegistry;
use Okay\Modules\Sviat\PersonalData\Forms\FormSettings;

/**
 * Згода в «Купити в один клік»: ланка OkayCMS\FastOrder\Extenders\BackendExtender::ValidateFastOrder.
 * FastOrder віддає список помилок у JSON як є, тож відмова лягає під ключем поля чекбокса.
 */
final readonly class FastOrderAdapter implements ExtensionInterface
{
    private const string FORM = 'fast_order';

    public function __construct(
        private ConsentGate $gate,
        private FormSettings $settings,
        private FormRegistry $forms,
    ) {}

    /** Без встановленого й увімкненого FastOrder ланку не реєструємо: ядро відкидає ланку до неіснуючого методу. */
    public function isActive(): bool
    {
        return $this->settings->isEnabled(self::FORM) && $this->forms->isAvailable(self::FORM);
    }

    /**
     * @param mixed $errors помилки FastOrder; згода перевіряється лише для порожнього масиву
     * @return mixed ті самі помилки або масив з відмовою під ConsentGate::FIELD
     */
    public function extendValidateFastOrder(mixed $errors): mixed
    {
        if ($errors !== []) {
            return $errors;
        }
        $error = $this->gate->check(null, self::FORM);
        if ($error !== null) {
            $errors[ConsentGate::FIELD] = $error;
        }

        return $errors;
    }
}
