<?php

namespace Okay\Modules\Sviat\PersonalData\Entities;

use Okay\Core\Entity\Entity;
use Okay\Core\Modules\Extender\ExtenderFacade;

/** Легкий запис згоди: яка форма, який запис магазину, коли, якою мовою і з якою сторінкою політики. */
class ConsentsEntity extends Entity
{
    public const string STATE_ACTIVE = 'active';
    public const string STATE_WITHDRAWN = 'withdrawn';
    public const string STATE_ANONYMIZED = 'anonymized';

    protected static $fields = [
        'id',
        'form',
        'record_type',
        'record_id',
        'lang_id',
        'given_at',
        'policy_page_id',
        'ip',
        'withdrawn_at',
        'withdrawn_by',
        'anonymized_at',
    ];

    protected static $defaultOrderFields = ['given_at DESC', 'id DESC'];

    protected static $table = '__sviat__personal_data__consents';
    protected static $tableAlias = 'pdc';

    /**
     * Порожній список не дає жодного рядка: автофільтр ядра на `[]` зникає й віддав би всю таблицю.
     *
     * @param list<int|string> $recordIds
     */
    protected function filter__record_ids($recordIds): void
    {
        $ids = array_values(array_filter(array_map(intval(...), (array) $recordIds), static fn(int $id) => $id > 0));
        if ($ids === []) {
            $this->select->where('1 = 0');

            return;
        }
        $this->select->where('pdc.record_id IN (:pdc_record_ids)')
            ->bindValue('pdc_record_ids', $ids);
    }

    protected function filter__not_anonymized($value): void
    {
        $this->select->where('pdc.anonymized_at IS NULL');
    }

    /** @param mixed $dateTime `Y-m-d H:i:s`; інше — помилка виклику, а не фільтр, що зникає */
    protected function filter__anonymized_before($dateTime): void
    {
        $moment = is_string($dateTime) ? \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $dateTime) : false;
        if ($moment === false || $moment->format('Y-m-d H:i:s') !== $dateTime) {
            throw new \InvalidArgumentException('Consent purge filter expects Y-m-d H:i:s');
        }
        $this->select->where('pdc.anonymized_at IS NOT NULL AND pdc.anonymized_at < :pdc_anonymized_before')
            ->bindValue('pdc_anonymized_before', $dateTime);
    }

    /** Невідомий стан не дає жодного рядка: фільтр, що мовчки зникає, показав би весь журнал. */
    protected function filter__state($state): void
    {
        $condition = match ($state) {
            self::STATE_ACTIVE => 'pdc.withdrawn_at IS NULL AND pdc.anonymized_at IS NULL',
            self::STATE_WITHDRAWN => 'pdc.withdrawn_at IS NOT NULL AND pdc.anonymized_at IS NULL',
            self::STATE_ANONYMIZED => 'pdc.anonymized_at IS NOT NULL',
            default => '1 = 0',
        };
        $this->select->where($condition);
    }

    /** @param string $date `Y-m-d`, день входить у вибірку */
    protected function filter__given_from($date): void
    {
        $this->select->where('pdc.given_at >= :pdc_given_from')
            ->bindValue('pdc_given_from', self::day($date)->format('Y-m-d H:i:s'));
    }

    /** @param string $date `Y-m-d`, день входить у вибірку */
    protected function filter__given_to($date): void
    {
        $this->select->where('pdc.given_at < :pdc_given_to')
            ->bindValue('pdc_given_to', self::day($date)->modify('+1 day')->format('Y-m-d H:i:s'));
    }

    /**
     * Рядки, що в журналі стоять вище за даний: порядок журналу — `given_at DESC, id DESC`.
     *
     * @param mixed $row `[given_at у Y-m-d H:i:s, id]`; інше — помилка виклику
     */
    protected function filter__ranked_above($row): void
    {
        [$givenAt, $id] = is_array($row) && count($row) === 2 ? array_values($row) : [null, null];
        $moment = is_string($givenAt) ? \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $givenAt) : false;
        if ($moment === false || $moment->format('Y-m-d H:i:s') !== $givenAt || !is_int($id)) {
            throw new \InvalidArgumentException('Consent rank filter expects [Y-m-d H:i:s, int]');
        }
        $this->select->where('(pdc.given_at > :pdc_rank_at OR (pdc.given_at = :pdc_rank_at_same AND pdc.id > :pdc_rank_id))')
            ->bindValues(['pdc_rank_at' => $givenAt, 'pdc_rank_at_same' => $givenAt, 'pdc_rank_id' => $id]);
    }

    /**
     * Час останньої згоди кожної форми одним згрупованим запитом.
     *
     * @return array<string, string> форма => `Y-m-d H:i:s`; форм без згод у масиві немає
     */
    public function lastGivenByForm(): array
    {
        $select = $this->queryFactory->newSelect();
        $select->cols(['pdc.form', 'MAX(pdc.given_at) AS last_given'])
            ->from(self::getTable() . ' AS pdc')
            ->groupBy(['pdc.form']);
        $this->db->query($select);
        $last = [];
        foreach ($this->db->results() ?: [] as $row) {
            $last[(string) $row->form] = (string) $row->last_given;
        }

        return ExtenderFacade::execute([static::class, __FUNCTION__], $last, func_get_args());
    }

    /** Невалідна дата — помилка виклику: межа періоду, що мовчки зникає, розширила б вибірку. */
    private static function day($date): \DateTimeImmutable
    {
        $day = is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if ($day === false || $day->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Consent date filter expects Y-m-d');
        }

        return $day;
    }
}
