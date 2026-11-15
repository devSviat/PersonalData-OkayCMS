<?php

namespace Modules\Sviat\PersonalData;

require_once __DIR__ . '/Support/ModuleTestCase.php';
require_once __DIR__ . '/Support/MigratedSettings.php';
require_once __DIR__ . '/Support/BuildsSchemaState.php';
require_once __DIR__ . '/Support/FakeDatabase.php';

use Aura\SqlQuery\QueryFactory as AuraQueryFactory;
use Modules\Sviat\PersonalData\Support\BuildsSchemaState;
use Modules\Sviat\PersonalData\Support\FakeDatabase;
use Modules\Sviat\PersonalData\Support\MigratedSettings;
use Okay\Core\BackendTranslations;
use Okay\Core\Database;
use Okay\Core\Modules\Modules;
use Okay\Core\QueryFactory;
use Okay\Entities\ModulesEntity;
use Okay\Entities\PagesEntity;
use Okay\Modules\Sviat\PersonalData\Backend\Helpers\BackendSettingsHelper;
use Okay\Modules\Sviat\PersonalData\Core\SchemaState;
use Okay\Modules\Sviat\PersonalData\Entities\ConsentsEntity;
use Okay\Modules\Sviat\PersonalData\Forms\FormDescriptor;
use Okay\Modules\Sviat\PersonalData\Forms\FormRegistry;
use Okay\Modules\Sviat\PersonalData\Forms\FormSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionProperty;
use Modules\Sviat\PersonalData\Support\ModuleTestCase;
use Modules\Sviat\PersonalData\Support\RequestStub;

/** Сторінка модуля: форми, сторінка політики й строк — що адмін може змінити і що бачить. */
class BackendSettingsTest extends ModuleTestCase
{
    use BuildsSchemaState;

    private const string MODULE = __DIR__ . '/../../../../Okay/Modules/Sviat/PersonalData';

    private const array ROW_FIELDS = ['key', 'title', 'collects', 'enabled', 'available', 'requires', 'lastConsentAt'];

    /** @var list<object> сторінки магазину для PagesEntity */
    private array $pageRows = [];

    public function testSaveWritesOnlyRegistryKeys(): void
    {
        $settings = new MigratedSettings();
        $before = $settings->values;

        $this->formsHelper($settings, ['forms' => ['cart' => '1', 'evil' => '1', 'sviat__personal_data__schema' => '1'], 'retention_years' => '4'])->save();

        $written = array_diff_key($settings->values, $before);
        ksort($written);
        self::assertSame([
            'sviat__personal_data__form_cart' => 1,
            'sviat__personal_data__form_feedback' => 0,
            'sviat__personal_data__retention_years' => 4,
        ], $written);
        self::assertSame($before[SchemaState::MARKER_KEY], $settings->values[SchemaState::MARKER_KEY]);
    }

    public function testUnavailableFormCannotBeEnabled(): void
    {
        $settings = new MigratedSettings();

        $this->formsHelper($settings, ['forms' => ['parts_selection' => '1', 'cart' => '1']])->save();

        self::assertArrayNotHasKey('sviat__personal_data__form_parts_selection', $settings->values);
        self::assertSame(1, $settings->values['sviat__personal_data__form_cart']);
    }

    /** Форма, чий модуль зараз вимкнено, після збереження зберігає свій стан: вимкнений перемикач не надсилається. */
    public function testUnavailableFormKeepsItsState(): void
    {
        $settings = new MigratedSettings(['sviat__personal_data__form_parts_selection' => 1]);

        $this->formsHelper($settings, ['forms' => []])->save();

        self::assertSame(1, $settings->values['sviat__personal_data__form_parts_selection']);
    }

    /** @return array<string, array{mixed, int|null}> */
    public static function retentionInputs(): array
    {
        return [
            'нуль' => ['0', 1],
            'відʼємне' => ['-3', 1],
            'забагато' => ['25', 10],
            'у межах' => ['7', 7],
            'не число' => ['abc', null],
            'масив' => [['5'], null],
        ];
    }

    #[DataProvider('retentionInputs')]
    public function testRetentionClamped(mixed $posted, ?int $stored): void
    {
        $settings = new MigratedSettings();

        $this->formsHelper($settings, ['retention_years' => $posted])->save();

        self::assertSame($stored, $settings->values['sviat__personal_data__retention_years'] ?? null);
    }

    public function testFormRowsHaveOnlyToggleAvailabilityAndLastConsent(): void
    {
        $consents = $this->createStub(ConsentsEntity::class);
        $consents->method('lastGivenByForm')->willReturn(['cart' => '2026-11-14 09:30:00']);
        $settings = new MigratedSettings(['sviat__personal_data__form_cart' => 1]);

        $groups = $this->formsHelper($settings, [], $consents)->formRows();

        self::assertSame(['orders', 'requests'], array_keys($groups));
        foreach ($groups as $rows) {
            foreach ($rows as $row) {
                self::assertSame(self::ROW_FIELDS, array_keys($row));
            }
        }
        [$cart] = $groups['orders'];
        self::assertSame(['key' => 'cart', 'title' => 'T:sviat__personal_data__form_title_cart',
            'collects' => 'T:sviat__personal_data__field_name, T:sviat__personal_data__field_phone',
            'enabled' => true, 'available' => true, 'requires' => null, 'lastConsentAt' => '2026-11-14 09:30:00'], $cart);
        $parts = $groups['requests'][1];
        self::assertSame(['parts_selection', false, false, 'Sviat/PartsSelection', null],
            [$parts['key'], $parts['enabled'], $parts['available'], $parts['requires'], $parts['lastConsentAt']]);
    }

