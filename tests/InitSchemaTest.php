<?php

namespace Modules\Sviat\PersonalData;

require_once __DIR__ . '/Support/ModuleTestCase.php';
require_once __DIR__ . '/Support/BuildsSchemaState.php';
require_once __DIR__ . '/Support/FakeDatabase.php';

use Aura\SqlQuery\QueryFactory as AuraQueryFactory;
use Modules\Sviat\PersonalData\Support\BuildsSchemaState;
use Modules\Sviat\PersonalData\Support\FakeDatabase;
use Okay\Core\Config;
use Okay\Core\Database;
use Okay\Core\EntityFactory;
use Okay\Core\Languages;
use Okay\Core\Modules\AbstractInit;
use Okay\Core\Modules\DTO\ModuleParamsDTO;
use Okay\Core\Modules\EntityField;
use Okay\Core\Modules\EntityMigrator;
use Okay\Core\Modules\Installer;
use Okay\Core\Modules\Module;
use Okay\Core\Modules\Modules;
use Okay\Core\Modules\SqlPresentor;
use Okay\Core\OkayContainer\OkayContainer;
use Okay\Core\QueryFactory;
use Okay\Core\ServiceLocator;
use Okay\Core\Settings;
use Okay\Entities\ModulesEntity;
use Okay\Modules\Sviat\PersonalData\Core\SchemaState;
use Okay\Modules\Sviat\PersonalData\Entities\ConsentsEntity;
use Okay\Modules\Sviat\PersonalData\Forms\FormRegistry;
use Okay\Modules\Sviat\PersonalData\Forms\FormSettings;
use Okay\Modules\Sviat\PersonalData\Init\Init;
use Okay\Modules\Sviat\PersonalData\Init\SchemaMigration;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionProperty;
use Modules\Sviat\PersonalData\Support\ModuleTestCase;

/** Таблиці з'являються лише після «Оновити», маркер — останнім і лише коли все справді в базі. */
// Module для Installer — частковий мок: getMathVersion має лишитися справжнім.
#[AllowMockObjectsWithoutExpectations]
class InitSchemaTest extends ModuleTestCase
{
    use BuildsSchemaState;

    private const string PREFIX = 'ok_';

    /** @var list<array{0: class-string, 1: list<EntityField>}> */
    private array $created = [];

    /** @var list<string> */
    private array $log = [];

    private ?string $tempRoot = null;

    /** @var array{services: array, serviceStore: array, parameters: array}|null стан контейнера до тесту, що прив'язує сервіси модуля */
    private ?array $container = null;

    protected function tearDown(): void
    {
        if ($this->container !== null) {
            $container = self::container();
            foreach ($this->container as $name => $value) {
                new ReflectionProperty($container, $name)->setValue($container, $value);
            }
            $this->container = null;
        }
        InitWithoutCoreServices::$properties = [];
        if ($this->tempRoot !== null) {
            exec('rm -rf ' . escapeshellarg($this->tempRoot));
        }
        parent::tearDown();
    }

    public function testInstallCreatesNoTables(): void
    {
        $database = $this->database();
        $migrator = $this->createMock(EntityMigrator::class);
        $migrator->expects(self::never())->method('migrateEntityTable');
        $migrator->expects(self::never())->method('migrateCustomTable');
        $this->replaceServiceInContainer(SchemaMigration::class, $this->migration($database, $migrator));
        $init = $this->init($migrator);

        $init->install();

        self::assertSame([], $database->tables);
        self::assertArrayNotHasKey(SchemaState::MARKER_KEY, $database->settings);
    }

    public function testUpdateCreatesOnlyConsentsTable(): void
    {
        $database = $this->database();
        $this->replaceServiceInContainer(SchemaMigration::class, $this->migration($database));

        $this->init()->update_1_1_0();

        $tables = array_map(static fn(array $call) => $call[0]::getTable(), $this->created);
        self::assertSame(['__sviat__personal_data__consents'], SchemaState::TABLES);
        self::assertSame(SchemaState::TABLES, $tables);
        self::assertEqualsCanonicalizing(array_map(self::prefixed(...), SchemaState::TABLES), $database->tables);
        self::assertSame(SchemaState::MARKER_VERSION, $database->settings[SchemaState::MARKER_KEY] ?? null);
    }

