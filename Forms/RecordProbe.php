<?php

namespace Okay\Modules\Sviat\PersonalData\Forms;

use Okay\Core\Database;
use Okay\Core\Entity\Entity;
use Okay\Core\QueryFactory;

/** Єдине місце, де модуль читає MAX(id) таблиці запису форми. */
final readonly class RecordProbe
{
    public function __construct(
        private Database $database,
        private QueryFactory $queryFactory,
    ) {}

    /**
     * Найбільший id запису форми; 0, якщо таблиця порожня або модуля-власника немає.
     *
     * @throws \RuntimeException запит не вдався: 0 тут означав би «усі наявні записи мали б мати згоду»
     */
    public function maxId(FormDescriptor $form): int
    {
        $entity = $form->recordEntity;
        if (!class_exists($entity) || !is_subclass_of($entity, Entity::class)) {
            return 0;
        }
        $query = $this->queryFactory->newSqlQuery()
            ->setStatement('SELECT MAX(id) AS max_id FROM ' . $entity::getTable());
        if (!$this->database->query($query)) {
            throw new \RuntimeException("Sviat/PersonalData: MAX(id) query failed for {$form->key}");
        }

        return (int) $this->database->result('max_id');
    }
}
