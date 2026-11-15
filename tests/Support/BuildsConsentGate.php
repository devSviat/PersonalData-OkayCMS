<?php

namespace Modules\Sviat\PersonalData\Support;

require_once __DIR__ . '/RequestStub.php';
require_once __DIR__ . '/MigratedSettings.php';
require_once __DIR__ . '/BuildsSchemaState.php';
require_once __DIR__ . '/FakeDatabase.php';

use Aura\SqlQuery\QueryFactory as AuraQueryFactory;
use Okay\Core\DesignBlocks;
use Okay\Core\EntityFactory;
use Okay\Core\Languages;
use Okay\Core\QueryFactory;
use Okay\Core\TemplateConfig\FrontTemplateConfig;
use Okay\Core\FrontTranslations;
use Okay\Core\Modules\Modules;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentGate;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentLedger;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentRecorder;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentPolicy;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentSink;
use Okay\Modules\Sviat\PersonalData\Consent\ErrorText;
use Okay\Modules\Sviat\PersonalData\Consent\RenderProof;
use Okay\Modules\Sviat\PersonalData\Consent\RequestState;
use Okay\Modules\Sviat\PersonalData\Extensions\LinkExtension;
use Okay\Modules\Sviat\PersonalData\Forms\Adapters\CallbackAdapter;
use Okay\Modules\Sviat\PersonalData\Forms\Adapters\CartAdapter;
use Okay\Modules\Sviat\PersonalData\Forms\Adapters\CommentAdapter;
use Okay\Modules\Sviat\PersonalData\Forms\Adapters\FastOrderAdapter;
use Okay\Modules\Sviat\PersonalData\Forms\Adapters\FeedbackAdapter;
use Okay\Modules\Sviat\PersonalData\Forms\Adapters\RegisterAdapter;
use Okay\Modules\Sviat\PersonalData\Forms\CommentType;
use Okay\Modules\Sviat\PersonalData\Forms\FormRegistry;
use Okay\Modules\Sviat\PersonalData\Forms\FormSettings;
use Okay\Modules\Sviat\PersonalData\Forms\RecordProbe;
use Okay\Modules\Sviat\PersonalData\Policy\PolicyPage;
use Psr\Log\NullLogger;
use Modules\Sviat\PersonalData\Support\RequestStub;

/**
 * Справжні політика, шлюз і адаптери поверх налаштувань у пам'яті.
 * Для тестів на основі ModuleTestCase; тест кличе cleanConsentState() у tearDown().
 */
trait BuildsConsentGate
{
    use BuildsSchemaState;

    protected RequestState $requestState;

    /** @var list<string> форми, на які шлюз видав квиток */
    protected array $issued = [];

    /** Корінь з cache/ для скомпільованих шаблонів тесту. */
    protected string $consentRoot = '';

    /** @var array<string, string> блок дизайну => шаблон, які зареєстрував Init */
    protected array $designBlocks = [];

    protected function resetConsentState(): void
    {
        $_SESSION = [];
        $this->requestState = new RequestState();
        $this->issued = [];
        $this->cleanConsentState();
        $this->consentRoot = sys_get_temp_dir() . '/sviat-pd-consent-' . bin2hex(random_bytes(6));
        mkdir($this->consentRoot . '/cache', 0777, true);
    }

    protected function cleanConsentState(): void
    {
        if ($this->consentRoot !== '') {
            exec('rm -rf ' . escapeshellarg($this->consentRoot));
        }
    }