    public function testConsentsHasNoUserAgentAndIpIsVarchar40(): void
    {
        $sql = $this->createSql(ConsentsEntity::class);

        self::assertDoesNotMatchRegularExpression('~user_agent|`ua`|agent~i', $sql);
        self::assertMatchesRegularExpression('~`ip` varchar\(40\)\s+NULL~', $sql);
        self::assertMatchesRegularExpression('~`record_id` int\(11\)\s+NULL~', $sql);
        self::assertMatchesRegularExpression('~`policy_page_id` int\(11\)\s+NULL~', $sql);
        self::assertMatchesRegularExpression('~`lang_id` int\(11\)\s+NOT NULL~', $sql);
        self::assertStringNotContainsString('text_id', $sql);
        self::assertEqualsCanonicalizing(ConsentsEntity::getFields(), $this->columns(ConsentsEntity::class));
    }

    public function testConsentsUniqueAndAnonymizedIndex(): void
    {
        $sql = $this->createSql(ConsentsEntity::class);

        self::assertStringContainsString('UNIQUE KEY `form_record_type_record_id` (`form`, `record_type`, `record_id`)', $sql);
        self::assertMatchesRegularExpression('~[,(] KEY `given_at` \(`given_at`\)~', $sql);
        self::assertMatchesRegularExpression('~[,(] KEY `anonymized_at` \(`anonymized_at`\)~', $sql);
    }

    public function testMarkerWrittenLast(): void
    {
        $root = $this->tempRoot();
        mkdir($root . '/compiled/theme', 0777, true);
        touch($root . '/compiled/theme/page.tpl.php');
        $database = $this->database();
        $database->onQuery = function (string $statement, array $bind) use ($root): void {
            if (str_starts_with($statement, 'INSERT INTO `__settings`')) {
                $this->log[] = $bind['param'] === SchemaState::MARKER_KEY ? 'marker' : 'setting';
                if ($bind['param'] === SchemaState::MARKER_KEY) {
                    $this->log[] = glob($root . '/compiled/*/*') === [] ? 'compiled clean' : 'compiled stale';
                }
            } elseif (preg_match('~FROM\s+`__settings`~', $statement)) {
                $this->log[] = 'reload';
            } elseif (str_contains($statement, 'information_schema')) {
                $this->log[] = 'check';
            }
        };
        $migration = $this->migration($database, rootDir: $root);
        $this->log = [];

        $migration->run();

        $marker = array_search('marker', $this->log, true);
        self::assertNotFalse($marker);
        self::assertContains('setting', $this->log);
        self::assertSame(['marker', 'compiled clean', 'reload'], array_slice($this->log, $marker), implode(' → ', $this->log));
        self::assertSame(count(SchemaState::TABLES), count(array_filter($this->log, static fn($e) => $e === 'table')));
    }

    public function testMigratedInSameRequestAfterMarker(): void
    {
        $migration = $this->migration($this->database());
        $state = new ReflectionProperty(SchemaMigration::class, 'schemaState')->getValue($migration);
        self::assertFalse($state->isMigrated());

        $migration->run();

        self::assertTrue($state->isMigrated());
    }

    public function testMarkerNotWrittenWhenTableMissing(): void
    {
        $database = $this->database();
        $database->tableQueryFails = true;
        $migration = $this->migration($database);

        try {
            $migration->run();
            self::fail('без підтверджених таблиць міграція мусить упасти');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('table', $e->getMessage());
        }

        self::assertArrayNotHasKey(SchemaState::MARKER_KEY, $database->settings);
    }

    public function testMarkerNotWrittenWhenCreateSilentlyFails(): void
    {
        $database = $this->database();
        $migration = $this->migration($database, refuse: ConsentsEntity::class);

        try {
            $migration->run();
            self::fail('таблиця, якої немає після CREATE, мусить зупинити міграцію');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('__sviat__personal_data__consents', $e->getMessage());
        }

        self::assertArrayNotHasKey(SchemaState::MARKER_KEY, $database->settings);
    }

    public function testMarkerWriteFailureThrows(): void
    {
        $database = $this->database();
        $database->rejectSettingsInsert = SchemaState::MARKER_KEY;
        $migration = $this->migration($database);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('marker');

        $migration->run();
    }

