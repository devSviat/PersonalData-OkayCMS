<?php

namespace Okay\Modules\Sviat\PersonalData\Consent;

use Okay\Core\Entity\Entity;
use Okay\Core\EntityFactory;
use Okay\Core\Request;
use Okay\Modules\Sviat\PersonalData\Entities\ConsentsEntity;
use Okay\Modules\Sviat\PersonalData\Forms\FormDescriptor;
use Okay\Modules\Sviat\PersonalData\Forms\FormRegistry;
use Psr\Log\LoggerInterface;

/**
 * Легкий запис згоди після створення запису форми і його знеособлення.
 * Лог отримує лише форму, тип і id запису, а при відновленні ще час і логін менеджера, що відкликав;
 * IP і дані покупця туди не потрапляють.
 */
final readonly class ConsentRecorder
{
    public function __construct(
        private ConsentLedger $ledger,
        private FormRegistry $forms,
        private EntityFactory $entityFactory,
        private LoggerInterface $logger,
        private Request $request,
    ) {}

    /**
     * Прив'язує згоду цього запиту до запису форми. Ніколи не кидає: шов стоїть у потоці створення
     * замовлення чи відгуку, і збій запису згоди не має ламати саму форму.
     *
     * @param mixed $recordId id, який повернуло створення запису; false, '' чи 0 — запису немає
     * @param bool $existing запис не новий (повтор без нового запису); дозволено лише формі з existingMatcher
     * @return bool true — рядок згоди записано або відкликаний рядок відновлено
     */
    public function link(string $form, mixed $recordId, bool $existing = false): bool
    {
        $id = self::normalizeId($recordId);
        $type = '?';
        try {
            $descriptor = $this->forms->get($form);
            if ($descriptor === null) {
                return $this->reject('unknown form', $form, $type, $id);
            }
            $type = $descriptor->recordType;

            // Квитка немає, коли форму вимкнено або згоду не вимагали: звичайна відправка, не збій для логу.
            // Збій видачі квитка журнал логує сам.
            $ticket = $this->ledger->take($form);
            if ($ticket === null) {
                return false;
            }
            if ($id <= 0) {
                return $this->reject('no record id', $form, $type, $id);
            }
            if (!$existing && $id <= $ticket->maxRecordId) {
                return $this->reject('record older than consent', $form, $type, $id);
            }

            $record = $this->recordEntity($descriptor)?->findOne(['id' => $id]);
            if (!is_object($record)) {
                return $this->reject('record not found', $form, $type, $id);
            }
            if ($existing && ($descriptor->existingMatcher === null || ($descriptor->existingMatcher)($record, $this->request) !== true)) {
                return $this->reject('existing record not matched', $form, $type, $id);
            }

            $consents = $this->consents();
            $row = $consents->findOne(['form' => $form, 'record_type' => $type, 'record_ids' => [$id]]);
            if (is_object($row)) {
                return $existing && $row->withdrawn_at !== null && $row->anonymized_at === null
                    ? $this->restore($consents, $row, $ticket, $form, $type, $id)
                    : false;
            }

            $added = $consents->add([
                'form' => $form,
                'record_type' => $type,
                'record_id' => $id,
                'lang_id' => $ticket->langId,
                'given_at' => $ticket->givenAt,
                'policy_page_id' => $ticket->policyPageId,
                'ip' => $ticket->ip,
            ]);
            if (!$added) {
                return $this->reject('insert failed', $form, $type, $id);
            }

            return true;
        } catch (\Throwable $e) {
            return $this->reject('error ' . $e::class, $form, $type, $id);
        }
    }

    /**
     * Повторна відправка після відкликання — нова згода на той самий запис; унікальний ключ не дає другого рядка,
     * тож рядок отримує дані нової згоди. Активний рядок лишається першим доказом (T8), знеособлений не оживає.
     * Попереднє відкликання рядок втрачає, тож воно лишається в лозі: хто й коли, без даних покупця.
     */
    private function restore(ConsentsEntity $consents, object $row, ConsentTicket $ticket, string $form, string $type, int $id): bool
    {
        $restored = $consents->update([(int) $row->id], [
            'lang_id' => $ticket->langId,
            'given_at' => $ticket->givenAt,
            'policy_page_id' => $ticket->policyPageId,
            'ip' => $ticket->ip,
            'withdrawn_at' => null,
            'withdrawn_by' => null,
        ]);

        if (!$restored) {
            return $this->reject('restore failed', $form, $type, $id);
        }
        $this->logger->info(sprintf(
            'Sviat/PersonalData: withdrawn consent restored: form=%s, type=%s, id=%d, withdrawn_at=%s, withdrawn_by=%s',
            self::safeKey($form),
            $type,
            $id,
            self::logValue($row->withdrawn_at),
            self::logValue($row->withdrawn_by),
        ));

        return true;
    }

    /**
     * Знеособлює згоди видалених записів: IP і зв'язок із записом стираються, факт згоди лишається до строку.
     * Не кидає: стоїть після видалення запису, а збій лише лишає згоди незнеособленими.
     *
     * @param list<int> $ids
     */
    public function anonymize(string $recordType, array $ids): void
    {
        try {
            $ids = array_values(array_unique(array_filter($ids, static fn(int $id) => $id > 0)));
            if ($ids === []) {
                return;
            }
            $consents = $this->consents();
            $rows = $consents->cols(['id'])->find([
                'record_type' => $recordType,
                'record_ids' => $ids,
                'not_anonymized' => 1,
                'limit' => count($ids) * max(1, count($this->forms->all())),
            ]) ?: [];
            $rowIds = array_map(intval(...), $rows);
            if ($rowIds !== [] && !$consents->update($rowIds, self::anonymized())) {
                $this->logger->warning("Sviat/PersonalData: consents of deleted records not anonymized: type=$recordType");
            }
        } catch (\Throwable $e) {
            $this->logger->warning("Sviat/PersonalData: consents of deleted records not anonymized: type=$recordType, " . $e::class);
        }
    }

    /** Знеособлює один рядок журналу за його власним id; уже знеособлений чи відсутній — false. */
    public function anonymizeRow(int $consentId): bool
    {
        if ($consentId <= 0) {
            return false;
        }
        $consents = $this->consents();
        $row = $consents->findOne(['id' => $consentId]);
        if (!is_object($row) || $row->anonymized_at !== null) {
            return false;
        }

        return (bool) $consents->update([$consentId], self::anonymized());
    }

    /** @return array{ip: null, record_id: null, anonymized_at: string} */
    private static function anonymized(): array
    {
        return ['ip' => null, 'record_id' => null, 'anonymized_at' => date('Y-m-d H:i:s')];
    }

    private function recordEntity(FormDescriptor $descriptor): ?Entity
    {
        $class = $descriptor->recordEntity;
        if (!class_exists($class) || !is_subclass_of($class, Entity::class)) {
            return null;
        }

        return $this->entityFactory->get($class);
    }

    private function consents(): ConsentsEntity
    {
        return $this->entityFactory->get(ConsentsEntity::class);
    }

    private function reject(string $reason, string $form, string $type, int $id): bool
    {
        $form = self::safeKey($form);
        $this->logger->warning("Sviat/PersonalData: consent not linked ($reason): form=$form, type=$type, id=$id");

        return false;
    }

    private static function safeKey(string $form): string
    {
        return (string) preg_replace('~[^a-z0-9_]~', '', strtolower($form));
    }

    /** Значення з бази одним рядком: перевід рядка в логіні менеджера не має підробити наступний рядок лога. */
    private static function logValue(mixed $value): string
    {
        return is_scalar($value) ? (string) preg_replace('~[\p{C}\s]+~u', ' ', (string) $value) : '-';
    }

    /** Те, що не є додатним цілим, стає 0: такого запису немає. */
    private static function normalizeId(mixed $recordId): int
    {
        if (is_int($recordId)) {
            return max(0, $recordId);
        }
        if (is_string($recordId) && preg_match('~^[1-9][0-9]{0,18}$~', $recordId)) {
            return (int) $recordId;
        }

        return 0;
    }
}
