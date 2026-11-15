<?php

namespace Okay\Modules\Sviat\PersonalData\Backend\Helpers;

use Okay\Core\Design;
use Okay\Core\EntityFactory;
use Okay\Core\Modules\Extender\ExtenderFacade;
use Okay\Modules\Sviat\PersonalData\Entities\ConsentsEntity;
use Okay\Modules\Sviat\PersonalData\Forms\FormRegistry;

/**
 * Бейдж згоди на сторінці запису магазину. На списку перший бейдж читає згоди всіх записів сторінки
 * одним запитом, решта бере їх із пам'яті запиту. Знеособлені рядки до записів не прив'язані й бейджа не дають.
 */
class ConsentBadges
{
    /** @var array<string, array<int, object|null>> тип запису => id запису => бейдж або null */
    private array $loaded = [];

    public function __construct(
        private readonly EntityFactory $entityFactory,
        private readonly FormRegistry $forms,
    ) {}

    /**
     * @param list<int|string> $pageIds id усіх записів поточної сторінки списку
     * @return object|null `{id, state, date, givenAt, withdrawnAt, withdrawnBy, url}`; `url` веде до рядка в журналі
     */
    public function forRecord(string $type, int $id, array $pageIds): ?object
    {
        if ($id > 0 && !array_key_exists($id, $this->loaded[$type] ?? [])) {
            $this->load($type, array_merge([$id], $pageIds));
        }

        return ExtenderFacade::execute(__METHOD__, $this->loaded[$type][$id] ?? null, func_get_args());
    }

    /**
     * Колбек блоку адмінки: бере запис і список сторінки з уже присвоєних змінних шаблону.
     * Порожній бейдж присвоюється теж, щоб рядок списку не показав бейдж попереднього.
     */
    public function assign(Design $design, string $type, string $recordVar, ?string $listVar = null): void
    {
        $record = $design->getVar($recordVar);
        $list = $listVar !== null ? $design->getVar($listVar) : null;
        $pageIds = is_iterable($list) ? self::ids($list) : [];
        $id = is_object($record) ? (int) ($record->id ?? 0) : 0;

        $design->assign('sviat_pd_badge', $id > 0 ? $this->forRecord($type, $id, $pageIds) : null);
    }

    /**
     * id записів сторінки. Список, згрупований на один рівень (заявки «Повідомити про наявність» за товаром),
     * розгортається, щоб усі бейджі сторінки лягли в одну вибірку.
     *
     * @return list<int>
     */
    private static function ids(iterable $list): array
    {
        $ids = [];
        foreach ($list as $item) {
            if (is_iterable($item)) {
                foreach ($item as $nested) {
                    $ids[] = (int) ($nested->id ?? 0);
                }
            } elseif (is_object($item)) {
                $ids[] = (int) ($item->id ?? 0);
            }
        }

        return $ids;
    }

    /** @param list<int|string> $ids */
    private function load(string $type, array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map(intval(...), $ids), fn(int $id) => $id > 0 && !array_key_exists($id, $this->loaded[$type] ?? []))));
        foreach ($ids as $id) {
            $this->loaded[$type][$id] = null;
        }

        // Форми цього типу запису: з ними вибірка йде унікальним ключем (form, record_type, record_id).
        $forms = array_values(array_map(
            static fn($form) => $form->key,
            array_filter($this->forms->all(), static fn($form) => $form->recordType === $type),
        ));
        if ($ids === [] || $forms === []) {
            return;
        }

        /** @var ConsentsEntity $consents */
        $consents = $this->entityFactory->get(ConsentsEntity::class);
        $rows = $consents->find([
            'form' => $forms,
            'record_type' => $type,
            'record_ids' => $ids,
            'not_anonymized' => 1,
            'limit' => count($ids) * count($forms),
        ]) ?: [];

        // Порядок журналу — новіші спершу, тож для запису лишається найсвіжіша згода.
        foreach ($rows as $row) {
            $recordId = (int) $row->record_id;
            $this->loaded[$type][$recordId] ??= self::badge($row);
        }
    }

    private static function badge(object $row): object
    {
        $withdrawn = $row->withdrawn_at !== null;

        return (object) [
            'id' => (int) $row->id,
            'state' => $withdrawn ? ConsentsEntity::STATE_WITHDRAWN : ConsentsEntity::STATE_ACTIVE,
            'date' => (string) ($withdrawn ? $row->withdrawn_at : $row->given_at),
            'givenAt' => (string) $row->given_at,
            'withdrawnAt' => $row->withdrawn_at,
            'withdrawnBy' => $row->withdrawn_by,
            'url' => '?' . http_build_query(['controller' => 'Sviat.PersonalData.ConsentLogAdmin', 'consent_id' => (int) $row->id]),
        ];
    }
}