    public function testExistingTablesNotRecreated(): void
    {
        $database = $this->database();
        $database->tables = array_map(self::prefixed(...), SchemaState::TABLES);
        $migrator = $this->createMock(EntityMigrator::class);
        $migrator->expects(self::never())->method('migrateEntityTable');

        $this->migration($database, $migrator)->run();

        self::assertSame(SchemaState::MARKER_VERSION, $database->settings[SchemaState::MARKER_KEY] ?? null);
    }

    public function testUpdateIdempotentOnRerun(): void
    {
        $database = $this->database(['pd_cart' => '1', 'pd_feedback' => '0', 'pd_comment' => '1']);
        $this->replaceServiceInContainer(SchemaMigration::class, $this->migration($database));

        $this->init()->update_1_1_0();
        $this->init()->update_1_1_0();
        $this->init()->update_1_1_0();
        $first = [$database->tables, $database->settings];

        self::assertCount(count(SchemaState::TABLES), $this->created, 'повторні прогони не створюють таблиць');

        // Попередній прогін встиг створити таблицю, але не записав налаштувань.
        $partial = $this->database(['pd_cart' => '1', 'pd_feedback' => '0', 'pd_comment' => '1']);
        $partial->tables = [self::prefixed(SchemaState::TABLES[0])];
        $this->created = [];
        $this->replaceServiceInContainer(SchemaMigration::class, $this->migration($partial));

        $this->init()->update_1_1_0();

        self::assertSame([], $this->created, 'таблиця вже є');
        self::assertEqualsCanonicalizing($first[0], $partial->tables);
        self::assertEquals($first[1], $partial->settings);
        self::assertSame(SchemaState::MARKER_VERSION, $partial->settings[SchemaState::MARKER_KEY]);
        self::assertSame('1', $partial->settings['sviat__personal_data__form_cart']);
        self::assertSame('0', $partial->settings['sviat__personal_data__form_comment_post']);
        self::assertSame('1', $partial->settings['pd_comment'], 'старі ключі лишаються до 1.2.0');
    }

    public function testSettingsAlreadyWrittenAreKept(): void
    {
        $database = $this->database(['pd_cart' => '1', 'sviat__personal_data__form_cart' => '0']);

        $this->migration($database)->run();

        self::assertSame('0', $database->settings['sviat__personal_data__form_cart']);
    }

    public function testVersionNotBumpedOnFailure(): void
    {
        $database = $this->database();
        $database->tableQueryFails = true;
        $this->replaceServiceInContainer(SchemaMigration::class, $this->migration($database));
        $modules = $this->createMock(ModulesEntity::class);
        $modules->method('findOne')->willReturn($this->installedModule());
        $modules->expects(self::never())->method('update');

        $this->expectException(\RuntimeException::class);

        $this->installer($modules)->update(7);
    }

    public function testControlVersionBumpedOnSuccess(): void
    {
        $this->replaceServiceInContainer(SchemaMigration::class, $this->migration($this->database()));
        $modules = $this->createMock(ModulesEntity::class);
        $modules->method('findOne')->willReturn($this->installedModule());
        $modules->expects(self::once())->method('update')->with(7, ['version' => '1.1.0']);

        $this->installer($modules)->update(7);
    }

