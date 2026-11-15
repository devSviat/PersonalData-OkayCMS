<?php

namespace Modules\Sviat\PersonalData;

require_once __DIR__ . '/Support/ModuleTestCase.php';
require_once __DIR__ . '/Support/RequestStub.php';
require_once __DIR__ . '/ConsentLogHelperTest.php';
require_once __DIR__ . '/Support/PrgSpy.php';
require_once __DIR__ . '/Support/BuildsMigratedSchema.php';

use Modules\Sviat\PersonalData\Support\BuildsMigratedSchema;
use Modules\Sviat\PersonalData\Support\PrgSpy;
use Okay\Admin\Controllers\IndexAdmin;
use Okay\Core\Design;
use Okay\Core\Response;
use Okay\Modules\Sviat\PersonalData\Backend\Controllers\ConsentLogAdmin;
use Okay\Modules\Sviat\PersonalData\Backend\Helpers\ConsentLogHelper;
use ReflectionClass;
use ReflectionProperty;
use Smarty\Smarty;
use Modules\Sviat\PersonalData\Support\ModuleTestCase;

/** Сторінка журналу, як її бачить адмін: чипи стану, діалоги з наслідками, повідомлення після дії, кнопки. */
class ConsentLogTemplateTest extends ModuleTestCase
{
    use BuildsMigratedSchema;

    private const string MODULE = __DIR__ . '/../../../../Okay/Modules/Sviat/PersonalData';
    private const string CORE_HTML = __DIR__ . '/../../../../backend/design/html';

    private ?string $compileDir = null;

    protected function tearDown(): void
    {
        if ($this->compileDir !== null && is_dir($this->compileDir)) {
            array_map('unlink', glob($this->compileDir . '/*') ?: []);
            rmdir($this->compileDir);
        }
        parent::tearDown();
    }

    public function testStateChipsWithWhoAndWhen(): void
    {
        $html = $this->render(['sviat_pd_rows' => [
            self::row(1, 'active'),
            self::row(2, 'withdrawn', ['withdrawnBy' => 'anna', 'withdrawnAt' => '2026-11-05 12:30:00']),
            self::row(3, 'anonymized', ['recordId' => null, 'ip' => '', 'anonymizedAt' => '2026-11-06 08:00:00']),
        ]]);

        self::assertStringContainsString('sviat-pd-chip--active">Дана<', $html);
        self::assertStringContainsString('sviat-pd-chip--withdrawn">Відкликано<', $html);
        self::assertStringContainsString('sviat-pd-chip--anonymized">Знеособлено<', $html);
        self::assertMatchesRegularExpression('~Відкликано</span>\s*<span class="sviat-pd-log__who">anna, <time datetime="2026-11-05 12:30:00">~u', $html);
        self::assertMatchesRegularExpression('~Знеособлено</span>\s*<span class="sviat-pd-log__who"><time datetime="2026-11-06 08:00:00">~u', $html);
    }

    public function testActionsHaveConfirmTextWithConsequences(): void
    {
        $html = $this->render(['sviat_pd_rows' => [self::row(1, 'active'), self::row(2, 'withdrawn')]]);

        $withdraw = self::dialog($html, 'withdraw');
        $anonymize = self::dialog($html, 'anonymize');
        self::assertStringContainsString('aria-describedby="sviat-pd-confirm-withdraw-text"', $withdraw);
        self::assertStringContainsString('не видаляється', $withdraw);
        self::assertStringContainsString('незворотн', $anonymize);
        self::assertStringContainsString('IP', $anonymize);
        self::assertStringContainsString('зв&#039;язок із записом', $anonymize);
        foreach ([$withdraw, $anonymize] as $dialog) {
            self::assertStringContainsString('name="session_id" value="sess-token"', $dialog);
            self::assertStringContainsString('method="post"', $dialog);
            self::assertMatchesRegularExpression('~<button type="button"[^>]*data-dismiss="modal"[^>]*autofocus~', $dialog, 'фокус на безпечній кнопці');
        }
        self::assertSame(1, substr_count($html, 'data-target="#sviat-pd-confirm-withdraw"'), 'лише активний рядок можна відкликати');
        self::assertSame(2, substr_count($html, 'data-target="#sviat-pd-confirm-anonymize"'));
    }

