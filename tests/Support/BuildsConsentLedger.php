<?php

namespace Modules\Sviat\PersonalData\Support;

require_once __DIR__ . '/BuildsConsentGate.php';
require_once __DIR__ . '/FakeDatabase.php';
require_once __DIR__ . '/FakeConsentsEntity.php';
require_once __DIR__ . '/FakeRecordEntity.php';
require_once __DIR__ . '/RoutesPolicyPages.php';
require_once __DIR__ . '/OwnerEntityStubs.php';

use Aura\SqlQuery\QueryFactory as AuraQueryFactory;
use Okay\Core\Entity\Entity;
use Okay\Core\EntityFactory;
use Okay\Core\Languages;
use Okay\Core\Modules\AbstractInit;
use Okay\Core\ManagerMenu;
use Okay\Core\Managers;
use Okay\Core\Modules\Extender\ChainExtender;
use Okay\Core\Modules\Extender\ExtenderFacade;
use Okay\Core\Modules\Extender\QueueExtender;
use Okay\Core\Modules\Module;
use Okay\Core\Modules\Modules;
use Okay\Core\QueryFactory;
use Okay\Core\Scheduler\Scheduler;
use Okay\Entities\PagesEntity;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentLedger;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentRecorder;
use Okay\Modules\Sviat\PersonalData\Consent\RenderProof;
use Okay\Modules\Sviat\PersonalData\Core\SchemaState;
use Okay\Modules\Sviat\PersonalData\Entities\ConsentsEntity;
use Okay\Modules\Sviat\PersonalData\Extensions\AnonymizeExtension;
use Okay\Modules\Sviat\PersonalData\Extensions\LinkExtension;
use Okay\Modules\Sviat\PersonalData\Forms\CommentType;
use Okay\Modules\Sviat\PersonalData\Forms\FormRegistry;
use Okay\Modules\Sviat\PersonalData\Forms\FormSettings;
use Okay\Modules\Sviat\PersonalData\Forms\RecordLookup;
use Okay\Modules\Sviat\PersonalData\Forms\RecordProbe;
use Okay\Modules\Sviat\PersonalData\Init\Init;
use Okay\Modules\Sviat\PersonalData\Policy\PolicyPage;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Журнал квитків і запис згоди поверх таблиць у пам'яті. Тест кличе resetLedgerState() у setUp()
 * і cleanLedgerState() у tearDown(); ланки, які зареєстрував Init, прибираються там само.
 */
trait BuildsConsentLedger
{
    use BuildsConsentGate;
    use RoutesPolicyPages;

    protected const string IP = '203.0.113.77';
    protected const int POLICY_PAGE = 41;

    protected FakeDatabase $database;

    protected FakeConsentsEntity $consents;

    /** @var array<int, object> сторінки магазину: id => рядок з `visible` і `url` */
    protected array $shopPages = [];

    /** @var list<array<string, mixed>> фільтри кожного запиту до сторінок */
    protected array $pageQueries = [];

    protected MigratedSettings $settings;

    /** @var array<string, FakeRecordEntity> клас сутності запису => таблиця */
    protected array $records = [];

    /** @var list<string> повідомлення логу разом із контекстом */
    protected array $logged = [];

    protected int $pageLangId = 2;

    protected bool $modulesActive = true;

    /** @var array<string, array<int, object>> */
    private array $savedTriggers = [];

    /** @param array<string, mixed> $settings */
    protected function resetLedgerState(array $settings = []): void
    {
        $this->resetConsentState();
        $this->routePolicyPages();
        foreach ([ChainExtender::class, QueueExtender::class] as $extender) {
            $this->savedTriggers[$extender] = \Closure::bind(static fn(): array => static::$triggers, null, $extender)();
        }
        $_SERVER['REMOTE_ADDR'] = self::IP;
        $this->database = new FakeDatabase();
        $this->consents = new FakeConsentsEntity();
        $this->logged = [];
        $this->pageLangId = 2;
        $this->modulesActive = true;
        $this->settings = new MigratedSettings($settings + ['sviat__personal_data__policy_page_id' => self::POLICY_PAGE]);
        $this->shopPages = [self::POLICY_PAGE => (object) ['id' => (string) self::POLICY_PAGE, 'url' => 'privacy', 'visible' => '1']];
        $this->pageQueries = [];
        $this->records = [];
        foreach ($this->registry()->all() as $form) {
            if (class_exists($form->recordEntity)) {
                $this->records($form->recordEntity, []);
            }
        }
    }

    protected function cleanLedgerState(): void
    {
        foreach ($this->savedTriggers as $extender => $triggers) {
            \Closure::bind(static function () use ($triggers): void {
                static::$triggers = $triggers;
            }, null, $extender)();
        }
        $this->savedTriggers = [];
        unset($_SERVER['REMOTE_ADDR']);
        $this->restorePolicyRoutes();
        $_SESSION = [];
        $this->cleanConsentState();
    }

    /** @param list<int> $ids наявні записи; MAX(id) таблиці береться з них */
    protected function records(string $entity, array $ids): FakeRecordEntity
    {
        $this->records[$entity] = new FakeRecordEntity($ids);
        $this->syncMaxId($entity);

        return $this->records[$entity];
    }

    /** Запис, створений формою вже після згоди. */
    protected function addRecord(string $entity, int $id): void
    {
        $this->records[$entity]->records[$id] = (object) ['id' => (string) $id];
        $this->syncMaxId($entity);
    }