    public function testFreshInstallCreatesTablesAndMarker(): void
    {
        $root = $this->tempRoot();
        $database = $this->database();
        $this->snapshotContainer();
        self::container()->bindParameters(['root_dir' => $root . '/']);
        $queryFactory = new QueryFactory(new AuraQueryFactory('mysql'));
        $settings = new Settings($database, $this->createStub(Languages::class), $queryFactory);
        $modules = $this->createStub(Modules::class);
        $modules->method('isActiveModule')->willReturn(false);
        foreach ([
            Settings::class => $settings,
            EntityMigrator::class => $this->recordingMigrator($database),
            SchemaState::class => $this->schemaState($settings, $database, rootDir: $root),
            Modules::class => $modules,
            LoggerInterface::class => new NullLogger(),
            Languages::class => $this->createStub(Languages::class),
        ] as $id => $service) {
            $this->replaceServiceInContainer($id, $service);
        }
        self::assertFalse(ServiceLocator::getInstance()->hasService(SchemaMigration::class), 'сервіси модуля ще не прив\'язані');

        $modulesEntity = $this->createStub(ModulesEntity::class);
        $modulesEntity->method('cols')->willReturnSelf();
        $modulesEntity->method('find')->willReturn([]);
        $modulesEntity->method('add')->willReturn(7);
        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('get')->willReturn($modulesEntity);
        InitWithoutCoreServices::$properties = [
            'entityFactory' => $entityFactory,
            'module' => new Module(new NullLogger()),
            'moduleId' => 7,
            'vendor' => 'Sviat',
            'moduleName' => 'PersonalData',
        ];

        self::assertSame(7, $this->installer($modulesEntity)->install('Sviat/PersonalData'));

        self::assertEqualsCanonicalizing(array_map(self::prefixed(...), SchemaState::TABLES), $database->tables);
        self::assertSame(SchemaState::MARKER_VERSION, $database->settings[SchemaState::MARKER_KEY] ?? null);
        self::assertSame('0', $database->settings['sviat__personal_data__form_cart'] ?? null, 'на чистому встановленні форми вимкнені');
        self::assertSame([], preg_grep('~__texts$|_text_id_~', [...$database->tables, ...array_keys($database->settings)]), 'таблиці й налаштувань версій тексту немає');
    }

    /** «Оновити» не читає таблиць записів форм, тож їхній стан міграцію не зриває. */
    public function testMigrationReadsNoRecordTables(): void
    {
        $database = $this->database(['pd_cart' => '1']);
        $database->maxIdQueryFails = true;

        $this->migration($database)->run();

        self::assertSame(SchemaState::MARKER_VERSION, $database->settings[SchemaState::MARKER_KEY] ?? null);
        self::assertSame('1', $database->settings['sviat__personal_data__form_cart']);
        self::assertSame([], preg_grep('~_since_id$~', array_keys($database->settings)));
    }

    public function testLostFormSettingThrowsBeforeMarker(): void
    {
        $database = $this->database(['pd_cart' => '1']);
        $database->rejectSettingsInsert = 'sviat__personal_data__form_feedback';

        try {
            $this->migration($database)->run();
            self::fail('втрачений запис налаштування форми мусить зупинити міграцію');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('sviat__personal_data__form_feedback', $e->getMessage());
        }

        self::assertArrayNotHasKey(SchemaState::MARKER_KEY, $database->settings);
    }

    /** @return iterable<string, array{\Closure(FakeDatabase): void, ?string, string}> */
    public static function failures(): iterable
    {
        yield 'таблиця не створилась' => [static fn() => null, ConsentsEntity::class, 'table(s) not created: __sviat__personal_data__consents'];
        yield 'налаштування форми' => [static fn(FakeDatabase $db) => $db->rejectSettingsInsert = 'sviat__personal_data__form_feedback', null, 'settings not saved: sviat__personal_data__form_feedback'];
        yield 'маркер' => [static fn(FakeDatabase $db) => $db->rejectSettingsInsert = SchemaState::MARKER_KEY, null, 'schema marker was not saved'];
    }

    /** Ядро показує причину лише у flash-повідомленні, тож у лозі застосунку вона мусить лишитися окремо. */
    #[DataProvider('failures')]
    public function testFailureReasonLoggedOnce(\Closure $break, ?string $refuse, string $reason): void
    {
        $database = $this->database(['pd_cart' => '1']);
        $break($database);
        $logged = [];
        $migration = $this->migration($database, refuse: $refuse, logger: $this->recordingLogger($logged));

        try {
            $migration->run();
            self::fail('міграція мусить упасти');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($reason, $e->getMessage());
        }

        self::assertSame(["error Sviat/PersonalData: migration to 1.1.0 stopped: $reason"], $logged);
    }

    public function testSuccessfulMigrationLogsNoError(): void
    {
        $logged = [];
        $this->migration($this->database(['pd_cart' => '1']), logger: $this->recordingLogger($logged))->run();

        self::assertSame([], $logged);
    }

