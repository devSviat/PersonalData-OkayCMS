<?php

namespace Okay\Modules\Sviat\PersonalData\Integration;

use Okay\Modules\Sviat\PersonalData\Consent\ConsentGate;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentRecorder;
use Okay\Modules\Sviat\PersonalData\Core\SchemaState;
use Okay\Modules\Sviat\PersonalData\Forms\FormRegistry;
use Psr\Log\LoggerInterface;

/**
 * Стабільний контракт для модулів, що самі обробляють свою форму. Усе поза Integration\ може змінитися;
 * сигнатури цього класу — ні (docs/personal-data.md).
 *
 * Брати лише коли модуль активний: class_exists(Consent::class) і Modules::isActiveModule('Sviat', 'PersonalData');
 * вимкнений модуль не реєструє сервісів. Жоден метод не кидає винятків.
 */
final readonly class Consent
{
    public function __construct(
        private SchemaState $schemaState,
        private FormRegistry $forms,
        private ConsentGate $gate,
        private ConsentRecorder $recorder,
        private LoggerInterface $logger,
    ) {}

    /**
     * Звіряє відмітку згоди у POST цього запиту. Дана згода видає квиток, який забирає link() у цьому ж запиті.
     * Збій усередині пропускає відправку: заблокувати покупця гірше, ніж не записати згоду.
     *
     * @param string $form ключ форми з фасадом: back_in_stock, parts_selection, order_return, review_request
     * @return string|null текст помилки для показу у формі; null — відправку можна продовжувати
     */
    public function check(string $form): ?string
    {
        try {
            if (!$this->schemaState->isMigrated() || !$this->isIntegrationForm($form)) {
                return null;
            }

            return $this->gate->check(null, $form);
        } catch (\Throwable $e) {
            $this->logger->warning('Sviat/PersonalData: consent check failed: form=' . self::safeKey($form) . ', ' . $e::class);

            return null;
        }
    }

    /**
     * Прив'язує згоду з check() цього запиту до запису, який модуль щойно створив. Запис має бути новішим
     * за згоду; наявний запис (`$existing`) приймається лише формою з existingMatcher, і відкликану згоду цього
     * запису повтор відновлює. Без квитка нічого не робить мовчки; інші порушення пишуть попередження в лог.
     *
     * @param int $recordId id з результату створення запису, а не з POST
     */
    public function link(string $form, int $recordId, bool $existing = false): void
    {
        try {
            // Матчер може торкатися полів запису модуля-власника, тож без модуля до нього не доходимо.
            if (!$this->schemaState->isMigrated() || !$this->isIntegrationForm($form) || !$this->forms->isAvailable($form)) {
                return;
            }
            $this->recorder->link($form, $recordId, $existing);
        } catch (\Throwable $e) {
            $this->logger->warning('Sviat/PersonalData: consent link failed: form=' . self::safeKey($form) . ', ' . $e::class);
        }
    }

    private function isIntegrationForm(string $form): bool
    {
        if ($this->forms->get($form)?->integration === true) {
            return true;
        }
        $this->logger->warning('Sviat/PersonalData: not an integration form: form=' . self::safeKey($form));

        return false;
    }

    private static function safeKey(string $form): string
    {
        return (string) preg_replace('~[^a-z0-9_]~', '', strtolower($form));
    }
}
