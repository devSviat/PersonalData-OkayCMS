<?php

namespace Okay\Modules\Sviat\PersonalData\Backend\Helpers;

use Okay\Core\BackendTranslations;
use Okay\Core\EntityFactory;
use Okay\Core\Languages;
use Okay\Core\Modules\Extender\ExtenderFacade;
use Okay\Core\Request;
use Okay\Entities\PagesEntity;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentRecorder;
use Okay\Modules\Sviat\PersonalData\Entities\ConsentsEntity;
use Okay\Modules\Sviat\PersonalData\Forms\FormRegistry;
use Psr\Log\LoggerInterface;

/**
 * Журнал згод: фільтри з білого списку, сторінка рядків із замаскованим IP, експорт CSV і дії менеджера.
 * IP повністю є лише в експорті; у лог іде тільки технічний запис про сам експорт.
 */
class ConsentLogHelper
{
    public const int PER_PAGE = 50;
    public const int EXPORT_BATCH = 500;

    private const array ENTITY_KEYS = ['form', 'given_from', 'given_to', 'lang_id', 'state', 'record_ids'];
    private const array STATES = [ConsentsEntity::STATE_ACTIVE, ConsentsEntity::STATE_WITHDRAWN, ConsentsEntity::STATE_ANONYMIZED];
    /** Записи, що мають власну сторінку в адмінці; звернення, дзвінки й відгуки живуть лише списками. */
    private const array RECORD_CONTROLLERS = ['order' => 'OrderAdmin', 'user' => 'UserAdmin'];

