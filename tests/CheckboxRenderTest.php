<?php

namespace Modules\Sviat\PersonalData;

require_once __DIR__ . '/Support/ModuleTestCase.php';
require_once __DIR__ . '/Support/BuildsSchemaState.php';
require_once __DIR__ . '/Support/FakeDatabase.php';
require_once __DIR__ . '/Support/RoutesPolicyPages.php';

use Aura\SqlQuery\QueryFactory as AuraQueryFactory;
use Modules\Sviat\PersonalData\Support\BuildsSchemaState;
use Modules\Sviat\PersonalData\Support\FakeDatabase;
use Modules\Sviat\PersonalData\Support\RoutesPolicyPages;
use Okay\Core\Design;
use Okay\Core\FrontTranslations;
use Okay\Core\Languages;
use Okay\Core\Modules\Modules;
use Okay\Core\OkayContainer\Reference\ServiceReference;
use Okay\Core\QueryFactory;
use Okay\Core\Settings;
use Okay\Modules\Sviat\PersonalData\Consent\ErrorText;
use Okay\Modules\Sviat\PersonalData\Consent\RenderProof;
use Okay\Modules\Sviat\PersonalData\Consent\RequestState;
use Okay\Modules\Sviat\PersonalData\Core\SchemaState;
use Okay\Modules\Sviat\PersonalData\Forms\FormDescriptor;
use Okay\Modules\Sviat\PersonalData\Forms\FormRegistry;
use Okay\Modules\Sviat\PersonalData\Forms\FormSettings;
use Okay\Modules\Sviat\PersonalData\Plugins\ConfirmPersonalDataProcessingPlugin;
use Smarty\Smarty;
use Modules\Sviat\PersonalData\Support\ModuleTestCase;

/**
 * Чекбокс згоди, як його малює плагін через справжній шаблон модуля в окремому Smarty 5.
 * Налаштування й маркер схеми живуть в одній фейковій базі, тож `statements` — усі запити рендеру. Адресу сторінки
 * політики будує справжній Router::generateUrl; підмінено лише таблицю роутів.
 */
class CheckboxRenderTest extends ModuleTestCase
{
    use BuildsSchemaState;
    use RoutesPolicyPages;

    private const string MODULE = __DIR__ . '/../../../../Okay/Modules/Sviat/PersonalData';
    private const int LANG_ID = 1;
    private const int POLICY_PAGE = 41;

    private FakeDatabase $database;

    private string $root;

    /** @var array<string, mixed> */
    private array $designVars = [];

    /** @var array<string, string> */
    private array $translations = [];

    private Smarty $smarty;

    private RequestState $requestState;

    private RenderProof $proof;

    protected function setUp(): void
    {
        $this->routePolicyPages();
        $_SESSION = [];
        $this->root = sys_get_temp_dir() . '/sviat-pd-render-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/cache', 0777, true);
        mkdir($this->root . '/compiled', 0777, true);

        $this->database = new FakeDatabase([
            SchemaState::MARKER_KEY => SchemaState::MARKER_VERSION,
            'sviat__personal_data__policy_page_id' => (string) self::POLICY_PAGE,
            'sviat__personal_data__form_callback' => '1',
            'sviat__personal_data__form_cart' => '1',
            'sviat__personal_data__form_comment_product' => '1',
            'sviat__personal_data__form_fast_order' => '1',
        ]);
        $this->replaceServiceInContainer(\Okay\Core\Database::class, $this->database);
        // MainHelper передає видимі сторінки в дизайн на кожному запиті вітрини.
        $this->designVars = ['pages' => [(object) ['id' => '3', 'url' => 'about'], (object) ['id' => (string) self::POLICY_PAGE, 'url' => 'privacy']]];

        $lang = [];
        include self::MODULE . '/design/lang/ua.php';
        $this->translations = $lang;