    /** @param list<string> $logged */
    private function recordingLogger(array &$logged): LoggerInterface
    {
        return new class ($logged) extends AbstractLogger {
            /** @param list<string> $logged */
            public function __construct(private array &$logged) {}

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = "$level $message";
            }
        };
    }

    public function testLeftoverCompiledFilesLoggedOnceWithCount(): void
    {
        if (!function_exists('posix_geteuid') || !function_exists('posix_seteuid')) {
            self::markTestSkipped('posix недоступне: перевірку прав від імені іншого користувача не зробити');
        }
        $root = $this->tempRoot();
        mkdir($root . '/compiled/locked', 0777, true);
        touch($root . '/compiled/locked/one.tpl.php');
        touch($root . '/compiled/locked/two.tpl.php');
        chmod($root . '/compiled/locked', 0555);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')
            ->with(self::logicalAnd(self::stringContains('2 compiled'), self::logicalNot(self::stringContains($root))));
        $migration = $this->migration($this->database(), rootDir: $root, logger: $logger);

        // root обходить права каталогу, тож прибирання йде від імені непривілейованого користувача.
        $asRoot = posix_geteuid() === 0;
        if ($asRoot) {
            posix_seteuid(65534);
        }
        try {
            $migration->run();
        } finally {
            if ($asRoot) {
                posix_seteuid(0);
            }
            chmod($root . '/compiled/locked', 0777);
        }

        self::assertCount(2, glob($root . '/compiled/locked/*'));
    }

    public function testCompiledClearedForAllThemes(): void
    {
        $root = $this->tempRoot();
        foreach (['a', 'b'] as $theme) {
            mkdir($root . '/compiled/' . $theme, 0777, true);
            touch($root . '/compiled/' . $theme . '/one.tpl.php');
            touch($root . '/compiled/' . $theme . '/two.tpl.php');
        }
        touch($root . '/compiled/.keep_folder');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $this->migration($this->database(), rootDir: $root, logger: $logger)->run();

        self::assertSame([], glob($root . '/compiled/a/*'));
        self::assertSame([], glob($root . '/compiled/b/*'));
        self::assertDirectoryExists($root . '/compiled/a');
        self::assertFileExists($root . '/compiled/.keep_folder');
    }

    /** @param array<string, string> $settings */
    private function database(array $settings = []): FakeDatabase
    {
        $database = new FakeDatabase($settings);
        $database->maxIds = ['__orders' => 900, '__feedbacks' => 7, '__comments' => 300, '__users' => 40, '__callbacks' => 12];
        // Запити ядра (Insert, Select) беруть Database з контейнера ще в конструкторі.
        $this->replaceServiceInContainer(Database::class, $database);

        return $database;
    }

    private function migration(
        FakeDatabase $database,
        ?EntityMigrator $migrator = null,
        ?string $rootDir = null,
        ?string $refuse = null,
        ?LoggerInterface $logger = null,
    ): SchemaMigration {
        $migrator ??= $this->recordingMigrator($database, $refuse);
        $queryFactory = new QueryFactory(new AuraQueryFactory('mysql'));
        $settings = new Settings($database, $this->createStub(Languages::class), $queryFactory);
        $modules = $this->createStub(Modules::class);
        $modules->method('isActiveModule')->willReturn(false);
        $services = require dirname(__DIR__, 4) . '/Okay/Modules/Sviat/PersonalData/Init/services.php';
        $registry = new FormRegistry($modules, ...array_slice($services[FormRegistry::class]['arguments'], 1));
        $rootDir ??= $this->tempRoot();

        return new SchemaMigration(
            $migrator,
            $this->schemaState($settings, $database, rootDir: $rootDir),
            $settings,
            $registry,
            new FormSettings($settings),
            $logger ?? new NullLogger(),
            $rootDir,
        );
    }

    /** «Створює» таблицю у фейковій базі, крім `$refuse`: так виглядає CREATE, що мовчки не вдався. */
    private function recordingMigrator(FakeDatabase $database, ?string $refuse = null): EntityMigrator
    {
        $migrator = $this->createStub(EntityMigrator::class);
        $migrator->method('migrateEntityTable')->willReturnCallback(
            function (string $entity, array $fields) use ($database, $refuse): void {
                $this->created[] = [$entity, $fields];
                $this->log[] = 'table';
                if ($entity !== $refuse) {
                    $database->tables[] = self::prefixed($entity::getTable());
                }
            }
        );

        return $migrator;
    }

    private function snapshotContainer(): void
    {
        $container = self::container();
        foreach (['services', 'serviceStore', 'parameters'] as $name) {
            $this->container[$name] = new ReflectionProperty($container, $name)->getValue($container);
        }
    }

    private static function container(): OkayContainer
    {
        return new ReflectionProperty(ServiceLocator::class, 'DI')->getValue(ServiceLocator::getInstance());
    }

    private function createSql(string $entity): string
    {
        $this->migration($this->database())->run();
        $fields = array_find($this->created, static fn(array $call) => $call[0] === $entity)[1] ?? [];
        self::assertNotSame([], $fields, $entity);

        return new SqlPresentor()->createTableQuery($entity::getTable(), $fields);
    }

    /** @return list<string> */
    private function columns(string $entity): array
    {
        $fields = array_find($this->created, static fn(array $call) => $call[0] === $entity)[1] ?? [];

        return array_map(static fn(EntityField $field) => $field->getName(), $fields);
    }

    private function init(?EntityMigrator $migrator = null): Init
    {
        $init = new ReflectionClass(Init::class)->newInstanceWithoutConstructor();
        $modulesEntity = $this->createStub(ModulesEntity::class);
        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('get')->willReturn($modulesEntity);
        $properties = [
            'entityFactory' => $entityFactory,
            'entityMigrator' => $migrator ?? $this->createStub(EntityMigrator::class),
            'module' => new Module(new NullLogger()),
            'moduleId' => 7,
            'vendor' => 'Sviat',
            'moduleName' => 'PersonalData',
        ];
        foreach ($properties as $name => $value) {
            new ReflectionProperty(AbstractInit::class, $name)->setValue($init, $value);
        }

        return $init;
    }

    private function installedModule(): object
    {
        return (object) ['id' => 7, 'vendor' => 'Sviat', 'module_name' => 'PersonalData', 'version' => '1.0.1'];
    }

    private function installer(ModulesEntity $modules): Installer
    {
        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('get')->willReturn($modules);
        $module = $this->getMockBuilder(Module::class)
            ->setConstructorArgs([new NullLogger()])
            ->onlyMethods(['getModuleParams', 'getInitClassName'])
            ->getMock();
        $params = $this->createStub(ModuleParamsDTO::class);
        $params->method('getVersion')->willReturn('1.1.0');
        $params->method('getMathVersion')->willReturn($module->getMathVersion('1.1.0'));
        $module->method('getModuleParams')->willReturn($params);
        $module->method('getInitClassName')->willReturn(InitWithoutCoreServices::class);

        return new Installer($entityFactory, $module, $this->bindingModules());
    }

    /**
     * Справжній Modules::bindModuleServices, яким ядро прив'язує сервіси модуля перед install()/update_*;
     * інші залежності Modules тут не потрібні.
     */
    private function bindingModules(): Modules
    {
        if ($this->container === null) {
            $this->snapshotContainer();
        }
        $modules = new ReflectionClass(Modules::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(Modules::class, 'module')->setValue($modules, new Module(new NullLogger()));
        new ReflectionProperty(Modules::class, 'config')->setValue($modules, $this->createStub(Config::class));

        return $modules;
    }

    private static function prefixed(string $table): string
    {
        return self::PREFIX . substr($table, 2);
    }

    private function tempRoot(): string
    {
        if ($this->tempRoot === null) {
            $this->tempRoot = sys_get_temp_dir() . '/sviat-pd-schema-' . bin2hex(random_bytes(6));
            mkdir($this->tempRoot . '/cache', 0777, true);
        }

        return $this->tempRoot;
    }
}

/**
 * Справжній update_1_1_0 модуля; конструктор AbstractInit тягне з контейнера пів ядра, і він тут не потрібен.
 */
final class InitWithoutCoreServices extends Init
{
    /** @var array<string, mixed> приватні властивості AbstractInit, потрібні install() */
    public static array $properties = [];

    public function __construct()
    {
        foreach (self::$properties as $name => $value) {
            new ReflectionProperty(AbstractInit::class, $name)->setValue($this, $value);
        }
    }
}
