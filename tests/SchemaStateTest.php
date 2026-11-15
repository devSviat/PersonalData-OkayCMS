<?php

namespace Modules\Sviat\PersonalData;

require_once __DIR__ . '/Support/ModuleTestCase.php';
require_once __DIR__ . '/Support/RequestStub.php';
require_once __DIR__ . '/Support/MigratedSettings.php';
require_once __DIR__ . '/Support/BuildsConsentGate.php';

use Modules\Sviat\PersonalData\Support\BuildsConsentGate;
use Modules\Sviat\PersonalData\Support\MigratedSettings;
use Okay\Core\Database;
use Okay\Core\Design;
use Okay\Core\EntityFactory;
use Okay\Core\FrontTranslations;
use Okay\Core\ManagerMenu;
use Okay\Core\Managers;
use Okay\Core\Modules\AbstractInit;
use Okay\Core\Modules\Extender\ChainExtender;
use Okay\Core\Modules\Extender\ExtenderFacade;
use Okay\Core\Modules\Extender\QueueExtender;
use Okay\Core\Modules\Module;
use Okay\Core\Response;
use Okay\Core\Scheduler\Schedule;
use Okay\Core\Scheduler\Scheduler;
use Okay\Core\Settings;
use Okay\Core\Validator;
use Okay\Entities\ModulesEntity;
use Okay\Helpers\OrdersHelper;
use Okay\Helpers\ValidateHelper;
use Okay\Modules\Sviat\PersonalData\Backend\Controllers\DescriptionAdmin;
use Okay\Modules\Sviat\PersonalData\Backend\Helpers\BackendSettingsHelper;
use Okay\Modules\Sviat\PersonalData\Core\SchemaState;
use Okay\Modules\Sviat\PersonalData\Forms\Adapters\FastOrderAdapter;
use Okay\Modules\Sviat\PersonalData\Init\Init;
use Okay\Modules\Sviat\PersonalData\Scheduler\PurgeTask;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionProperty;
use Modules\Sviat\PersonalData\Support\ModuleTestCase;
use Modules\Sviat\PersonalData\Support\RequestStub;

/** Код 1.1.0 на базі 1.0.1: доки не натиснуто «Оновити», модуль нічого не вимагає й не чіпає нових таблиць. */
class SchemaStateTest extends ModuleTestCase
{
    use BuildsConsentGate;

    private const string MODULE_NAMESPACE = 'Okay\\Modules\\Sviat\\PersonalData\\';

    private const array NEW_ENTITIES = [
        self::MODULE_NAMESPACE . 'Entities\\ConsentsEntity',
    ];

    /** @var array<class-string, array<string, mixed>> */
    private array $triggers = [];

    private ?string $tempRoot = null;

    protected function setUp(): void
    {
        foreach ([ChainExtender::class, QueueExtender::class] as $extender) {
            $this->triggers[$extender] = self::triggersOf($extender);
        }
    }

    protected function tearDown(): void
    {
        $this->cleanConsentState();
        foreach ($this->triggers as $extender => $triggers) {
            \Closure::bind(static function () use ($triggers): void {
                static::$triggers = $triggers;
            }, null, $extender)();
        }
        $_SESSION = [];
        if ($this->tempRoot !== null) {
            exec('rm -rf ' . escapeshellarg($this->tempRoot));
        }
        parent::tearDown();
    }

    public function testMarkerMeansMigrated(): void
    {
        self::assertTrue($this->schemaState(new MigratedSettings())->isMigrated());
    }

    public function testHigherMarkerCountsAsMigrated(): void
    {
        self::assertTrue($this->schemaState(new MigratedSettings([SchemaState::MARKER_KEY => '1.2.0']))->isMigrated());
        self::assertFalse($this->schemaState(new MigratedSettings([SchemaState::MARKER_KEY => '1.0.9']))->isMigrated());
    }

    public function testNoMarkerMeansNotMigrated(): void
    {
        self::assertFalse($this->schemaState(MigratedSettings::pending())->isMigrated());
    }

    public function testNoDbQuery(): void
    {
        $database = $this->createMock(Database::class);
        $database->expects(self::never())->method(self::anything());
        $settings = new MigratedSettings();
        $state = $this->schemaState($settings, $database);

        self::assertTrue($state->isMigrated());
        unset($settings->values[SchemaState::MARKER_KEY]);
        self::assertTrue($state->isMigrated(), 'результат запамʼятовано на запит');
    }

    public function testMissingTablesComeFromInformationSchemaBaseTables(): void
    {
        $statements = [];
        $database = $this->createStub(Database::class);
        $database->method('query')->willReturnCallback(static function ($query) use (&$statements): bool {
            $statements[] = [$query->getStatement(), $query->getBindValues()];

            return true;
        });
        $database->method('results')->willReturn([]);
        $state = $this->schemaState(new MigratedSettings(), $database);

        self::assertSame(['__sviat__personal_data__consents'], $state->missingTables());
        self::assertFalse($state->tablesExist());
        self::assertStringContainsString("TABLE_TYPE = 'BASE TABLE'", $statements[0][0]);
        self::assertSame(['ok_sviat__personal_data__consents'], $statements[0][1]['names']);
    }