    public function testFormRowsOneQueryForLastConsent(): void
    {
        $consents = $this->createMock(ConsentsEntity::class);
        $consents->expects(self::once())->method('lastGivenByForm')->willReturn([]);
        $consents->expects(self::never())->method('find');
        $consents->expects(self::never())->method('count');

        $this->formsHelper(new MigratedSettings(), [], $consents)->formRows();
    }

    public function testLastGivenByFormIsOneGroupedQuery(): void
    {
        $database = new FakeDatabase();
        $this->replaceServiceInContainer(Database::class, $database);
        $entity = new ReflectionClass(ConsentsEntity::class)->newInstanceWithoutConstructor();
        new ReflectionProperty($entity, 'db')->setValue($entity, $database);
        new ReflectionProperty($entity, 'queryFactory')->setValue($entity, new QueryFactory(new AuraQueryFactory('mysql')));

        $entity->lastGivenByForm();

        self::assertCount(1, $database->statements);
        $sql = (string) preg_replace('~\s+~', ' ', str_replace('`', '', $database->statements[0]));
        self::assertStringContainsString('MAX(pdc.given_at)', $sql);
        self::assertStringContainsString('GROUP BY pdc.form', $sql);
    }

    /** @return array<string, array{mixed, int|null}> що прийшло в POST і що лягло в налаштування */
    public static function policyPageInputs(): array
    {
        return [
            'наявна сторінка' => ['6', 6],
            'зняти вибір' => ['0', 0],
            'немає такої сторінки' => ['404', null],
            'відʼємне' => ['-6', null],
            'не число' => ['6abc', null],
            'масив' => [['6'], null],
            'не надіслано' => [null, null],
        ];
    }

    #[DataProvider('policyPageInputs')]
    public function testPolicyPageSavedOnlyWhenItExists(mixed $posted, ?int $stored): void
    {
        $this->pageRows = [self::page(6, 'privacy-policy', 1, '<p>Політика</p>')];
        $settings = new MigratedSettings(['sviat__personal_data__policy_page_id' => 8]);
        $post = $posted === null ? [] : ['policy_page_id' => $posted];

        $this->formsHelper($settings, $post)->save();

        self::assertSame($stored ?? 8, $settings->values['sviat__personal_data__policy_page_id']);
        self::assertSame($stored === 0 ? null : ($stored ?? 8), new FormSettings($settings)->policyPageId());
    }

    /** Службові й порожні сторінки політикою не бувають; збережений вибір не зникає з переліку. */
    public function testPolicyPagesOnlyContentPagesVisibleFirst(): void
    {
        $this->pageRows = [
            self::page(1, '', 1, '<p>Головна</p>'),
            self::page(2, 'offer-draft', 0, '<p>Чернетка</p>'),
            self::page(3, 'user/login', 1, '<p>Вхід</p>'),
            self::page(4, '404', 1, '<p>Немає</p>'),
            self::page(5, 'faq', 1, ''),
            self::page(6, 'privacy-policy', 1, '<p>Політика</p>'),
            self::page(7, 'wishlist', 1, '<p> </p>'),
            self::page(8, 'delivery', 1, '<p>Доставка</p>'),
        ];
        $ids = static fn(array $pages) => array_map(static fn(array $p) => [$p['id'], $p['visible']], $pages);

        self::assertSame([[6, true], [8, true], [2, false]], $ids($this->formsHelper(new MigratedSettings(), [])->policyPages()));

        $selected = new MigratedSettings(['sviat__personal_data__policy_page_id' => 3]);
        self::assertSame([[3, true], [6, true], [8, true], [2, false]], $ids($this->formsHelper($selected, [])->policyPages()));
    }