        $this->smarty = new Smarty();
        $this->smarty->setTemplateDir(self::MODULE . '/design/html');
        $this->smarty->setCompileDir($this->root . '/compiled');
        $this->requestState = new RequestState();
        $this->proof = new RenderProof();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        $_SESSION = [];
        $this->restorePolicyRoutes();
        parent::tearDown();
    }

    public function testCheckboxNotPrechecked(): void
    {
        $input = $this->dom($this->plugin()->run(['form' => 'callback']))->querySelector('input[type=checkbox]');

        self::assertNotNull($input);
        self::assertFalse($input->hasAttribute('checked'));
        self::assertTrue($input->hasAttribute('required'));
        self::assertSame('sviat__personal_data__confirm', $input->getAttribute('name'));
        self::assertSame('1', $input->getAttribute('value'));
    }

    public function testInputVisuallyHiddenNotDisplayNone(): void
    {
        $input = $this->dom($this->plugin()->run(['form' => 'callback']))->querySelector('input[type=checkbox]');
        $classes = explode(' ', (string) $input?->getAttribute('class'));
        self::assertContains('sviat-pd__input', $classes);
        self::assertContains('checkbox__input', $classes);

        $rules = $this->inputRules();
        self::assertNotSame([], $rules, 'у CSS модуля немає правила для поля');
        foreach ($rules as $selector => $body) {
            self::assertDoesNotMatchRegularExpression('~display\s*:\s*none~i', $body, $selector);
        }
        $all = implode(';', $rules);
        // Тема ховає .checkbox__input через display:none; правило модуля мусить повернути полю бокс.
        self::assertMatchesRegularExpression('~display\s*:\s*(?!none)\w+~i', $all);
        self::assertMatchesRegularExpression('~opacity\s*:\s*0\b~', $all);
        self::assertMatchesRegularExpression('~position\s*:\s*absolute~', $all);
    }

    /**
     * Квадратик — розмітка чекбокса тем OkayCMS: поле й svg.checkbox__icon одразу за ним усередині label.checkbox,
     * тож тема малює його своїм CSS (`.checkbox__input:checked + * .checkbox__mark`). Посилання на умови й помилка
     * лежать поза міткою: клік по них не перемикає чекбокс.
     */
    public function testThemeCheckboxMarkupNoOtherInteractiveInsideLabel(): void
    {
        $doc = $this->dom($this->plugin()->run(['form' => 'callback']));
        $box = $doc->querySelector('div.sviat-pd');
        self::assertNotNull($box);

        $label = $doc->querySelector('label.checkbox.sviat-pd__label');
        $input = $doc->querySelector('input.checkbox__input.sviat-pd__input');
        $more = $doc->querySelector('a.sviat-pd__more');
        $error = $doc->querySelector('label.error.sviat-pd__error');
        foreach ([$label, $more, $error] as $element) {
            self::assertNotNull($element);
            self::assertSame($box, $element->parentElement, 'мітка, посилання й помилка — діти одного .sviat-pd');
        }
        self::assertSame($label, $input?->parentElement, 'поле всередині label.checkbox, як у темі');

        $icon = $input->nextElementSibling;
        self::assertSame('svg', $icon?->localName);
        // .error — клас теми для помилок: його колір дає червону рамку квадратика при відмові.
        self::assertSame('checkbox__icon error', $icon->getAttribute('class'));
        self::assertSame('true', $icon->getAttribute('aria-hidden'));
        self::assertNotNull($icon->querySelector('.checkbox__mark'));

        self::assertCount(0, $label->querySelectorAll('button, a, select, textarea, label'));
        self::assertCount(1, $label->querySelectorAll('input'));
        self::assertSame($input->getAttribute('id'), $label->getAttribute('for'));
        self::assertSame($error->getAttribute('id'), $input->getAttribute('aria-describedby'));
        self::assertSame('sviat-pd-callback-error', $error->getAttribute('id'));
        // label.error без for: jQuery Validate знаходить її за aria-describedby і пише свій текст у неї,
        // а не вставляє нову мітку між полем і квадратиком.
        self::assertFalse($error->hasAttribute('for'));
    }

    /**
     * Зона дотику мітки й посилання — щонайменше 44 px без зміни висоти рядка: область вмісту рядкового елемента
     * не нижча за 1em, а вертикальні поля доповнюють її до 44 px за будь-якого розміру шрифту.
     */
    public function testInlineTouchTargetIsAtLeast44px(): void
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $this->css());
        foreach (['\.sviat-pd \.sviat-pd__label', '\.sviat-pd__more'] as $selector) {
            self::assertMatchesRegularExpression(
                '~(?:^|\})\s*' . $selector . '\s*\{[^}]*padding:\s*calc\(22px - \.5em\) 0[ ;]~',
                $css,
                $selector,
            );
        }
        foreach ([12, 14, 16, 20] as $fontSize) {
            self::assertGreaterThanOrEqual(44, $fontSize + 2 * (22 - .5 * $fontSize), "{$fontSize}px");
        }
        // Поля рядкового елемента не мають розсувати рядок: мітка й посилання лишаються рядковими.
        self::assertMatchesRegularExpression('~\.sviat-pd \.sviat-pd__label\s*\{[^}]*display:\s*inline;~', $css);
        self::assertDoesNotMatchRegularExpression('~\.sviat-pd__more\s*\{[^}]*display:~', $css);
        // Поля дотику позиційованої мітки теми не мають накривати посилання й помилку: дотик до них не ставить галочку.
        self::assertMatchesRegularExpression('~\.sviat-pd__more,\s*\.sviat-pd__error\s*\{[^}]*position:\s*relative~', $css);
    }

    /** Кінець підпису веде на сторінку політики з налаштувань у новій вкладці. */
    public function testLinkPointsToPolicyPageInNewTab(): void
    {
        $more = $this->dom($this->plugin()->run(['form' => 'callback']))->querySelector('a.sviat-pd__more');

        self::assertSame('/privacy', $more?->getAttribute('href'));
        self::assertSame('_blank', $more->getAttribute('target'));
        self::assertSame('noopener', $more->getAttribute('rel'));
        self::assertSame('умовами обробки персональних даних', trim($more->textContent));
        self::assertFalse($more->hasAttribute('aria-haspopup'));
    }

    /** Адреса — мовою покупця: префікс мови ставить ядро. */
    public function testLinkFollowsBuyerLanguage(): void
    {
        $this->speak(2);

        $more = $this->dom($this->plugin(pageLangId: 2)->run(['form' => 'callback']))->querySelector('a.sviat-pd__more');

        self::assertSame('/en/privacy', $more?->getAttribute('href'));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function noPolicyPage(): array
    {
        return [
            'не вибрано' => [['setting' => '0']],
            'прихована чи видалена' => [['pages' => [(object) ['id' => '3', 'url' => 'about']]]],
            'дизайн без сторінок' => [['pages' => null]],
            'слаг не пройшов білий список' => [['pages' => [(object) ['id' => '41', 'url' => '\\evil.example']]]],
        ];
    }

    /**
     * Без видимої сторінки політики підпис — простий текст у мітці, без посилання: битого посилання не буває.
     *
     * @param array<string, mixed> $case
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('noPolicyPage')]
    public function testNoVisiblePolicyPageMeansPlainText(array $case): void
    {
        if (isset($case['setting'])) {
            $this->database->settings['sviat__personal_data__policy_page_id'] = $case['setting'];
        }
        if (array_key_exists('pages', $case)) {
            $this->designVars['pages'] = $case['pages'];
        }

        $doc = $this->dom($this->plugin()->run(['form' => 'callback']));

        self::assertCount(0, $doc->querySelectorAll('a'));
        self::assertSame('Погоджуюся з умовами обробки персональних даних', trim((string) preg_replace('~\s+~u', ' ', (string) $doc->querySelector('label.sviat-pd__label')?->textContent)));
        self::assertSame('Погоджуюся з умовами обробки персональних даних', $this->accessibleName($doc));
    }

    public function testIdsUniqueAcrossFormsAndInstances(): void
    {
        $plugin = $this->plugin();
        $html = $plugin->run(['form' => 'callback'])
            . $plugin->run(['form' => 'cart'])
            . $plugin->run(['form' => 'comment_product', 'instance' => 5])
            . $plugin->run(['form' => 'comment_product', 'instance' => 6]);

        $ids = array_map(static fn($element) => $element->getAttribute('id'), iterator_to_array($this->dom($html)->querySelectorAll('[id]')));

        self::assertCount(16, $ids, '4 форми × (поле, мітка, посилання, помилка)');
        self::assertSame($ids, array_values(array_unique($ids)));
    }

    public function testLabelStringsInEveryLanguage(): void
    {
        foreach (['ua', 'en', 'ru', 'ge'] as $label) {
            $lang = [];
            include self::MODULE . "/design/lang/$label.php";
            self::assertSame(['sviat__personal_data__agree', 'sviat__personal_data__terms', 'sviat__personal_data__error'], array_keys($lang), $label);
            foreach ($lang as $key => $value) {
                self::assertNotSame('', trim((string) $value), "$label: $key");
            }
        }
        self::assertSame('Потрібна ваша згода', $this->translations['sviat__personal_data__error']);
    }

    /** Мова магазину без мовного файла модуля: підпис вбудований, а не порожній. */
    public function testMissingLabelTranslationShowsBuiltInText(): void
    {
        $this->translations = [];

        $doc = $this->dom($this->plugin()->run(['form' => 'callback']));

        self::assertSame('Погоджуюся з умовами обробки персональних даних', $this->accessibleName($doc));
    }

    public function testLabelIsEscaped(): void
    {
        $this->translations['sviat__personal_data__agree'] = '<img src=x onerror=alert(1)> Погоджуюся & з';
        $this->translations['sviat__personal_data__terms'] = 'умовами "обробки" <b>x</b>';

        $html = $this->plugin()->run(['form' => 'callback']);
        $doc = $this->dom($html);

        self::assertCount(0, $doc->querySelectorAll('img, b'));
        self::assertStringNotContainsString('&amp;lt;', $html, 'подвійне екранування');
        self::assertStringNotContainsString('&amp;amp;', $html, 'подвійне екранування');
        self::assertSame('<img src=x onerror=alert(1)> Погоджуюся & з', trim((string) $doc->querySelector('label.sviat-pd__label')?->textContent));
        self::assertSame('умовами "обробки" <b>x</b>', trim((string) $doc->querySelector('a.sviat-pd__more')?->textContent));
    }

    /** Слаг сторінки — дані з бази: адреса не веде на інший хост і не ламає атрибут. */
    public function testHostilePageSlugGivesNoLink(): void
    {
        $this->designVars['pages'] = [(object) ['id' => (string) self::POLICY_PAGE, 'url' => '\\evil.example']];
        self::assertCount(0, $this->dom($this->plugin()->run(['form' => 'callback']))->querySelectorAll('a'));

        $this->designVars['pages'] = [(object) ['id' => (string) self::POLICY_PAGE, 'url' => 'privacy"onmouseover="x']];
        $doc = $this->dom($this->plugin()->run(['form' => 'cart']));
        foreach ($doc->querySelectorAll('a') as $link) {
            self::assertFalse($link->hasAttribute('onmouseover'));
        }
    }

    public function testErrorTextIsEscaped(): void
    {
        $this->translations['sviat__personal_data__error'] = 'Позначте "згоду" <i>x</i>';
        $this->requestState->refuse('callback');

        $doc = $this->dom($this->plugin()->run(['form' => 'callback']));

        self::assertSame('Позначте "згоду" <i>x</i>', $doc->querySelector('input.sviat-pd__input')?->getAttribute('data-msg-required'));
        self::assertSame('Позначте "згоду" <i>x</i>', trim((string) $doc->querySelector('.sviat-pd__error')?->textContent));
        self::assertCount(0, $doc->querySelectorAll('i'));
    }

    /** Без перекладу в жодній мові помилка під чекбоксом і в data-msg-required — вбудований текст, а не порожньо. */
    public function testMissingErrorTranslationShowsBuiltInText(): void
    {
        unset($this->translations['sviat__personal_data__error']);
        $this->requestState->refuse('callback');

        $doc = $this->dom($this->plugin()->run(['form' => 'callback']));

        self::assertSame(ErrorText::BUILT_IN, $doc->querySelector('input.sviat-pd__input')?->getAttribute('data-msg-required'));
        self::assertSame(ErrorText::BUILT_IN, trim((string) $doc->querySelector('.sviat-pd__error')?->textContent));
    }

    public function testInstanceGivesUniqueIds(): void
    {
        $plugin = $this->plugin();
        $five = $this->dom($plugin->run(['form' => 'comment_product', 'instance' => 5]));
        $six = $this->dom($plugin->run(['form' => 'comment_product', 'instance' => 6]));
        $hostile = $this->dom($plugin->run(['form' => 'comment_product', 'instance' => '"><b>x']));

        self::assertSame('sviat-pd-comment_product-5', $five->querySelector('input')?->getAttribute('id'));
        self::assertSame('sviat-pd-comment_product-5-error', $five->querySelector('.sviat-pd__error')?->getAttribute('id'));
        self::assertSame('sviat-pd-comment_product-6', $six->querySelector('input')?->getAttribute('id'));
        self::assertSame('sviat-pd-comment_product-6', $six->querySelector('label')?->getAttribute('for'));
        self::assertMatchesRegularExpression('~^sviat-pd-comment_product-[\w-]+$~', (string) $hostile->querySelector('input')?->getAttribute('id'));
        self::assertCount(0, $hostile->querySelectorAll('b'));
    }

    public function testWithoutInstanceRendersOncePerRequest(): void
    {
        $plugin = $this->plugin();

        self::assertNotSame('', $plugin->run(['form' => 'callback']));
        self::assertSame('', $plugin->run(['form' => 'callback']));
        self::assertNotSame('', $plugin->run(['form' => 'cart']), 'інша форма малюється');
    }

    public function testRefusedStateShowsErrorAndAriaInvalid(): void
    {
        $plugin = $this->plugin();
        $this->requestState->refuse('callback');

        $refused = $this->dom($plugin->run(['form' => 'callback']));
        $other = $this->dom($plugin->run(['form' => 'cart']));

        self::assertSame('true', $refused->querySelector('input.sviat-pd__input')?->getAttribute('aria-invalid'));
        self::assertSame($this->translations['sviat__personal_data__error'], trim((string) $refused->querySelector('.sviat-pd__error')?->textContent));
        self::assertFalse((bool) $other->querySelector('input.sviat-pd__input')?->hasAttribute('aria-invalid'));
        self::assertSame('', trim((string) $other->querySelector('.sviat-pd__error')?->textContent));
        self::assertFalse((bool) $refused->querySelector('input.sviat-pd__input')?->hasAttribute('checked'), 'відмова не відновлює відмітку');
    }

    /** Доказ: мова сторінки й сторінка політики, на яку справді вело посилання показаної мітки. */
    public function testRecordsProofWithLanguageAndLinkedPage(): void
    {
        self::assertNull($this->proof->shown('callback'));

        $this->plugin(pageLangId: 2)->run(['form' => 'callback']);

        self::assertSame(['langId' => 2, 'policyPageId' => self::POLICY_PAGE], $this->proof->shown('callback'));
        self::assertNull($this->proof->shown('cart'), 'доказ лише для показаної форми');
    }

    /**
     * Мітка без посилання (сторінку сховано чи слаг не пройшов білий список) — у доказі null, хоча в налаштуваннях сторінка є.
     *
     * @param array<string, mixed> $case
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('noPolicyPage')]
    public function testPlainTextLabelRecordsNoPage(array $case): void
    {
        if (isset($case['setting'])) {
            $this->database->settings['sviat__personal_data__policy_page_id'] = $case['setting'];
        }
        if (array_key_exists('pages', $case)) {
            $this->designVars['pages'] = $case['pages'];
        }

        $this->plugin()->run(['form' => 'callback']);

        self::assertSame(['langId' => self::LANG_ID, 'policyPageId' => null], $this->proof->shown('callback'));
    }

    public function testPreMigrationRendersNothing(): void
    {
        unset($this->database->settings[SchemaState::MARKER_KEY]);
        $plugin = $this->plugin();
        $before = count($this->database->statements);

        $html = $plugin->run(['form' => 'callback']);

        self::assertSame('', $html);
        self::assertSame([], array_slice($this->database->statements, $before));
        self::assertArrayNotHasKey('sviat_pd', $this->designVars, 'шаблон не малювався');
        self::assertSame([], $_SESSION);
    }

    public function testDisabledOrUnavailableRendersNothing(): void
    {
        $plugin = $this->plugin();

        self::assertSame('', $plugin->run(['form' => 'feedback']), 'вимкнена форма');
        self::assertSame('', $plugin->run(['form' => 'fast_order']), 'модуль форми не активний');
        self::assertSame('', $this->plugin(activeModules: true)->run(['form' => 'feedback']));
        self::assertSame([], $_SESSION);
        self::assertNotSame('', $this->plugin(activeModules: true)->run(['form' => 'fast_order']), 'контроль: активний модуль');
    }

    public function testUnknownFormRendersNothing(): void
    {
        $plugin = $this->plugin();

        self::assertSame('', $plugin->run([]));
        self::assertSame('', $plugin->run(['form' => 'subscribe']));
        self::assertSame('', $plugin->run(['form' => ['callback']]));
        self::assertSame([], $_SESSION);
    }

    public function testRenderMakesZeroQueriesSteadyState(): void
    {
        $plugin = $this->plugin();
        $before = count($this->database->statements);

        $plugin->run(['form' => 'callback']);
        $plugin->run(['form' => 'cart']);

        self::assertSame([], array_slice($this->database->statements, $before));
        // Що лічильник бачить запити рендеру, показує testModuleMissingFromRunningListQueriesOncePerRequest.
    }

    public function testRenderWritesNothing(): void
    {
        $settings = $this->database->settings;
        $plugin = $this->plugin();

        $plugin->run(['form' => 'callback']);
        $plugin->run(['form' => 'comment_product', 'instance' => 3]);

        self::assertSame([], array_values(array_filter(
            $this->database->statements,
            static fn(string $statement) => preg_match('~^\s*(INSERT|UPDATE|DELETE|REPLACE)\b~i', $statement) === 1,
        )));
        self::assertSame($settings, $this->database->settings);
    }

    public function testPluginDefinitionMatchesConstructor(): void
    {
        $definitions = include self::MODULE . '/Init/SmartyPlugins.php';
        $definition = $definitions[ConfirmPersonalDataProcessingPlugin::class] ?? null;
        self::assertIsArray($definition);

        $expected = array_map(
            static fn(\ReflectionParameter $parameter) => (string) $parameter->getType(),
            new \ReflectionMethod(ConfirmPersonalDataProcessingPlugin::class, '__construct')->getParameters(),
        );
        $given = array_map(
            static fn(ServiceReference $reference) => $reference->getName(),
            $definition['arguments'],
        );

        self::assertSame($expected, $given);
    }

    public function testModuleFormRenderMakesZeroQueries(): void
    {
        $plugin = $this->plugin(modules: $this->realModules(['OkayCMS/FastOrder' => 1]));
        $before = count($this->database->statements);

        self::assertNotSame('', $plugin->run(['form' => 'fast_order']));
        self::assertNotSame('', $plugin->run(['form' => 'fast_order', 'instance' => 2]));
        self::assertSame([], array_slice($this->database->statements, $before));

        $inactive = $this->plugin(modules: $this->realModules(['OkayCMS/FastOrder' => 0]));
        $before = count($this->database->statements);
        self::assertSame('', $inactive->run(['form' => 'fast_order']));
        self::assertSame([], array_slice($this->database->statements, $before));
    }

    public function testModuleMissingFromRunningListQueriesOncePerRequest(): void
    {
        // Контроль лічильника: справжній Modules::isActiveModule() поза списком запущених іде в базу.
        $plugin = $this->plugin(modules: $this->realModules([]));
        $before = count($this->database->statements);

        self::assertSame('', $plugin->run(['form' => 'fast_order']));
        self::assertSame('', $plugin->run(['form' => 'fast_order', 'instance' => 2]));

        $queries = array_slice($this->database->statements, $before);
        self::assertCount(1, $queries);
        self::assertStringContainsString('__modules', $queries[0]);
    }

    public function testCheckboxNameIsTheWholeLabel(): void
    {
        $doc = $this->dom($this->plugin()->run(['form' => 'callback']));

        self::assertSame('Погоджуюся з умовами обробки персональних даних', $this->accessibleName($doc));
    }

    public function testBoxColoursFollowTheText(): void
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $this->css());

        self::assertDoesNotMatchRegularExpression('~#[0-9a-f]{3,8}\b~i', $css, 'кольори теми не вшиваються в модуль');
        self::assertDoesNotMatchRegularExpression('~::before|::after~', $css, 'квадратик малює тема, а не псевдоелементи модуля');
        self::assertMatchesRegularExpression('#\.sviat-pd__input:focus-visible\s*\+\s*\.checkbox__icon\s*\{[^}]*outline:[^;]*CanvasText#', $css, 'фокус системним кольором тексту: currentColor квадратика — колір помилок');
        // Форму квадратика задає лише запасне правило під :where(), яке не перебиває теми.
        preg_match_all('~([^{}]+)\{([^{}]*)\}~', $css, $rules, PREG_SET_ORDER);
        foreach ($rules as [, $selector, $body]) {
            if (str_contains($selector, '.checkbox__icon') && !str_starts_with(trim($selector), ':where(')) {
                self::assertDoesNotMatchRegularExpression('~(?<![-\w])(width|height|background|border|border-radius)\s*:~', $body, trim($selector));
            }
        }
    }

    /** Назва поля за accname: aria-labelledby, а без нього — текст label[for]. */
    private function accessibleName(\Dom\HTMLDocument $doc): string
    {
        $input = $doc->querySelector('input.sviat-pd__input');
        self::assertNotNull($input);
        $ids = array_filter(explode(' ', (string) $input->getAttribute('aria-labelledby')));
        if ($ids === []) {
            $text = (string) $doc->querySelector('label[for="' . $input->getAttribute('id') . '"]')?->textContent;
        } else {
            $text = implode(' ', array_map(static function (string $id) use ($doc): string {
                $element = $doc->getElementById($id);
                self::assertNotNull($element, "aria-labelledby: #$id");

                return (string) $element->textContent;
            }, $ids));
        }

        return trim((string) preg_replace('~\s+~u', ' ', $text));
    }

    /**
     * Справжній Modules без конструктора: isActiveModule() і getRunningModules() — код ядра, а запит
     * іде у фейкову базу, тож тест рахує його, а не виклик заглушки.
     *
     * @param array<string, int> $running `Vendor/Module` => is_active
     */
    private function realModules(array $running): Modules
    {
        $modules = new \ReflectionClass(Modules::class)->newInstanceWithoutConstructor();
        new \ReflectionProperty(Modules::class, 'db')->setValue($modules, $this->database);
        new \ReflectionProperty(Modules::class, 'queryFactory')->setValue($modules, new QueryFactory(new AuraQueryFactory('mysql')));
        $list = [];
        foreach ($running as $name => $active) {
            [$vendor, $module] = explode('/', $name);
            $list[$name] = ['vendor' => $vendor, 'module_name' => $module, 'is_active' => $active];
        }
        new \ReflectionProperty(Modules::class, 'runningModules')->setValue($modules, $list);

        return $modules;
    }

    private function plugin(bool $activeModules = false, ?Modules $modules = null, int $pageLangId = self::LANG_ID): ConfirmPersonalDataProcessingPlugin
    {
        $queryFactory = new QueryFactory(new AuraQueryFactory('mysql'));
        $languages = $this->createStub(Languages::class);
        $languages->method('getLangId')->willReturn($pageLangId);
        $languages->method('getMainLanguage')->willReturn((object) ['id' => self::LANG_ID, 'label' => 'ua']);
        $languages->method('getLangLabel')->willReturn('ua');
        $settings = new Settings($this->database, $languages, $queryFactory);

        $design = $this->createStub(Design::class);
        $design->method('assign')->willReturnCallback(function ($name, $value) {
            $this->designVars[$name] = $value;

            return null;
        });
        $design->method('getVar')->willReturnCallback(fn($name) => $this->designVars[$name] ?? null);
        $design->method('fetch')->willReturnCallback(function (string $template) {
            $this->smarty->clearAllAssign();
            $this->smarty->assign($this->designVars);

            return $this->smarty->fetch($template);
        });

        $translations = $this->createStub(FrontTranslations::class);
        $translations->method('getTranslation')->willReturnCallback(fn($key) => $this->translations[$key] ?? null);

        if ($modules === null) {
            $modules = $this->createStub(Modules::class);
            $modules->method('isActiveModule')->willReturn($activeModules);
        }

        return new ConfirmPersonalDataProcessingPlugin(
            $design,
            $this->schemaState($settings),
            $this->registry($modules),
            new FormSettings($settings),
            $languages,
            $translations,
            $this->proof,
            $this->requestState,
            new ErrorText($translations),
        );
    }

    private function registry(?Modules $modules = null): FormRegistry
    {
        $services = require self::MODULE . '/Init/services.php';
        $descriptors = array_values(array_filter(
            $services[FormRegistry::class]['arguments'],
            static fn($argument) => $argument instanceof FormDescriptor,
        ));

        return new FormRegistry($modules ?? $this->createStub(Modules::class), ...$descriptors);
    }

    private function dom(string $html): \Dom\HTMLDocument
    {
        return \Dom\HTMLDocument::createFromString('<!doctype html><html><body>' . $html . '</body></html>', LIBXML_NOERROR);
    }

    private function css(): string
    {
        return (string) file_get_contents(self::MODULE . '/design/css/personal_data.css');
    }

    /** @return array<string, string> селектор => тіло для правил, де поле — сам елемент, а не попередній сусід */
    private function inputRules(): array
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $this->css());
        preg_match_all('~([^{}@]+)\{([^{}]*)\}~', $css, $matches, PREG_SET_ORDER);
        $rules = [];
        foreach ($matches as [, $selector, $body]) {
            foreach (explode(',', $selector) as $single) {
                if (preg_match('#\.sviat-pd__input[^\s~+>]*\s*$#', trim($single)) === 1) {
                    $rules[trim($single)] = $body;
                }
            }
        }

        return $rules;
    }
}
