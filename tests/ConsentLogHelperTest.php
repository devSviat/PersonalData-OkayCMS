<?php

namespace Modules\Sviat\PersonalData;

require_once __DIR__ . '/Support/ModuleTestCase.php';
require_once __DIR__ . '/Support/RequestStub.php';
require_once __DIR__ . '/Support/FakeConsentsEntity.php';
require_once __DIR__ . '/Support/PrgSpy.php';
require_once __DIR__ . '/Support/BuildsMigratedSchema.php';

use Modules\Sviat\PersonalData\Support\FakeConsentsEntity;
use Modules\Sviat\PersonalData\Support\BuildsMigratedSchema;
use Modules\Sviat\PersonalData\Support\PrgSpy;
use Okay\Admin\Controllers\IndexAdmin;
use Okay\Core\BackendTranslations;
use Okay\Core\Design;
use Okay\Core\Languages;
use Okay\Core\Modules\Modules;
use Okay\Core\Response;
use Okay\Entities\PagesEntity;
use Okay\Modules\Sviat\PersonalData\Backend\Controllers\ConsentLogAdmin;
use Okay\Modules\Sviat\PersonalData\Backend\Helpers\ConsentLogHelper;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentLedger;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentRecorder;
use Okay\Modules\Sviat\PersonalData\Entities\ConsentsEntity;
use Okay\Modules\Sviat\PersonalData\Forms\FormDescriptor;
use Okay\Modules\Sviat\PersonalData\Forms\FormRegistry;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionProperty;
use Modules\Sviat\PersonalData\Support\ModuleTestCase;
use Modules\Sviat\PersonalData\Support\RequestStub;

/** Журнал згод: що адмін може відфільтрувати, що бачить, що вивантажує і які дії дозволено. */
class ConsentLogHelperTest extends ModuleTestCase
{
    use BuildsMigratedSchema;

    private const string MODULE = __DIR__ . '/../../../../Okay/Modules/Sviat/PersonalData';