    /** Кожен ключ `$btr->sviat__…` із шаблону сторінки є в усіх трьох мовах адмінки. */
    public function testEveryAdminStringExistsInEachLanguage(): void
    {
        $keys = [];
        foreach (glob(self::MODULE . '/Backend/design/html/*.tpl') ?: [] as $file) {
            preg_match_all('~\$btr->(sviat__personal_data__\w+)~', (string) file_get_contents($file), $m);
            $keys = [...$keys, ...$m[1]];
        }
        foreach (FormDescriptor::GROUPS as $group) {
            $keys[] = "sviat__personal_data__group_$group";
        }
        // Ключі, які шаблони журналу й бейджа складають зі стану, дії чи коду повідомлення.
        foreach (['active', 'withdrawn', 'anonymized'] as $state) {
            $keys[] = "sviat__personal_data__state_$state";
        }
        foreach (['active', 'withdrawn'] as $state) {
            $keys[] = "sviat__personal_data__badge_$state";
        }
        foreach (['withdrawn', 'anonymized', 'refused', 'session'] as $flash) {
            $keys[] = "sviat__personal_data__flash_$flash";
        }
        foreach (['withdraw', 'anonymize'] as $action) {
            foreach (['title', 'text', 'yes'] as $part) {
                $keys[] = "sviat__personal_data__confirm_{$action}_$part";
            }
        }
        self::assertContains('sviat__personal_data__policy_page', $keys);

        foreach (['ua', 'en', 'ru'] as $label) {
            $lang = [];
            include self::MODULE . "/Backend/lang/$label.php";
            foreach (array_unique($keys) as $key) {
                self::assertNotSame('', trim((string) ($lang[$key] ?? '')), "$label: $key");
            }
        }
    }

    /** @return array<string, array{MigratedSettings, string, list<string>, bool}> */
    public static function adminStates(): array
    {
        $tables = ['ok_sviat__personal_data__consents'];

        return [
            'немає маркера' => [MigratedSettings::pending(), '1.1.0', $tables, true],
            'версія модуля нижча' => [new MigratedSettings(), '1.0.1', $tables, true],
            'немає таблиці' => [new MigratedSettings(), '1.1.0', [], true],
            'усе оновлено' => [new MigratedSettings(), '1.1.0', $tables, false],
            'новіша версія' => [new MigratedSettings(), '1.2.0', $tables, false],
        ];
    }

    /** @param list<string> $tables */
    #[DataProvider('adminStates')]
    public function testBannerWhenMarkerMissingOrVersionLowOrTablesMissing(MigratedSettings $settings, string $version, array $tables, bool $banner): void
    {
        $database = new FakeDatabase();
        $database->tables = $tables;
        $modules = $this->createStub(ModulesEntity::class);
        $modules->method('getByVendorModuleName')->willReturn((object) ['version' => $version]);

        $state = $this->schemaState($settings, $database, $this->mockEntityFactory([ModulesEntity::class => $modules]));

        self::assertSame($banner, $state->needsUpdateInAdmin());
    }

    public function testBannerHasNoAction(): void
    {
        $banner = (string) file_get_contents(self::MODULE . '/Backend/design/html/migration_banner.tpl');

        self::assertStringContainsString('$btr->sviat__personal_data__schema_banner|escape', $banner);
        foreach (['<form', '<a', '<button', '<input', 'href', '{url'] as $action) {
            self::assertStringNotContainsString($action, $banner);
        }
        self::assertMatchesRegularExpression(
            '~\{if \$sviat_pd_needs_update\}\s*\{include file=\'migration_banner\.tpl\'\}\s*\{else\}~',
            (string) file_get_contents(self::MODULE . '/Backend/design/html/description.tpl'),
            'до оновлення лише банер',
        );
    }

    /** @param array<string, mixed> $post */
    private function formsHelper(MigratedSettings $settings, array $post, ?ConsentsEntity $consents = null): BackendSettingsHelper
    {
        $modules = $this->createStub(Modules::class);
        $modules->method('getRunningModules')->willReturn([]);
        $modules->method('isActiveModule')->willReturn(false);
        $registry = new FormRegistry(
            $modules,
            new FormDescriptor('cart', 'orders', ['name', 'phone'], 'cart.tpl', 'x', 'order', 'X', true, null, false),
            new FormDescriptor('feedback', 'requests', ['name', 'email', 'text'], 'feedback.tpl', 'x', 'feedback', 'X', true, null, false),
            new FormDescriptor('parts_selection', 'requests', ['name'], 'p.tpl', 'x', 'parts_selection', 'X', false, 'Sviat/PartsSelection', true),
        );
        if ($consents === null) {
            $consents = $this->createStub(ConsentsEntity::class);
            $consents->method('lastGivenByForm')->willReturn([]);
        }
        $translations = $this->createStub(BackendTranslations::class);
        $translations->method('getTranslation')->willReturnCallback(static fn(string $key) => "T:$key");

        $pages = $this->createStub(PagesEntity::class);
        $pages->method('findOne')->willReturnCallback(fn(array $filter) => array_find($this->pageRows, static fn(object $row) => (int) $row->id === (int) ($filter['id'] ?? 0)) ?? false);
        $pages->method('noLimit')->willReturnSelf();
        $pages->method('find')->willReturnCallback(fn() => $this->pageRows);
        $pages->method('getSystemPages')->willReturn(['', 'catalog', 'contact', 'user', '404', 'wishlist']);

        return new BackendSettingsHelper(
            new RequestStub($post),
            $registry,
            new FormSettings($settings),
            $this->mockEntityFactory([ConsentsEntity::class => $consents, PagesEntity::class => $pages]),
            $translations,
        );
    }

    private static function page(int $id, string $url, int $visible, string $description): object
    {
        return (object) ['id' => (string) $id, 'url' => $url, 'visible' => (string) $visible, 'name' => "P$id", 'description' => $description];
    }
}