    public function __construct(
        private readonly Request $request,
        private readonly FormRegistry $forms,
        private readonly EntityFactory $entityFactory,
        private readonly Languages $languages,
        private readonly BackendTranslations $translations,
        private readonly ConsentRecorder $recorder,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Фільтр журналу з GET. Лише відомі ключі й типи; невалідне значення зникає ще до Entity, бо її фільтри
     * дат кидають виняток. Мова й стан діють лише в межах періоду «з … по»: без нього вони зникають.
     * `consent_id` (посилання з бейджа) відкидає решту фільтрів і дає сторінку, де стоїть цей рядок.
     *
     * @return array<string, mixed> ключі Entity, а також `page`, `limit` і, можливо, `consent_id`
     */
    public function filterFromRequest(): array
    {
        $filter = $this->consentIdFilter() ?? $this->listFilter();

        return ExtenderFacade::execute(__METHOD__, $filter, func_get_args());
    }

    public function total(array $filter): int
    {
        return ExtenderFacade::execute(__METHOD__, (int) $this->consents()->count(self::entityFilter($filter)), func_get_args());
    }

    /**
     * Рядки сторінки журналу для шаблону.
     *
     * @return list<array{id: int, form: string, formTitle: string, recordId: int|null, recordUrl: string|null, lang: string,
     *     givenAt: string, policyPageId: int|null, policyPageName: string|null, policyPageUrl: string|null, ip: string,
     *     state: string, withdrawnAt: string|null, withdrawnBy: string|null, anonymizedAt: string|null}>
     */
    public function rows(array $filter): array
    {
        $found = $this->consents()->find(self::entityFilter($filter) + [
            'page' => max(1, (int) ($filter['page'] ?? 1)),
            'limit' => self::PER_PAGE,
        ]) ?: [];
        $pages = $this->policyPages($found);
        $languages = $this->languageOptions();

        $rows = [];
        foreach ($found as $row) {
            $recordId = $row->record_id === null ? null : (int) $row->record_id;
            $pageId = $row->policy_page_id === null ? null : (int) $row->policy_page_id;
            $controller = self::RECORD_CONTROLLERS[(string) $row->record_type] ?? null;
            $rows[] = [
                'id' => (int) $row->id,
                'form' => (string) $row->form,
                'formTitle' => $this->formTitle((string) $row->form),
                'recordId' => $recordId,
                'recordUrl' => $recordId !== null && $controller !== null ? self::adminUrl($controller, $recordId) : null,
                'lang' => $languages[(int) $row->lang_id] ?? '',
                'givenAt' => (string) $row->given_at,
                'policyPageId' => $pageId,
                'policyPageName' => $pageId !== null ? ($pages[$pageId] ?? null) : null,
                'policyPageUrl' => $pageId !== null && isset($pages[$pageId]) ? self::adminUrl('PageAdmin', $pageId) : null,
                'ip' => self::maskIp($row->ip),
                'state' => self::state($row),
                'withdrawnAt' => $row->withdrawn_at,
                'withdrawnBy' => $row->withdrawn_by,
                'anonymizedAt' => $row->anonymized_at,
            ];
        }

        return ExtenderFacade::execute(__METHOD__, $rows, func_get_args());
    }

    /**
     * CSV журналу за фільтром: UTF-8 з BOM, комірки без формул, повний IP, партіями. Після останньої партії
     * в лог іде один рядок: хто, з яким фільтром і скільки рядків, без вмісту.
     *
     * @return iterable<string> шматки файлу
     */
    public function export(array $filter, string $manager): iterable
    {
        return ExtenderFacade::execute(__METHOD__, $this->exportChunks(self::entityFilter($filter), $manager), func_get_args());
    }

    /** Позначає активну згоду відкликаною; запис магазину не змінюється. */
    public function withdraw(int $id, string $manager): bool
    {
        $row = $id > 0 ? $this->consents()->findOne(['id' => $id]) : false;
        $done = is_object($row) && self::state($row) === ConsentsEntity::STATE_ACTIVE
            && (bool) $this->consents()->update([$id], [
                'withdrawn_at' => date('Y-m-d H:i:s'),
                'withdrawn_by' => mb_substr($manager, 0, 64),
            ]);

        return ExtenderFacade::execute(__METHOD__, $done, func_get_args());
    }

    /** Знеособлює рядок, ще не знеособлений: IP і зв'язок із записом стираються безповоротно. */
    public function anonymize(int $id): bool
    {
        $row = $id > 0 ? $this->consents()->findOne(['id' => $id]) : false;
        $done = is_object($row) && $row->anonymized_at === null && $this->recorder->anonymizeRow($id);

        return ExtenderFacade::execute(__METHOD__, $done, func_get_args());
    }

    /** @return array<string, string> ключ форми => назва */
    public function formOptions(): array
    {
        $options = [];
        foreach ($this->forms->all() as $form) {
            $options[$form->key] = $this->formTitle($form->key);
        }

        return ExtenderFacade::execute(__METHOD__, $options, func_get_args());
    }

    /** @return array<int, string> id мови => назва */
    public function languageOptions(): array
    {
        $options = [];
        foreach ($this->languages->getAllLanguages() ?: [] as $language) {
            $options[(int) $language->id] = (string) $language->name;
        }

        return ExtenderFacade::execute(__METHOD__, $options, func_get_args());
    }

    /** IPv4 без останнього октету, IPv6 до /48: досить, щоб відрізнити мережі, і замало, щоб упізнати людину. */
    public static function maskIp(mixed $ip): string
    {
        $packed = is_string($ip) ? @inet_pton($ip) : false;
        if ($packed === false) {
            return '';
        }
        if (strlen($packed) === 4) {
            return implode('.', array_slice(explode('.', (string) inet_ntop($packed)), 0, 3)) . '.x';
        }

        return inet_ntop(substr($packed, 0, 6) . str_repeat("\0", 10)) . '/48';
    }

    /** @return array<string, mixed>|null */
    private function consentIdFilter(): ?array
    {
        $id = self::positiveInt($this->request->get('consent_id'));
        $row = $id !== null ? $this->consents()->findOne(['id' => $id]) : false;
        if (!is_object($row)) {
            return null;
        }
        $above = (int) $this->consents()->count(['ranked_above' => [(string) $row->given_at, $id]]);

        return ['consent_id' => $id, 'page' => intdiv($above, self::PER_PAGE) + 1, 'limit' => self::PER_PAGE];
    }

    /** @return array<string, mixed> */
    private function listFilter(): array
    {
        $filter = [];
        $form = $this->request->get('form');
        if (is_string($form) && $this->forms->get($form) !== null) {
            $filter['form'] = $form;
        }
        $from = self::day($this->request->get('from'));
        $to = self::day($this->request->get('to'));
        if ($from !== null) {
            $filter['given_from'] = $from;
        }
        if ($to !== null) {
            $filter['given_to'] = $to;
        }
        if ($from !== null && $to !== null) {
            $langId = self::positiveInt($this->request->get('lang_id'));
            if ($langId !== null && isset($this->languageOptions()[$langId])) {
                $filter['lang_id'] = $langId;
            }
            $state = $this->request->get('state');
            if (in_array($state, self::STATES, true)) {
                $filter['state'] = $state;
            }
        }
        $record = self::positiveInt($this->request->get('record'));
        if ($record !== null) {
            $filter['record_ids'] = [$record];
        }

        $pages = max(1, (int) ceil($this->total($filter) / self::PER_PAGE));
        $filter['page'] = min($pages, self::positiveInt($this->request->get('page')) ?? 1);
        $filter['limit'] = self::PER_PAGE;

        return $filter;
    }

    /** @return \Generator<string> */
    private function exportChunks(array $filter, string $manager): \Generator
    {
        $languages = $this->languageOptions();
        yield "\xEF\xBB\xBF" . CsvCell::line(['id', 'form', 'record_type', 'record_id', 'language', 'given_at', 'policy_page_id', 'ip', 'state', 'withdrawn_at', 'withdrawn_by', 'anonymized_at']);

        $count = 0;
        for ($page = 1; ; $page++) {
            $batch = $this->consents()->find($filter + ['page' => $page, 'limit' => self::EXPORT_BATCH]) ?: [];
            $chunk = '';
            foreach ($batch as $row) {
                $chunk .= CsvCell::line([
                    (int) $row->id, $row->form, $row->record_type, $row->record_id, $languages[(int) $row->lang_id] ?? $row->lang_id,
                    $row->given_at, $row->policy_page_id, $row->ip, self::state($row), $row->withdrawn_at, $row->withdrawn_by, $row->anonymized_at,
                ]);
            }
            $count += count($batch);
            if ($chunk !== '') {
                yield $chunk;
            }
            if (count($batch) < self::EXPORT_BATCH) {
                break;
            }
        }

        $this->logger->info(sprintf(
            'Sviat/PersonalData: consent journal exported: manager=%s, filter=%s, rows=%d',
            (string) preg_replace('~[\p{C}\s]+~u', ' ', $manager),
            json_encode($filter, JSON_UNESCAPED_UNICODE),
            $count,
        ));
    }

    /** @return array<string, mixed> лише ключі, які розуміє ConsentsEntity */
    private static function entityFilter(array $filter): array
    {
        if (isset($filter['consent_id'])) {
            return [];
        }

        return array_intersect_key($filter, array_flip(self::ENTITY_KEYS));
    }

    /**
     * Назви сторінок політики одним запитом на сторінку журналу; видаленої сторінки в масиві немає.
     *
     * @param list<object> $rows
     * @return array<int, string>
     */
    private function policyPages(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn(object $row) => (int) $row->policy_page_id, $rows))));
        if ($ids === []) {
            return [];
        }
        /** @var PagesEntity $pages */
        $pages = $this->entityFactory->get(PagesEntity::class);
        $names = [];
        foreach ($pages->find(['id' => $ids, 'limit' => count($ids)]) ?: [] as $page) {
            $names[(int) $page->id] = (string) $page->name;
        }

        return $names;
    }

    private function formTitle(string $form): string
    {
        return (string) $this->translations->getTranslation('sviat__personal_data__form_title_' . $form);
    }

    private function consents(): ConsentsEntity
    {
        return $this->entityFactory->get(ConsentsEntity::class);
    }

    private static function state(object $row): string
    {
        return match (true) {
            $row->anonymized_at !== null => ConsentsEntity::STATE_ANONYMIZED,
            $row->withdrawn_at !== null => ConsentsEntity::STATE_WITHDRAWN,
            default => ConsentsEntity::STATE_ACTIVE,
        };
    }

    /** Відносна адреса лише із запиту: працює і з `/backend/index.php`, і з `/admin`. */
    private static function adminUrl(string $controller, int $id): string
    {
        return '?' . http_build_query(['controller' => $controller, 'id' => $id]);
    }

    private static function day(mixed $value): ?string
    {
        $day = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        return $day !== false && $day->format('Y-m-d') === $value ? $value : null;
    }

    private static function positiveInt(mixed $value): ?int
    {
        return is_string($value) && preg_match('~^[1-9][0-9]{0,9}$~', $value) === 1 ? (int) $value : null;
    }
}
