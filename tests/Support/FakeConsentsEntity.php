<?php

namespace Modules\Sviat\PersonalData\Support;

use Okay\Modules\Sviat\PersonalData\Entities\ConsentsEntity;

/**
 * Журнал згод у пам'яті з тими самими фільтрами, що й ConsentsEntity; SQL цих фільтрів перевіряє
 * ConsentsEntityFiltersTest. Значення віддаються рядками, як їх віддає PDO, і невідомий фільтр — помилка тесту.
 */
final class FakeConsentsEntity extends ConsentsEntity
{
    private const array FILTERS = [
        'id', 'form', 'record_type', 'record_ids', 'not_anonymized', 'anonymized_before', 'limit', 'page',
        'lang_id', 'state', 'given_from', 'given_to', 'ranked_above',
    ];

    /** @var list<array<string, mixed>> фільтри кожного count */
    public array $counts = [];

    /** @var array<int, object> id => рядок */
    public array $rows = [];

    public bool $addFails = false;

    public bool $updateFails = false;

    public int $adds = 0;

    /** DELETE, який база «ковтнула»: відповідає true, але нічого не видаляє. */
    public bool $deleteSilentlyFails = false;

    /** @var list<array<string, mixed>> фільтри кожного find */
    public array $finds = [];

    /** @var list<string> */
    private array $selected = [];

    private int $nextId = 1;

    public function __construct() {}

    public function cols(array $cols)
    {
        $this->selected = $cols;

        return $this;
    }

    public function findOne(array $filter = [])
    {
        return $this->find(['limit' => 1] + $filter)[0] ?? false;
    }

    public function find(array $filter = [])
    {
        $this->finds[] = $filter;
        $unknown = array_diff(array_keys($filter), self::FILTERS);
        if ($unknown !== []) {
            throw new \LogicException('unknown consent filter: ' . implode(', ', $unknown));
        }
        $rows = $this->matching($filter);
        $limit = (int) ($filter['limit'] ?? 100);
        $rows = array_slice($rows, $limit * (max(1, (int) ($filter['page'] ?? 1)) - 1), $limit);
        $cols = $this->selected;
        $this->selected = [];

        return array_map(static function (object $row) use ($cols) {
            $out = array_map(static fn($value) => is_int($value) ? (string) $value : $value, (array) $row);
            if (count($cols) === 1) {
                return $out[$cols[0]];
            }

            return (object) ($cols === [] ? $out : array_intersect_key($out, array_flip($cols)));
        }, $rows);
    }

    public function count(array $filter = [])
    {
        $this->counts[] = $filter;
        unset($filter['page'], $filter['limit']);

        return (string) count($this->matching($filter));
    }

    /**
     * Порядок журналу: новіші спершу, за рівного часу — більший id.
     *
     * @return list<object>
     */
    private function matching(array $filter): array
    {
        $unknown = array_diff(array_keys($filter), self::FILTERS);
        if ($unknown !== []) {
            throw new \LogicException('unknown consent filter: ' . implode(', ', $unknown));
        }
        foreach (['given_from', 'given_to'] as $day) {
            $parsed = is_string($filter[$day] ?? null) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $filter[$day]) : false;
            if (isset($filter[$day]) && ($parsed === false || $parsed->format('Y-m-d') !== $filter[$day])) {
                throw new \InvalidArgumentException('Consent date filter expects Y-m-d');
            }
        }
        $rows = array_filter($this->rows, static function (object $row) use ($filter): bool {
            foreach (['id', 'form', 'record_type', 'lang_id'] as $field) {
                if (array_key_exists($field, $filter) && !in_array((string) $row->$field, array_map(strval(...), (array) $filter[$field]), true)) {
                    return false;
                }
            }
            $state = match (true) {
                $row->anonymized_at !== null => ConsentsEntity::STATE_ANONYMIZED,
                $row->withdrawn_at !== null => ConsentsEntity::STATE_WITHDRAWN,
                default => ConsentsEntity::STATE_ACTIVE,
            };
            [$aboveAt, $aboveId] = $filter['ranked_above'] ?? [null, null];

            return (!isset($filter['record_ids']) || in_array($row->record_id, array_map(intval(...), $filter['record_ids']), true))
                && (!isset($filter['not_anonymized']) || $row->anonymized_at === null)
                && (!isset($filter['anonymized_before']) || ($row->anonymized_at !== null && $row->anonymized_at < $filter['anonymized_before']))
                && (!isset($filter['state']) || $filter['state'] === $state)
                && (!isset($filter['given_from']) || $row->given_at >= $filter['given_from'] . ' 00:00:00')
                && (!isset($filter['given_to']) || $row->given_at <= $filter['given_to'] . ' 23:59:59')
                && ($aboveAt === null || $row->given_at > $aboveAt || ($row->given_at === $aboveAt && $row->id > $aboveId));
        });
        usort($rows, static fn(object $a, object $b) => [$b->given_at, $b->id] <=> [$a->given_at, $a->id]);

        return $rows;
    }

    public function add($object)
    {
        $object = (array) $object;
        $this->adds++;
        if ($this->addFails) {
            return false;
        }
        foreach ($this->rows as $row) {
            if ($object['record_id'] !== null && $row->form === $object['form'] && $row->record_type === $object['record_type'] && $row->record_id === $object['record_id']) {
                return false;
            }
        }
        $id = $this->nextId++;
        $this->rows[$id] = (object) (['id' => $id, 'withdrawn_at' => null, 'withdrawn_by' => null, 'anonymized_at' => null] + $object);

        return $id;
    }

    public function update($ids, $object)
    {
        if ($this->updateFails) {
            return false;
        }
        foreach ((array) $ids as $id) {
            if (isset($this->rows[(int) $id])) {
                $this->rows[(int) $id] = (object) ((array) $object + (array) $this->rows[(int) $id]);
            }
        }

        return true;
    }

    public function delete($ids)
    {
        if (!$this->deleteSilentlyFails) {
            foreach ((array) $ids as $id) {
                unset($this->rows[(int) $id]);
            }
        }

        return true;
    }

    /** Рядок, як його лишила б попередня прив'язка чи знеособлення. */
    public function seed(array $row): int
    {
        $id = $this->nextId++;
        $this->rows[$id] = (object) ($row + [
            'id' => $id, 'form' => 'feedback', 'record_type' => 'feedback', 'record_id' => null, 'lang_id' => 1,
            'given_at' => '2026-11-01 10:00:00', 'policy_page_id' => null, 'ip' => null, 'withdrawn_at' => null, 'withdrawn_by' => null, 'anonymized_at' => null,
        ]);

        return $id;
    }
}
