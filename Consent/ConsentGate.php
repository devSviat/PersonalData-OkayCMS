<?php

namespace Okay\Modules\Sviat\PersonalData\Consent;

use Okay\Core\Request;

/** Рішення щодо відправки форми: пропустити, відмовити через згоду чи прийняти згоду. */
final readonly class ConsentGate
{
    public const string FIELD = 'sviat__personal_data__confirm';

    /** Значення поля в шаблоні чекбокса. */
    private const string CONFIRMED = '1';

    public function __construct(
        private ConsentPolicy $policy,
        private RequestState $requestState,
        private ConsentSink $sink,
        private Request $request,
        private ErrorText $errorText,
    ) {}

    /**
     * Ключ форми задає ланка, а не запит: з POST береться лише відмітка. Відмітку записують і без доказу
     * показу (наприклад, сесія скінчилася між показом і відправкою): справжню згоду не губимо.
     * Вимога вирішує лише, чи відмовити, коли відмітки немає.
     *
     * @param string|null $error помилка ядра чи попередніх ланок; null і '' — помилки немає
     * @return string|null наявна помилка як є, текст відмови без відмітки, null — відправку пропущено
     */
    public function check(?string $error, string $form): ?string
    {
        if ($error !== null && $error !== '') {
            return $error;
        }
        if (!$this->policy->applies($form)) {
            return null;
        }
        if ($this->request->post(self::FIELD) === self::CONFIRMED) {
            $this->sink->issue($form);

            return null;
        }
        if ($this->policy->isRequired($form)) {
            $this->requestState->refuse($form);

            return $this->errorText->get();
        }

        return null;
    }
}