    private FakeConsentsEntity $consents;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->consents = new FakeConsentsEntity();
    }

    public function testUnknownFilterKeysDropped(): void
    {
        $filter = $this->helper([
            'form' => 'cart', 'evil' => '1', 'record_type' => 'order', 'id' => '5', 'ip' => '192.0.2.10',
            'limit' => '100000', 'sort' => 'ip', 'lang_id' => '1 OR 1=1', 'state' => 'everything', 'record' => '12abc',
        ])->filterFromRequest();

        self::assertSame(['form' => 'cart', 'page' => 1, 'limit' => 50], $filter);
    }

    public function testFormMustBeFromRegistry(): void
    {
        self::assertArrayNotHasKey('form', $this->helper(['form' => "cart' OR 1=1"])->filterFromRequest());
        self::assertSame('feedback', $this->helper(['form' => 'feedback'])->filterFromRequest()['form'], 'контроль');
    }

    public function testRecordNumberIsPositiveInteger(): void
    {
        self::assertSame([12], $this->helper(['record' => '12'])->filterFromRequest()['record_ids']);
        self::assertArrayNotHasKey('record_ids', $this->helper(['record' => '-3'])->filterFromRequest());
    }

    public function testLogFiltersHaveNoIp(): void
    {
        $filter = $this->helper(['ip' => '192.0.2.10', 'given_ip' => '192.0.2.10'])->filterFromRequest();

        self::assertSame([], array_filter(array_keys($filter), static fn(string $key) => str_contains($key, 'ip')));
        $template = (string) file_get_contents(self::MODULE . '/Backend/design/html/consent_log.tpl');
        self::assertDoesNotMatchRegularExpression('~name="[^"]*ip[^"]*"~i', $template);
    }

    /** Порожнє й зіпсоване поле дати зникає ще до Entity: її фільтр кидає виняток, а сторінка не має відповісти 500. */
    public function testInvalidPeriodDroppedBeforeQuery(): void
    {
        $this->seed(3);
        $helper = $this->helper(['from' => '', 'to' => '2026-02-31', 'state' => 'active']);

        $filter = $helper->filterFromRequest();
        $rows = $helper->rows($filter);

        self::assertArrayNotHasKey('given_from', $filter);
        self::assertArrayNotHasKey('given_to', $filter);
        self::assertCount(3, $rows);
    }

    /** Контроль: той самий зіпсований день, переданий в Entity як є, дає виняток. */
    public function testControlRawBadDateThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->consents->find(['given_to' => '2026-02-31']);
    }

    public function testLangStateFiltersRequirePeriod(): void
    {
        $withoutPeriod = $this->helper(['lang_id' => '1', 'state' => 'withdrawn', 'from' => '2026-11-01'])->filterFromRequest();
        self::assertArrayNotHasKey('lang_id', $withoutPeriod);
        self::assertArrayNotHasKey('state', $withoutPeriod);

        $withPeriod = $this->helper(['lang_id' => '1', 'state' => 'withdrawn', 'from' => '2026-11-01', 'to' => '2026-11-15'])->filterFromRequest();
        self::assertSame(1, $withPeriod['lang_id']);
        self::assertSame('withdrawn', $withPeriod['state']);
        self::assertSame('2026-11-01', $withPeriod['given_from']);
        self::assertSame('2026-11-15', $withPeriod['given_to']);
    }

    public function testUnknownLanguageDropped(): void
    {
        $filter = $this->helper(['lang_id' => '9', 'from' => '2026-11-01', 'to' => '2026-11-15'])->filterFromRequest();

        self::assertArrayNotHasKey('lang_id', $filter);
    }

    public function testPageClamped(): void
    {
        $this->seed(120);

        self::assertSame(3, $this->helper(['page' => '999'])->filterFromRequest()['page']);
        self::assertSame(1, $this->helper(['page' => '-4'])->filterFromRequest()['page']);
        self::assertSame(2, $this->helper(['page' => '2'])->filterFromRequest()['page'], 'контроль');
        self::assertSame(1, $this->emptyJournalPage(), 'порожній журнал');
    }

    /** Посилання з бейджа відкриває сторінку, де стоїть рядок, без решти фільтрів. */
    public function testConsentIdOpensItsPage(): void
    {
        $ids = $this->seed(120);
        $target = $ids[110];

        $filter = $this->helper(['consent_id' => (string) $target, 'form' => 'cart', 'page' => '1'])->filterFromRequest();

        self::assertSame(['consent_id' => $target, 'page' => 3, 'limit' => 50], $filter);
        $rowIds = array_column($this->helper([])->rows($filter), 'id');
        self::assertContains($target, $rowIds);
    }

    public function testMissingConsentIdIgnored(): void
    {
        self::assertArrayNotHasKey('consent_id', $this->helper(['consent_id' => '777'])->filterFromRequest());
    }

    public function testListMasksIp(): void
    {
        $this->consents->seed(['ip' => '192.0.2.123', 'given_at' => '2026-11-03 10:00:00']);
        $this->consents->seed(['ip' => '2001:db8:abcd:12::7', 'given_at' => '2026-11-02 10:00:00']);
        $this->consents->seed(['ip' => null, 'given_at' => '2026-11-01 10:00:00']);

        $rows = $this->helper([])->rows(['page' => 1, 'limit' => 50]);

        self::assertSame(['192.0.2.x', '2001:db8:abcd::/48', ''], array_column($rows, 'ip'));
        self::assertStringNotContainsString('123', json_encode($rows));
    }

    public function testRowCarriesPolicyPageAndState(): void
    {
        $this->consents->seed(['policy_page_id' => 7, 'withdrawn_at' => '2026-11-05 12:00:00', 'withdrawn_by' => 'anna']);
        $this->consents->seed(['policy_page_id' => 99, 'given_at' => '2026-10-01 10:00:00', 'anonymized_at' => '2026-11-06 12:00:00']);

        [$withdrawn, $anonymized] = $this->helper([])->rows(['page' => 1, 'limit' => 50]);

        self::assertSame('Політика', $withdrawn['policyPageName']);
        self::assertStringContainsString('controller=PageAdmin', $withdrawn['policyPageUrl']);
        self::assertSame('withdrawn', $withdrawn['state']);
        self::assertSame('anna', $withdrawn['withdrawnBy']);
        self::assertSame(99, $anonymized['policyPageId']);
        self::assertNull($anonymized['policyPageName'], 'сторінки вже немає');
        self::assertSame('anonymized', $anonymized['state']);
        self::assertArrayNotHasKey('text_id', $withdrawn);
    }

    public function testJournalNeverTouchesConsentTexts(): void
    {
        foreach (['Backend/Helpers/ConsentLogHelper.php', 'Backend/Controllers/ConsentLogAdmin.php', 'Backend/Helpers/ConsentBadges.php', 'Backend/design/html/consent_log.tpl'] as $file) {
            $source = (string) file_get_contents(self::MODULE . '/' . $file);
            self::assertDoesNotMatchRegularExpression('~ConsentTexts|consent_texts|__texts|text_id~', $source, $file);
        }
    }

    public function testActionOnMissingOrAnonymizedRowRefused(): void
    {
        $active = $this->consents->seed([]);
        $withdrawn = $this->consents->seed(['withdrawn_at' => '2026-11-05 12:00:00', 'withdrawn_by' => 'anna']);
        $anonymized = $this->consents->seed(['anonymized_at' => '2026-11-06 12:00:00']);
        $helper = $this->helper([]);

        self::assertFalse($helper->withdraw(404, 'olena'), 'немає рядка');
        self::assertFalse($helper->withdraw($withdrawn, 'olena'), 'уже відкликано');
        self::assertFalse($helper->withdraw($anonymized, 'olena'), 'знеособлено');
        self::assertFalse($helper->anonymize(404), 'немає рядка');
        self::assertFalse($helper->anonymize($anonymized), 'уже знеособлено');
        self::assertSame('anna', $this->consents->rows[$withdrawn]->withdrawn_by, 'чуже відкликання не перезаписано');

        self::assertTrue($helper->withdraw($active, 'olena'), 'контроль: активний рядок');
        self::assertSame('olena', $this->consents->rows[$active]->withdrawn_by);
        self::assertNotNull($this->consents->rows[$active]->withdrawn_at);
        self::assertTrue($helper->anonymize($withdrawn), 'контроль: відкликаний можна знеособити');
        self::assertNull($this->consents->rows[$withdrawn]->ip);
        self::assertNull($this->consents->rows[$withdrawn]->record_id);
    }

    public function testExportStartsWithBom(): void
    {
        $this->seed(2);

        $csv = implode('', iterator_to_array($this->helper([])->export(['page' => 1, 'limit' => 50], 'olena'), false));

        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertSame(3, substr_count($csv, "\n"), 'заголовок і два рядки');
    }

    public function testExportNeutralizesFormulas(): void
    {
        $this->consents->seed(['withdrawn_at' => '2026-11-05 12:00:00', 'withdrawn_by' => '=HYPERLINK("http://x.test")']);

        $csv = implode('', iterator_to_array($this->helper([])->export([], 'olena'), false));

        self::assertStringContainsString("'=HYPERLINK", $csv);
        self::assertStringNotContainsString(';=HYPERLINK', $csv);
    }

    /** У експорті IP повний, бо це єдине місце, де він потрібен. */
    public function testExportHasFullIp(): void
    {
        $this->consents->seed(['ip' => '192.0.2.123']);

        $csv = implode('', iterator_to_array($this->helper([])->export([], 'olena'), false));

        self::assertStringContainsString('192.0.2.123', $csv);
    }

    public function testExportGoesInBatches(): void
    {
        $this->seed(1100);

        $lines = iterator_to_array($this->helper([])->export([], 'olena'), false);

        self::assertSame(1101, substr_count(implode('', $lines), "\n"), 'заголовок і 1100 рядків');
        self::assertSame([1, 2, 3], array_column(array_filter($this->consents->finds, static fn(array $f) => ($f['limit'] ?? 0) === 500), 'page'));
    }

    public function testExportEventLoggedWithoutContent(): void
    {
        $this->consents->seed(['ip' => '192.0.2.123', 'record_id' => 4242, 'withdrawn_at' => '2026-11-05 12:00:00', 'withdrawn_by' => 'anna']);
        $this->consents->seed(['ip' => '198.51.100.7', 'record_id' => 4343]);

        iterator_to_array($this->helper(['form' => 'feedback'])->export(['form' => 'feedback', 'page' => 2, 'limit' => 50], "olena\nfake line"), false);

        self::assertCount(1, $this->logged);
        $line = $this->logged[0];
        self::assertStringContainsString('olena fake line', $line);
        self::assertStringContainsString('rows=2', $line);
        self::assertStringContainsString('"form":"feedback"', $line);
        foreach (['192.0.2', '198.51', '4242', '4343', 'anna', 'page', "\n"] as $content) {
            self::assertStringNotContainsString($content, $line);
        }
    }

    public function testExportIsPostOnly(): void
    {
        $get = ['action' => 'export'];

        self::assertSame(0, $this->runController(new LogRequest(['action' => 'export'], $get, 'GET', true)), 'GET');
        self::assertSame(0, $this->runController(new LogRequest(['action' => 'export'], [], 'POST', false)), 'POST без сесії');
        self::assertSame(1, $this->runController(new LogRequest(['action' => 'export'], [], 'POST', true)), 'контроль');
    }

    private function emptyJournalPage(): int
    {
        $this->consents = new FakeConsentsEntity();

        return $this->helper(['page' => '5'])->filterFromRequest()['page'];
    }

    /** @return list<int> id рядків, від найновішого */
    private function seed(int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->consents->seed(['given_at' => date('Y-m-d H:i:s', strtotime('2026-11-01 10:00:00') - $i * 60)]);
        }

        return $ids;
    }

    /** @param array<string, mixed> $get */
    private function helper(array $get, ?RequestStub $request = null): ConsentLogHelper
    {
        $modules = $this->createStub(Modules::class);
        $modules->method('getRunningModules')->willReturn([]);
        $modules->method('isActiveModule')->willReturn(false);
        $registry = new FormRegistry(
            $modules,
            new FormDescriptor('cart', 'orders', ['name'], 'cart.tpl', 'x', 'order', 'X', true, null, false),
            new FormDescriptor('feedback', 'requests', ['name'], 'feedback.tpl', 'x', 'feedback', 'X', true, null, false),
        );
        $languages = $this->createStub(Languages::class);
        $languages->method('getAllLanguages')->willReturn([(object) ['id' => '1', 'name' => 'Українська', 'label' => 'ua']]);
        $translations = $this->createStub(BackendTranslations::class);
        $translations->method('getTranslation')->willReturnCallback(static fn(string $key) => "T:$key");
        $pages = $this->createStub(PagesEntity::class);
        $pages->method('find')->willReturnCallback(static fn(array $filter) => in_array(7, array_map(intval(...), (array) ($filter['id'] ?? [])), true)
            ? [(object) ['id' => '7', 'name' => 'Політика']]
            : []);
        $entityFactory = $this->mockEntityFactory([ConsentsEntity::class => $this->consents, PagesEntity::class => $pages]);
        $logged = &$this->logged;
        $logger = new class ($logged) extends AbstractLogger {
            /** @param list<string> $logged */
            public function __construct(private array &$logged) {}

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = $level . ' ' . $message;
            }
        };
        $request ??= new RequestStub([], $get, 'GET');
        $recorder = new ConsentRecorder(
            new ReflectionClass(ConsentLedger::class)->newInstanceWithoutConstructor(),
            $registry,
            $entityFactory,
            new NullLogger(),
            $request,
        );

        return new ConsentLogHelper($request, $registry, $entityFactory, $languages, $translations, $recorder, $logger);
    }

    /** @return int скільки разів викликано експорт */
    private function runController(LogRequest $request): int
    {
        $calls = 0;
        $helper = $this->createStub(ConsentLogHelper::class);
        $helper->method('filterFromRequest')->willReturn(['page' => 1, 'limit' => 50]);
        $helper->method('rows')->willReturn([]);
        $helper->method('export')->willReturnCallback(static function () use (&$calls): iterable {
            $calls++;

            return [];
        });
        $schema = $this->migratedSchema();

        $design = $this->createStub(Design::class);
        $design->method('fetch')->willReturn('');
        $controller = new ReflectionClass(ConsentLogAdmin::class)->newInstanceWithoutConstructor();
        $properties = [
            'design' => $design,
            'request' => $request,
            'response' => $this->createStub(Response::class),
            'postRedirectGet' => new PrgSpy(),
            'manager' => (object) ['login' => 'olena', 'permissions' => ['sviat__personal_data__log']],
        ];
        foreach ($properties as $name => $value) {
            new ReflectionProperty(IndexAdmin::class, $name)->setValue($controller, $value);
        }

        $controller->fetch($helper, $schema);

        return $calls;
    }
}

/** Запит журналу із заданим результатом перевірки сесії. */
final class LogRequest extends RequestStub
{
    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $get
     */
    public function __construct(array $post, array $get, string $method, private readonly bool $sessionValid)
    {
        parent::__construct($post, $get, $method);
    }

    public function checkSession()
    {
        return $this->sessionValid;
    }
}