    public function testSuccessFlashAfterAction(): void
    {
        $html = $this->render(['message_success' => 'anonymized']);
        self::assertMatchesRegularExpression('~role="status">\s*<div class="alert__content">\s*<div class="alert__title">Згоду знеособлено</div>~u', $html);

        $html = $this->render(['message_error' => 'refused']);
        self::assertStringContainsString('role="alert"', $html);

        $prg = new PrgSpy();
        $helper = $this->createMock(ConsentLogHelper::class);
        $helper->expects(self::once())->method('withdraw')->with(7, 'olena')->willReturn(true);
        $this->runController(new LogRequest(['action' => 'withdraw', 'id' => '7'], [], 'POST', true), $helper, $prg);
        self::assertSame(['withdrawn'], $prg->success);
        self::assertCount(1, $prg->redirects, 'PRG: після POST — редірект');
    }

    public function testRefusedActionFlashesError(): void
    {
        $prg = new PrgSpy();
        $helper = $this->createStub(ConsentLogHelper::class);
        $helper->method('anonymize')->willReturn(false);

        $this->runController(new LogRequest(['action' => 'anonymize', 'id' => '7'], [], 'POST', true), $helper, $prg);

        self::assertSame([], $prg->success);
        self::assertSame(['refused'], $prg->errors);
        self::assertCount(1, $prg->redirects);
    }

    public function testActionsAreFocusableButtons(): void
    {
        $html = $this->render(['sviat_pd_rows' => [self::row(1, 'active'), self::row(2, 'withdrawn')]]);

        preg_match_all('~<(\w+)\b[^>]*data-sviat-pd-id=~', $html, $m);
        self::assertSame(['button', 'button', 'button'], $m[1]);
        self::assertSame(3, preg_match_all('~<button type="button"[^>]*data-sviat-pd-id=~', $html));
        self::assertStringNotContainsString('tabindex="-1"', $html);
    }

    public function testRowShowsPolicyPageNotText(): void
    {
        $html = $this->render(['sviat_pd_rows' => [
            self::row(1, 'active', ['policyPageId' => 7, 'policyPageName' => 'Політика <конфіденційності>', 'policyPageUrl' => '/backend/index.php?controller=PageAdmin&id=7']),
            self::row(2, 'active', ['policyPageId' => 99]),
            self::row(3, 'active', ['policyPageId' => null]),
        ]]);

        self::assertStringContainsString('<a href="/backend/index.php?controller=PageAdmin&amp;id=7">Політика &lt;конфіденційності&gt;</a>', $html);
        self::assertStringContainsString('Сторінку видалено #99', $html);
        self::assertStringContainsString('Не вибрано', $html);
        $source = (string) file_get_contents(self::MODULE . '/Backend/design/html/consent_log.tpl');
        self::assertDoesNotMatchRegularExpression('~text_id|textId|version~', $source);
    }

    public function testEmptyStatesDiffer(): void
    {
        self::assertStringContainsString('Згод ще немає', $this->render([]));
        self::assertStringContainsString('За цими фільтрами згод немає', $this->render(['sviat_pd_filtered' => true]));
    }

