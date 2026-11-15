<?php

namespace Modules\Sviat\PersonalData;

require_once __DIR__ . '/Support/ModuleTestCase.php';
require_once __DIR__ . '/Support/RequestStub.php';
require_once __DIR__ . '/Support/MigratedSettings.php';
require_once __DIR__ . '/Support/BuildsSchemaState.php';
require_once __DIR__ . '/Support/BuildsConsentGate.php';
require_once __DIR__ . '/Support/FakeDatabase.php';
require_once __DIR__ . '/Support/PrgSpy.php';

use Modules\Sviat\PersonalData\Support\BuildsConsentGate;
use Modules\Sviat\PersonalData\Support\FakeDatabase;
use Modules\Sviat\PersonalData\Support\MigratedSettings;
use Modules\Sviat\PersonalData\Support\PrgSpy;
use Okay\Admin\Controllers\IndexAdmin;
use Okay\Core\Design;
use Okay\Core\Languages;
use Okay\Core\ManagerMenu;
use Okay\Core\Managers;
use Okay\Core\Modules\AbstractInit;
use Okay\Core\Modules\Extender\ChainExtender;
use Okay\Core\Modules\Extender\ExtenderFacade;
use Okay\Core\Modules\Extender\QueueExtender;
use Okay\Core\Modules\Module;
use Okay\Core\Response;
use Okay\Core\Scheduler\Scheduler;
use Okay\Entities\ModulesEntity;
use Okay\Modules\Sviat\PersonalData\Backend\Controllers\ConsentLogAdmin;
use Okay\Modules\Sviat\PersonalData\Backend\Controllers\DescriptionAdmin;
use Okay\Modules\Sviat\PersonalData\Backend\Helpers\BackendSettingsHelper;
use Okay\Modules\Sviat\PersonalData\Backend\Helpers\ConsentLogHelper;
use Okay\Modules\Sviat\PersonalData\Core\SchemaState;
use Okay\Modules\Sviat\PersonalData\Init\Init;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionProperty;
use Modules\Sviat\PersonalData\Support\ModuleTestCase;
use Modules\Sviat\PersonalData\Support\RequestStub;

/** Кожна дія адмінки модуля — POST із перевіркою сесії; «Форми» й «Журнал» мають окремі права. */
class AdminActionsTest extends ModuleTestCase
{
    use BuildsConsentGate;

    private const string CONTROLLERS = __DIR__ . '/../../../../Okay/Modules/Sviat/PersonalData/Backend/Controllers';

    /** @var array<class-string, array<string, mixed>> */
    private array $triggers = [];

    private ?Init $lastInit = null;

    protected function setUp(): void
    {
        foreach ([ChainExtender::class, QueueExtender::class] as $extender) {
            $this->triggers[$extender] = \Closure::bind(static fn(): array => static::$triggers, null, $extender)();
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
        parent::tearDown();
    }

    /** @return array<string, array{class-string<IndexAdmin>, class-string, string, array<string, mixed>}> */
    public static function actions(): array
    {
        $settings = ['forms' => ['cart' => '1'], 'policy_page_id' => '41', 'retention_years' => '3'];

        return [
            'форми' => [DescriptionAdmin::class, BackendSettingsHelper::class, 'save', $settings],
            'відкликання' => [ConsentLogAdmin::class, ConsentLogHelper::class, 'withdraw', ['action' => 'withdraw', 'id' => '5']],
            'знеособлення' => [ConsentLogAdmin::class, ConsentLogHelper::class, 'anonymize', ['action' => 'anonymize', 'id' => '5']],
            'експорт' => [ConsentLogAdmin::class, ConsentLogHelper::class, 'export', ['action' => 'export']],
        ];
    }

    /**
     * @param class-string<IndexAdmin> $controller
     * @param class-string $helperClass
     * @param array<string, mixed> $post
     */
    #[DataProvider('actions')]
    public function testEveryAdminActionRequiresPostAndSession(string $controller, string $helperClass, string $action, array $post): void
    {
        self::assertSame(0, $this->runAction($controller, $helperClass, $action, new SessionRequest($post, 'GET', true)), 'GET');
        self::assertSame(0, $this->runAction($controller, $helperClass, $action, new SessionRequest($post, 'POST', false)), 'POST без сесії');
        self::assertSame(1, $this->runAction($controller, $helperClass, $action, new SessionRequest($post, 'POST', true)), 'контроль: POST із сесією');
    }

    /** Майбутній контролер з POST теж мусить перевіряти сесію. */
    public function testEveryControllerWithPostChecksSession(): void
    {
        $files = glob(self::CONTROLLERS . '/*.php') ?: [];
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            if (str_contains($source, "method('post')")) {
                self::assertStringContainsString('checkSession()', $source, basename($file));
            }
        }
    }

    public function testPermissionsAreSeparate(): void
    {
        $settings = new MigratedSettings();
        $this->resetConsentState();
        $this->replaceAdaptersInContainer($this->consentGate($settings), $settings);
        $this->replaceServiceInContainer(SchemaState::class, $this->schemaState($settings));
        $permissions = [];
        $managers = $this->createStub(Managers::class);
        $managers->method('addModuleControllerPermission')->willReturnCallback(static function (string $controller, string $permission) use (&$permissions): void {
            $permissions[$controller] = $permission;
        });

        $this->init($managers)->init();

        self::assertSame([
            'Sviat.PersonalData.DescriptionAdmin' => 'sviat__personal_data',
            'Sviat.PersonalData.ConsentLogAdmin' => 'sviat__personal_data__log',
        ], $permissions);
        self::assertSame(['DescriptionAdmin', 'ConsentLogAdmin'], $this->lastInit?->getBackendControllers());
    }