    public function testFailedTableQueryMeansAllMissing(): void
    {
        $database = $this->createStub(Database::class);
        $database->method('query')->willReturn(false);

        self::assertSame(SchemaState::TABLES, $this->schemaState(new MigratedSettings(), $database)->missingTables());
    }

    public function testPreMigrationRegistersNothingAndQueriesNothing(): void
    {
        $database = $this->createMock(Database::class);
        $database->expects(self::never())->method(self::anything());
        $this->replaceServiceInContainer(SchemaState::class, $this->schemaState(MigratedSettings::pending(), $database));

        $this->init()->init();

        self::assertSame([], $this->moduleLinks());
    }

    public function testControlMigratedRegistersLinks(): void
    {
        $settings = new MigratedSettings(['sviat__personal_data__form_feedback' => 1]);
        $this->resetConsentState();
        $this->replaceAdaptersInContainer($this->consentGate($settings), $settings);
        $this->replaceServiceInContainer(SchemaState::class, $this->schemaState($settings));

        $this->init()->init();

        self::assertNotSame([], $this->moduleLinks(), 'перевірка бачить ланки модуля');
    }

    /** Ядро відкидає ланку до методу класу, якого немає, тож без FastOrder ланки на нього немає. */
    public function testFastOrderChainOnlyWithModule(): void
    {
        $settings = new MigratedSettings(['sviat__personal_data__form_fast_order' => 1]);
        $this->resetConsentState();
        $gate = $this->consentGate($settings, modulesActive: true);
        $this->replaceAdaptersInContainer($gate, $settings);
        $this->replaceServiceInContainer(SchemaState::class, $this->schemaState($settings));
        $link = 'Okay\\Modules\\OkayCMS\\FastOrder\\Extenders\\BackendExtender::ValidateFastOrder → '
            . self::MODULE_NAMESPACE . 'Forms\\Adapters\\FastOrderAdapter::extendValidateFastOrder';

        $this->init()->init();
        self::assertNotContains($link, $this->moduleLinks());

        $this->replaceServiceInContainer(FastOrderAdapter::class, $this->fastOrderAdapter($gate, $settings, true));
        $this->init()->init();
        self::assertContains($link, $this->moduleLinks());
    }

    public function testPreMigrationLeavesCoreValidationUntouched(): void
    {
        $settings = MigratedSettings::pending(['sviat__personal_data__form_feedback' => 1]);
        $this->resetConsentState();
        $this->shown('feedback');
        $this->replaceAdaptersInContainer($this->consentGate($settings), $settings);
        $feedback = (object) ['name' => 'Іван', 'email' => 'ivan@example.com', 'message' => 'Привіт'];
        $withoutModule = $this->validateHelper()->getFeedbackValidateError($feedback);

        $this->replaceServiceInContainer(SchemaState::class, $this->schemaState(MigratedSettings::pending()));
        $this->init()->init();

        self::assertNull($withoutModule);
        self::assertSame($withoutModule, $this->validateHelper()->getFeedbackValidateError($feedback));
    }

    public function testPreMigrationRegistersOnlyDescriptionController(): void
    {
        $this->replaceServiceInContainer(SchemaState::class, $this->schemaState(MigratedSettings::pending()));
        $init = $this->init();

        $init->init();

        self::assertSame(['DescriptionAdmin'], $init->getBackendControllers());
    }

    public function testPreMigrationAdminQueriesNoNewTables(): void
    {
        $requested = [];
        $entityFactory = $this->createStub(EntityFactory::class);
        $modules = $this->createStub(ModulesEntity::class);
        $modules->method('getByVendorModuleName')->willReturn((object) ['version' => '1.0.1']);
        $entityFactory->method('get')->willReturnCallback(function (string $class) use (&$requested, $modules) {
            $requested[] = $class;

            return $class === ModulesEntity::class ? $modules : $this->createStub(ModulesEntity::class);
        });
        $database = $this->createStub(Database::class);
        $database->method('query')->willReturn(true);
        $database->method('results')->willReturn([]);
        $state = $this->schemaState(MigratedSettings::pending(), $database, $entityFactory);

        $assigned = [];
        $design = $this->createStub(Design::class);
        $design->method('assign')->willReturnCallback(static function ($name, $value) use (&$assigned) {
            $assigned[$name] = $value;
        });
        $design->method('fetch')->willReturn('');
        $controller = new ReflectionClass(DescriptionAdmin::class)->newInstanceWithoutConstructor();
        foreach (['design' => $design, 'request' => new RequestStub(['pd_cart' => '1']), 'response' => $this->createStub(Response::class), 'entityFactory' => $entityFactory] as $name => $value) {
            new ReflectionProperty($controller, $name)->setValue($controller, $value);
        }
        $helper = $this->createMock(BackendSettingsHelper::class);
        $helper->expects(self::never())->method('save');
        $helper->expects(self::never())->method('formRows');

        $controller->fetch($helper, $state);

        self::assertSame([], array_values(array_intersect($requested, self::NEW_ENTITIES)));
        self::assertTrue($assigned['sviat_pd_needs_update'] ?? null);
    }

