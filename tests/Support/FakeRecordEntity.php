<?php

namespace Modules\Sviat\PersonalData\Support;

use Okay\Core\Entity\Entity;

/** Таблиця запису форми в пам'яті: які id існують. Помилку SQL база ковтає, тож `fails` дає порожній результат. */
final class FakeRecordEntity extends Entity
{
    /** @var array<int, object> id => запис */
    public array $records = [];

    public bool $fails = false;

    /** @var list<array<string, mixed>> */
    public array $finds = [];

    private bool $idsOnly = false;

    /** @param list<int> $ids */
    public function __construct(array $ids = [])
    {
        foreach ($ids as $id) {
            $this->records[$id] = (object) ['id' => (string) $id];
        }
    }

    public function cols(array $cols)
    {
        $this->idsOnly = $cols === ['id'];

        return $this;
    }

    public function findOne(array $filter = [])
    {
        return $this->find(['limit' => 1] + $filter)[0] ?? false;
    }

    public function find(array $filter = [])
    {
        $this->finds[] = $filter;
        $idsOnly = $this->idsOnly;
        $this->idsOnly = false;
        if ($this->fails) {
            return [];
        }
        $unknown = array_diff(array_keys($filter), ['id', 'limit', 'type', 'has_parent']);
        if ($unknown !== []) {
            throw new \LogicException('unknown record filter: ' . implode(', ', $unknown));
        }
        $found = array_values(array_filter(
            array_intersect_key($this->records, array_flip(array_map(intval(...), (array) ($filter['id'] ?? [])))),
            static fn(object $row) => (!isset($filter['type']) || ($row->type ?? null) === $filter['type'])
                && (!array_key_exists('has_parent', $filter) || ((int) ($row->parent_id ?? 0) > 0) === !empty($filter['has_parent'])),
        ));
        $found = array_slice($found, 0, (int) ($filter['limit'] ?? 100));

        return $idsOnly ? array_map(static fn(object $row) => $row->id, $found) : array_map(static fn(object $row) => clone $row, $found);
    }
}