    public function testExportFormIsPostWithSession(): void
    {
        $html = $this->render(['sviat_pd_rows' => [self::row(1, 'active')], 'sviat_pd_total' => 1]);

        self::assertMatchesRegularExpression('~<form method="post" class="sviat-pd-log-bar__export">\s*<input type="hidden" name="session_id" value="sess-token">\s*<input type="hidden" name="action" value="export">~', $html);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function row(int $id, string $state, array $extra = []): array
    {
        return $extra + [
            'id' => $id, 'form' => 'cart', 'formTitle' => 'Кошик', 'recordId' => 100 + $id, 'recordUrl' => null,
            'lang' => 'Українська', 'givenAt' => '2026-11-01 10:00:00', 'policyPageId' => null, 'policyPageName' => null,
            'policyPageUrl' => null, 'ip' => '192.0.2.x', 'state' => $state, 'withdrawnAt' => null, 'withdrawnBy' => null,
            'anonymizedAt' => null,
        ];
    }

    private static function dialog(string $html, string $action): string
    {
        self::assertMatchesRegularExpression('~<div id="sviat-pd-confirm-' . $action . '".*?</form>~s', $html);
        preg_match('~<div id="sviat-pd-confirm-' . $action . '".*?</form>~s', $html, $m);

        return $m[0];
    }

    /** @param array<string, mixed> $vars */
    private function render(array $vars): string
    {
        $this->compileDir ??= sys_get_temp_dir() . '/okay-pd-log-tpl-' . bin2hex(random_bytes(4));
        if (!is_dir($this->compileDir)) {
            mkdir($this->compileDir);
        }
        $_SESSION['id'] = 'sess-token';

        $smarty = new Smarty();
        $smarty->setCompileDir($this->compileDir);
        $smarty->setTemplateDir([self::MODULE . '/Backend/design/html', self::CORE_HTML]);
        $smarty->registerPlugin('function', 'url', static fn(array $params) => '?' . http_build_query($params));
        $smarty->registerPlugin('modifier', 'date', static fn($value) => $value === null ? '' : date('d.m.Y', strtotime((string) $value)));
        $smarty->registerPlugin('modifier', 'time', static fn($value) => $value === null ? '' : date('H:i', strtotime((string) $value)));
        foreach (['in_array', 'count', 'floor', 'ceil', 'min', 'max'] as $function) {
            $smarty->registerPlugin('modifier', $function, $function);
        }
        $lang = [];
        include self::MODULE . '/Backend/lang/ua.php';
        $smarty->assign('btr', new class ($lang) {
            /** @param array<string, string> $lang */
            public function __construct(private readonly array $lang) {}

            public function __get(string $key): string
            {
                return $this->lang[$key] ?? $key;
            }

            public function __isset(string $key): bool
            {
                return true;
            }

            public function getTranslation(string $key): string
            {
                return $this->lang[$key] ?? $key;
            }
        });
        $smarty->assign('manager', (object) ['login' => 'olena', 'permissions' => ['sviat__personal_data', 'sviat__personal_data__log']]);
        $smarty->assign($vars + [
            'sviat_pd_tab' => 'log',
            'sviat_pd_needs_update' => false,
            'message_success' => false,
            'message_error' => false,
            'sviat_pd_forms' => ['cart' => 'Кошик'],
            'sviat_pd_languages' => [1 => 'Українська'],
            'sviat_pd_values' => ['form' => '', 'from' => '', 'to' => '', 'lang_id' => '', 'state' => '', 'record' => ''],
            'sviat_pd_rows' => [],
            'sviat_pd_total' => count($vars['sviat_pd_rows'] ?? []),
            'sviat_pd_filtered' => false,
            'pages_count' => 1,
            'current_page' => 1,
        ]);

        return $smarty->fetch('consent_log.tpl');
    }

    private function runController(LogRequest $request, ConsentLogHelper $helper, PrgSpy $prg): void
    {
        $schema = $this->migratedSchema();
        $design = $this->createStub(Design::class);
        $design->method('fetch')->willReturn('');
        $controller = new ReflectionClass(ConsentLogAdmin::class)->newInstanceWithoutConstructor();
        $properties = [
            'design' => $design,
            'request' => $request,
            'response' => $this->createStub(Response::class),
            'postRedirectGet' => $prg,
            'manager' => (object) ['login' => 'olena', 'permissions' => ['sviat__personal_data__log']],
        ];
        foreach ($properties as $name => $value) {
            new ReflectionProperty(IndexAdmin::class, $name)->setValue($controller, $value);
        }

        $controller->fetch($helper, $schema);
    }
}