    protected function registry(): FormRegistry
    {
        $modules = $this->createStub(Modules::class);
        $modules->method('getRunningModules')->willReturn([]);
        $modules->method('isActiveModule')->willReturnCallback(fn() => $this->modulesActive);

        return $this->consentRegistry($modules);
    }

    protected function entityFactory(): EntityFactory
    {
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturnCallback(fn(string $class) => match ($class) {
            ConsentsEntity::class => $this->consents,
            PagesEntity::class => $this->pagesEntity(),
            default => $this->records[$class] ?? throw new \LogicException("no fake for $class"),
        });

        return $factory;
    }

    protected function logger(): LoggerInterface
    {
        $logged = &$this->logged;

        return new class ($logged) extends AbstractLogger {
            /** @param list<string> $logged */
            public function __construct(private array &$logged) {}

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = $level . ' ' . $message . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
            }
        };
    }

    protected function ledger(?EntityFactory $entityFactory = null): ConsentLedger
    {
        $languages = $this->createStub(Languages::class);
        $languages->method('getLangId')->willReturnCallback(fn() => $this->pageLangId);
        $languages->method('getMainLanguage')->willReturn((object) ['id' => 1, 'label' => 'ua']);
        $languages->method('getAllLanguages')->willReturn([1 => (object) ['id' => 1, 'label' => 'ua'], 2 => (object) ['id' => 2, 'label' => 'en'], 3 => (object) ['id' => 3, 'label' => 'ge']]);
        $languages->method('getLangLabel')->willReturnCallback(static fn($id) => [1 => 'ua', 2 => 'en', 3 => 'ge'][$id] ?? false);

        return new ConsentLedger(
            $this->registry(),
            new RecordProbe($this->database, new QueryFactory(new AuraQueryFactory('mysql'))),
            new RenderProof(),
            $languages,
            new PolicyPage(new FormSettings($this->settings), $entityFactory ?? $this->entityFactory()),
            $this->logger(),
        );
    }

    protected function recorder(ConsentLedger $ledger, ?EntityFactory $entityFactory = null): ConsentRecorder
    {
        return new ConsentRecorder($ledger, $this->registry(), $entityFactory ?? $this->entityFactory(), $this->logger(), new \Modules\Sviat\PersonalData\Support\RequestStub());
    }

    protected function recordLookup(): RecordLookup
    {
        return new RecordLookup(new RecordProbe($this->database, new QueryFactory(new AuraQueryFactory('mysql'))), $this->entityFactory());
    }

    /** Запис справді видалено з таблиці. */
    protected function removeRecord(string $entity, int $id): void
    {
        unset($this->records[$entity]->records[$id]);
        $this->syncMaxId($entity);
    }

    /** Запис іншої форми тієї самої сутності: тип коментаря, відповідь менеджера. */
    protected function recordFields(string $entity, int $id, array $fields): void
    {
        $this->records[$entity]->records[$id] = (object) (['id' => (string) $id] + $fields);
        $this->syncMaxId($entity);
    }

    protected function linkExtension(ConsentLedger $ledger, ConsentRecorder $recorder): LinkExtension
    {
        return new LinkExtension($ledger, $recorder, new FormSettings($this->settings), $this->registry(), new CommentType());
    }

    /**
     * Init модуля, який реєструє ланки в справжньому ExtenderFacade; сервіси, що він бере з контейнера,
     * підмінено на зібрані тут.
     */
    protected function initWithLedger(ConsentLedger $ledger): Init
    {
        $recorder = $this->recorder($ledger);
        $this->replaceAdaptersInContainer($this->consentGate($this->settings, sink: $ledger), $this->settings);
        $this->replaceServiceInContainer(SchemaState::class, $this->schemaState($this->settings));
        $this->replaceServiceInContainer(LinkExtension::class, $this->linkExtension($ledger, $recorder));
        $this->replaceServiceInContainer(AnonymizeExtension::class, new AnonymizeExtension($recorder, $this->registry(), $this->recordLookup(), $this->logger()));

        $init = new \ReflectionClass(Init::class)->newInstanceWithoutConstructor();
        $properties = [
            'extenderFacade' => new ExtenderFacade(new QueueExtender(), new ChainExtender()),
            'managers' => $this->createStub(Managers::class),
            'module' => new Module(new NullLogger()),
            'scheduler' => $this->createStub(Scheduler::class),
            'frontTemplateConfig' => $this->frontTemplateConfig(),
            'managerMenu' => $this->createStub(ManagerMenu::class),
            'vendor' => 'Sviat',
            'moduleName' => 'PersonalData',
        ];
        foreach ($properties as $name => $value) {
            new \ReflectionProperty(AbstractInit::class, $name)->setValue($init, $value);
        }
        $init->init();

        return $init;
    }

    /** @return list<object> рядки журналу як їх бачить база */
    protected function consentRows(): array
    {
        return array_values($this->consents->rows);
    }

    private function syncMaxId(string $entity): void
    {
        /** @var class-string<Entity> $entity */
        $ids = array_keys($this->records[$entity]->records);
        $this->database->maxIds[$entity::getTable()] = $ids === [] ? null : max($ids);
    }

    private function pagesEntity(): PagesEntity
    {
        $pages = $this->createStub(PagesEntity::class);
        $pages->method('findOne')->willReturnCallback(function (array $filter) {
            $this->pageQueries[] = $filter;
            $page = $this->shopPages[(int) ($filter['id'] ?? 0)] ?? null;

            return $page !== null && (!isset($filter['visible']) || (int) $page->visible === (int) $filter['visible']) ? $page : false;
        });

        return $pages;
    }
}
