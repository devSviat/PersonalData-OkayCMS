<?php

namespace Okay\Modules\Sviat\PersonalData\Extensions;

use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Entities\CallbacksEntity;
use Okay\Entities\CommentsEntity;
use Okay\Entities\FeedbacksEntity;
use Okay\Entities\OrdersEntity;
use Okay\Entities\UsersEntity;
use Okay\Modules\Sviat\InformBackInStock\Entities\InformBackInStockEntity;
use Okay\Modules\Sviat\OrderReturns\Entities\OrderReturnEntity;
use Okay\Modules\Sviat\PartsSelection\Entities\PartsSelectionEntity;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentRecorder;
use Okay\Modules\Sviat\PersonalData\Forms\FormDescriptor;
use Okay\Modules\Sviat\PersonalData\Forms\FormRegistry;
use Okay\Modules\Sviat\PersonalData\Forms\RecordLookup;
use Psr\Log\LoggerInterface;

/**
 * Черги після CRUD::delete кожної сутності запису форми: згоди видаленого запису знеособлюються.
 * Видалення повз CRUD::delete (сирий SQL) ці черги не бачать, і згоди такого запису лишаються незнеособленими.
 */
final readonly class AnonymizeExtension implements ExtensionInterface
{
    public function __construct(
        private ConsentRecorder $recorder,
        private FormRegistry $forms,
        private RecordLookup $records,
        private LoggerInterface $logger,
    ) {}

    public function ordersDeleted(mixed $result = null, mixed $ids = null): void
    {
        $this->deleted(OrdersEntity::class, $result, $ids);
    }

    public function usersDeleted(mixed $result = null, mixed $ids = null): void
    {
        $this->deleted(UsersEntity::class, $result, $ids);
    }

    public function feedbacksDeleted(mixed $result = null, mixed $ids = null): void
    {
        $this->deleted(FeedbacksEntity::class, $result, $ids);
    }

    public function callbacksDeleted(mixed $result = null, mixed $ids = null): void
    {
        $this->deleted(CallbacksEntity::class, $result, $ids);
    }

    public function commentsDeleted(mixed $result = null, mixed $ids = null): void
    {
        $this->deleted(CommentsEntity::class, $result, $ids);
    }

    public function backInStockDeleted(mixed $result = null, mixed $ids = null): void
    {
        $this->deleted(InformBackInStockEntity::class, $result, $ids);
    }

    public function partsSelectionDeleted(mixed $result = null, mixed $ids = null): void
    {
        $this->deleted(PartsSelectionEntity::class, $result, $ids);
    }

    public function orderReturnsDeleted(mixed $result = null, mixed $ids = null): void
    {
        $this->deleted(OrderReturnEntity::class, $result, $ids);
    }

    /**
     * CRUD::delete відповідає true і тоді, коли база проковтнула помилку DELETE. Знеособлення не відкотити,
     * тож знеособлюються лише записи, яких справді вже немає.
     */
    private function deleted(string $entity, mixed $result, mixed $ids): void
    {
        if (!$result) {
            return;
        }
        $ids = array_values(array_map(intval(...), array_filter(
            (array) $ids,
            static fn($id) => (is_int($id) && $id > 0) || (is_string($id) && preg_match('~^[1-9][0-9]{0,18}$~', $id)),
        )));
        /** @var array<string, FormDescriptor> $types */
        $types = [];
        foreach ($this->forms->all() as $form) {
            if ($form->recordEntity === $entity) {
                $types[$form->recordType] ??= $form;
            }
        }
        if ($ids === [] || $types === []) {
            return;
        }
        try {
            $gone = array_values(array_diff($ids, $this->records->existing(reset($types), $ids)));
        } catch (\Throwable $e) {
            $this->logger->warning('Sviat/PersonalData: deletion not confirmed, consents not anonymized: type='
                . implode(',', array_keys($types)) . ', ' . $e::class);

            return;
        }
        foreach (array_keys($types) as $type) {
            $this->recorder->anonymize($type, $gone);
        }
    }
}
