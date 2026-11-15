<?php

namespace Okay\Modules\Sviat\PersonalData\Forms;

use Okay\Core\Entity\Entity;
use Okay\Core\EntityFactory;

/**
 * Які записи форми існують. База ковтає помилку SQL і віддає порожній список, а «нічого не знайдено»
 * тут означало б «усе видалено»; тому в запит іде контрольний MAX(id), без якого результату не вірять.
 */
final readonly class RecordLookup
{
    public function __construct(
        private RecordProbe $probe,
        private EntityFactory $entityFactory,
    ) {}

    /**
     * Наявні id серед `$ids`.
     *
     * @param list<int> $ids
     * @return list<int>
     * @throws \RuntimeException запит не повернув контрольний запис або MAX(id) не прочитано
     */
    public function existing(FormDescriptor $form, array $ids): array
    {
        $maxId = $this->probe->maxId($form);
        if ($ids === [] || $maxId <= 0) {
            return [];
        }
        $query = array_values(array_unique([...$ids, $maxId]));
        $found = $this->ids($form, ['id' => $query, 'limit' => count($query)]);
        if (!in_array($maxId, $found, true)) {
            throw new \RuntimeException('control record not returned');
        }

        return array_values(array_intersect($ids, $found));
    }

    /** @return list<int> */
    private function ids(FormDescriptor $form, array $filter): array
    {
        /** @var Entity $entity */
        $entity = $this->entityFactory->get($form->recordEntity);

        return array_map(intval(...), $entity->cols(['id'])->find($filter) ?: []);
    }
}