    protected function consentPolicy(MigratedSettings $settings, bool $modulesActive = false): ConsentPolicy
    {
        $modules = $this->createStub(Modules::class);
        $modules->method('getRunningModules')->willReturn([]);
        $modules->method('isActiveModule')->willReturn($modulesActive);

        return new ConsentPolicy(
            $this->schemaState($settings),
            $this->consentRegistry($modules),
            new FormSettings($settings),
            new RenderProof(),
        );
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed>|null $translations null — перекладів немає в жодній мові
     */
    protected function consentGate(MigratedSettings $settings, array $post = [], ?array $translations = ['sviat__personal_data__error' => 'Позначте згоду.'], ?FrontTranslations $frontTranslations = null, ?ConsentSink $sink = null, bool $modulesActive = false): ConsentGate
    {
        if ($frontTranslations === null) {
            $frontTranslations = $this->createStub(FrontTranslations::class);
            $frontTranslations->method('getTranslation')->willReturnCallback(static fn($key) => $translations[$key] ?? null);
        }
        $issued = &$this->issued;
        $sink ??= new class ($issued) implements ConsentSink {
            /** @param list<string> $issued */
            public function __construct(private array &$issued) {}

            public function issue(string $form): void
            {
                $this->issued[] = $form;
            }
        };

        return new ConsentGate($this->consentPolicy($settings, $modulesActive), $this->requestState, $sink, new RequestStub($post), new ErrorText($frontTranslations));
    }

    /** @return array<class-string, object> */
    protected function coreAdapters(ConsentGate $gate, MigratedSettings $settings): array
    {
        $formSettings = new FormSettings($settings);

        return [
            CartAdapter::class => new CartAdapter($gate, $formSettings),
            RegisterAdapter::class => new RegisterAdapter($gate, $formSettings),
            FeedbackAdapter::class => new FeedbackAdapter($gate, $formSettings),
            CallbackAdapter::class => new CallbackAdapter($gate, $formSettings),
            CommentAdapter::class => new CommentAdapter($gate, $formSettings, new CommentType()),
        ];
    }

    /** @param bool $moduleActive чи встановлено й увімкнено OkayCMS/FastOrder */
    protected function fastOrderAdapter(ConsentGate $gate, MigratedSettings $settings, bool $moduleActive): FastOrderAdapter
    {
        $modules = $this->createStub(Modules::class);
        $modules->method('getRunningModules')->willReturn([]);
        $modules->method('isActiveModule')->willReturn($moduleActive);

        return new FastOrderAdapter($gate, new FormSettings($settings), $this->consentRegistry($modules));
    }

    /** Init бере адаптери й шви прив'язки з контейнера, щоб спитати isActive(). FastOrder тут не встановлено. */
    protected function replaceAdaptersInContainer(ConsentGate $gate, MigratedSettings $settings): void
    {
        foreach ($this->coreAdapters($gate, $settings) as $class => $adapter) {
            $this->replaceServiceInContainer($class, $adapter);
        }
        $this->replaceServiceInContainer(FastOrderAdapter::class, $this->fastOrderAdapter($gate, $settings, false));
        $this->replaceServiceInContainer(LinkExtension::class, $this->idleLinkExtension($settings));
        $modules = $this->createStub(Modules::class);
        $modules->method('getRunningModules')->willReturn([]);
        $this->replaceServiceInContainer(FormRegistry::class, $this->consentRegistry($modules));
        $this->designBlocks = [];
        $designBlocks = $this->createStub(DesignBlocks::class);
        $designBlocks->method('registerBlock')->willReturnCallback(function (string $block, string $file): void {
            $this->designBlocks[$block] = $file;
        });
        $this->replaceServiceInContainer(DesignBlocks::class, $designBlocks);
    }

    /** Тема, якої немає в design/: addFrontBlock тоді бере шаблон із каталогу модуля. */
    protected function frontTemplateConfig(): FrontTemplateConfig
    {
        $config = $this->createStub(FrontTemplateConfig::class);
        $config->method('getTheme')->willReturn('sviat-pd-no-theme');

        return $config;
    }

    /** Шви прив'язки без бази: квитків ніхто не видає, тож вони нічого не пишуть. */
    protected function idleLinkExtension(MigratedSettings $settings): LinkExtension
    {
        $modules = $this->createStub(Modules::class);
        $modules->method('getRunningModules')->willReturn([]);
        $registry = $this->consentRegistry($modules);
        $entityFactory = $this->createStub(EntityFactory::class);
        $languages = $this->createStub(Languages::class);
        $ledger = new ConsentLedger(
            $registry,
            new RecordProbe(new FakeDatabase(), new QueryFactory(new AuraQueryFactory('mysql'))),
            new RenderProof(),
            $languages,
            new PolicyPage(new FormSettings($settings), $entityFactory),
            new NullLogger(),
        );

        return new LinkExtension(
            $ledger,
            new ConsentRecorder($ledger, $registry, $entityFactory, new NullLogger(), new RequestStub()),
            new FormSettings($settings),
            $registry,
            new CommentType(),
        );
    }

    /** Проставляє в сесію доказ показу, як його лишає плагін. */
    protected function shown(string $form, int $langId = 1, ?int $policyPageId = null): void
    {
        new RenderProof()->record($form, $langId, $policyPageId);
    }

    private function consentRegistry(Modules $modules): FormRegistry
    {
        $services = require dirname(__DIR__, 5) . '/Okay/Modules/Sviat/PersonalData/Init/services.php';

        return new FormRegistry($modules, ...array_slice($services[FormRegistry::class]['arguments'], 1));
    }
}