    public function testSchedulePreMigrationEmpty(): void
    {
        $scheduler = $this->createMock(Scheduler::class);
        $scheduler->expects(self::never())->method('registerSchedule');
        $this->replaceServiceInContainer(SchemaState::class, $this->schemaState(MigratedSettings::pending()));

        $this->init($scheduler)->registerSchedule(new Schedule([Init::class, 'init']));
    }

    public function testControlScheduleAfterMigration(): void
    {
        $scheduler = $this->createMock(Scheduler::class);
        $scheduler->expects(self::once())->method('registerSchedule');
        $this->replaceServiceInContainer(SchemaState::class, $this->schemaState(new MigratedSettings()));

        $this->init($scheduler)->registerSchedule(new Schedule([Init::class, 'init']));
    }

    /** Строк зберігання йде лише після оновлення схеми: до нього таблиці журналу може не бути. */
    public function testConsentTasksScheduledOnlyAfterMigration(): void
    {
        $settings = new MigratedSettings();
        $this->resetConsentState();
        $this->replaceAdaptersInContainer($this->consentGate($settings), $settings);
        $this->replaceServiceInContainer(SchemaState::class, $this->schemaState($settings));
        $commands = [];
        $scheduler = $this->createStub(Scheduler::class);
        $scheduler->method('registerSchedule')->willReturnCallback(static function (Schedule $schedule) use (&$commands): void {
            $commands[] = new ReflectionProperty(Schedule::class, 'command')->getValue($schedule);
        });

        $this->init($scheduler)->init();

        self::assertSame([[PurgeTask::class, 'run']], $commands);

        $commands = [];
        $this->replaceServiceInContainer(SchemaState::class, $this->schemaState(MigratedSettings::pending()));
        $this->init($scheduler)->init();
        self::assertSame([], $commands);
    }

    public function testWarningThrottleFileUnwritableLogsNothing(): void
    {
        $root = $this->tempRoot();
        // Звичайний файл на місці каталогу: запис неможливий навіть від root, на відміну від chmod.
        file_put_contents($root . '/cache', '');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method(self::anything());

        $this->schemaState(MigratedSettings::pending(), logger: $logger, rootDir: $root)->warnNotMigrated();
    }

    public function testWarningLoggedOncePerHour(): void
    {
        $root = $this->tempRoot();
        mkdir($root . '/cache');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::stringContains('schema not migrated'));

        $this->schemaState(MigratedSettings::pending(), logger: $logger, rootDir: $root)->warnNotMigrated();
        $this->schemaState(MigratedSettings::pending(), logger: $logger, rootDir: $root)->warnNotMigrated();
    }

    private function init(?Scheduler $scheduler = null): Init
    {
        $init = new ReflectionClass(Init::class)->newInstanceWithoutConstructor();
        $properties = [
            'extenderFacade' => new ExtenderFacade(new QueueExtender(), new ChainExtender()),
            'managers' => $this->createStub(Managers::class),
            'module' => new Module(new NullLogger()),
            'scheduler' => $scheduler ?? $this->createStub(Scheduler::class),
            'frontTemplateConfig' => $this->frontTemplateConfig(),
            'managerMenu' => $this->createStub(ManagerMenu::class),
            'vendor' => 'Sviat',
            'moduleName' => 'PersonalData',
        ];
        foreach ($properties as $name => $value) {
            new ReflectionProperty(AbstractInit::class, $name)->setValue($init, $value);
        }

        return $init;
    }

    /** @return list<string> */
    private function moduleLinks(): array
    {
        $links = [];
        foreach ([ChainExtender::class, QueueExtender::class] as $extender) {
            foreach (self::triggersOf($extender) as $trigger => $extensions) {
                foreach ($extensions as $extension) {
                    if (str_starts_with($extension->class, self::MODULE_NAMESPACE)) {
                        $links[] = $trigger . ' → ' . $extension->class . '::' . $extension->method;
                    }
                }
            }
        }

        return $links;
    }

    /** @return array<string, array<int, object>> */
    private static function triggersOf(string $extender): array
    {
        return \Closure::bind(static fn(): array => static::$triggers, null, $extender)();
    }

    private function validateHelper(): ValidateHelper
    {
        $settings = new MigratedSettings();
        $validator = $this->createStub(Validator::class);
        $validator->method('isName')->willReturn(true);
        $validator->method('isEmail')->willReturn(true);
        $validator->method('isComment')->willReturn(true);

        return new ValidateHelper(
            $validator,
            $settings,
            new RequestStub([]),
            $this->createStub(FrontTranslations::class),
            new ReflectionClass(OrdersHelper::class)->newInstanceWithoutConstructor(),
        );
    }

    private function tempRoot(): string
    {
        $this->tempRoot = sys_get_temp_dir() . '/sviat-pd-' . bin2hex(random_bytes(6));
        mkdir($this->tempRoot);

        return $this->tempRoot;
    }
}
