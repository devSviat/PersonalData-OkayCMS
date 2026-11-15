<?php

namespace Okay\Modules\Sviat\PersonalData\Scheduler;

use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\PersonalData\Entities\ConsentsEntity;
use Okay\Modules\Sviat\PersonalData\Forms\FormSettings;
use Psr\Log\LoggerInterface;

/** Видаляє знеособлені згоди, строк яких від `anonymized_at` минув. Повторний запуск нічого не ламає. */
final readonly class PurgeTask
{
    private const int BATCH = 500;

    public function __construct(
        private FormSettings $formSettings,
        private EntityFactory $entityFactory,
        private LoggerInterface $logger,
    ) {}

    /** @return int скільки рядків видалено */
    public function run(): int
    {
        $before = new \DateTimeImmutable()
            ->modify('-' . $this->formSettings->retentionYears() . ' years')
            ->format('Y-m-d H:i:s');
        /** @var ConsentsEntity $consents */
        $consents = $this->entityFactory->get(ConsentsEntity::class);
        $deleted = 0;
        $previous = [];
        do {
            $ids = array_map(intval(...), $consents->cols(['id'])->find(['anonymized_before' => $before, 'limit' => self::BATCH]) ?: []);
            if ($ids === []) {
                break;
            }
            // База ковтає помилку DELETE, і та сама партія повернулася б знову.
            if (array_intersect($ids, $previous) !== [] || !$consents->delete($ids)) {
                $this->logger->warning('Sviat/PersonalData: consent purge stopped, rows were not deleted');
                break;
            }
            $deleted += count($ids);
            $previous = $ids;
        } while (count($ids) === self::BATCH);

        return $deleted;
    }
}