    /** Право журналу відкриває експорт із повними IP, тож у формі прав менеджера обидва права мають людські назви. */
    public function testPermissionLabelsAreHuman(): void
    {
        $settings = new MigratedSettings();
        $this->resetConsentState();
        $this->replaceAdaptersInContainer($this->consentGate($settings), $settings);
        $this->replaceServiceInContainer(SchemaState::class, $this->schemaState($settings));
        $managers = new Managers();
        $menu = new ManagerMenu($managers, new Module(new NullLogger()));

        $this->init($managers, $menu)->init();

        $lang = [];
        include __DIR__ . '/../../../../Okay/Modules/Sviat/PersonalData/Backend/lang/ua.php';
        $btr = new class ($lang) {
            /** @param array<string, string> $lang */
            public function __construct(private readonly array $lang) {}

            public function __get($key)
            {
                return $this->lang[$key] ?? $key;
            }
        };
        $admin = (object) ['permissions' => $managers->getAllPermissions()];
        $modules = $menu->getPermissionMenu($admin, $btr)['left_modules'];

        self::assertSame('Sviat/PersonalData — Згоди: форми', $modules['sviat__personal_data'] ?? null);
        self::assertSame('Sviat/PersonalData — Журнал згод', $modules['sviat__personal_data__log'] ?? null);
    }

    /** До «Оновити» лише «Форми»: журнал читає нові таблиці, а сторінка «Форми» сама показує банер. */
    public function testSamePageBeforeUpdate(): void
    {
        $this->replaceServiceInContainer(SchemaState::class, $this->schemaState(MigratedSettings::pending()));
        $init = $this->init($this->createStub(Managers::class));

        $init->init();

        self::assertSame(['DescriptionAdmin'], $init->getBackendControllers());
    }

    /**
     * @param class-string<IndexAdmin> $controllerClass
     * @param class-string $helperClass
     * @return int скільки разів викликано дію
     */
    private function runAction(string $controllerClass, string $helperClass, string $action, SessionRequest $request): int
    {
        $calls = 0;
        $helper = $this->createStub($helperClass);
        $helper->method($action)->willReturnCallback(static function () use (&$calls, $action) {
            $calls++;

            return match ($action) {
                'save' => null,
                'export' => [],
                default => true,
            };
        });
        if ($helper instanceof BackendSettingsHelper) {
            $helper->method('formRows')->willReturn([]);
            $helper->method('policyPages')->willReturn([]);
        }

        $design = $this->createStub(Design::class);
        $design->method('fetch')->willReturn('');
        $languages = $this->createStub(Languages::class);
        $languages->method('getLangId')->willReturn(1);
        $controller = new ReflectionClass($controllerClass)->newInstanceWithoutConstructor();
        $properties = [
            'design' => $design,
            'request' => $request,
            'response' => $this->createStub(Response::class),
            'languages' => $languages,
            'manager' => (object) ['login' => 'anna', 'permissions' => ['all']],
            'postRedirectGet' => new PrgSpy(),
        ];
        foreach ($properties as $name => $value) {
            new ReflectionProperty(IndexAdmin::class, $name)->setValue($controller, $value);
        }

        $controller->fetch($helper, $this->migratedState());

        return $calls;
    }

    private function migratedState(): SchemaState
    {
        $database = new FakeDatabase();
        $database->tables = ['ok_sviat__personal_data__consents'];
        $modules = $this->createStub(ModulesEntity::class);
        $modules->method('getByVendorModuleName')->willReturn((object) ['version' => '1.1.0']);

        return $this->schemaState(new MigratedSettings(), $database, $this->mockEntityFactory([ModulesEntity::class => $modules]));
    }

    private function init(Managers $managers, ?ManagerMenu $menu = null): Init
    {
        $init = new ReflectionClass(Init::class)->newInstanceWithoutConstructor();
        $properties = [
            'extenderFacade' => new ExtenderFacade(new QueueExtender(), new ChainExtender()),
            'managers' => $managers,
            'module' => new Module(new NullLogger()),
            'scheduler' => $this->createStub(Scheduler::class),
            'frontTemplateConfig' => $this->frontTemplateConfig(),
            'managerMenu' => $menu ?? new ManagerMenu($managers, new Module(new NullLogger())),
            'vendor' => 'Sviat',
            'moduleName' => 'PersonalData',
        ];
        foreach ($properties as $name => $value) {
            new ReflectionProperty(AbstractInit::class, $name)->setValue($init, $value);
        }

        return $this->lastInit = $init;
    }
}

/** Запит із заданим результатом перевірки сесії; справжній checkSession() читає $_POST і токен сесії. */
final class SessionRequest extends RequestStub
{
    /** @param array<string, mixed> $post */
    public function __construct(array $post, string $method, private readonly bool $sessionValid)
    {
        parent::__construct($post, [], $method);
    }

    public function checkSession()
    {
        return $this->sessionValid;
    }
}
