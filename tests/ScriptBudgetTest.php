<?php

namespace Modules\Sviat\PersonalData;

use Okay\Core\TemplateConfig\Js;
use Okay\Core\TemplateConfig\JsConfig;
use PHPUnit\Framework\TestCase;

/** Скрипт згоди потрапляє в бандл теми на кожній сторінці, тож має бути крихітним і без HTML-вставок. */
class ScriptBudgetTest extends TestCase
{
    private const string DESIGN = __DIR__ . '/../../../../Okay/Modules/Sviat/PersonalData/design';
    private const int BUDGET = 512;

    public function testScriptUnderBudgetAndWritesNoHtml(): void
    {
        $source = (string) file_get_contents(self::DESIGN . '/js/personal_data.js');
        // Так його мініфікує ядро, зводячи JS модулів у бандл теми.
        $served = JsConfig::minifyJs($source);

        self::assertLessThanOrEqual(self::BUDGET, strlen($served), 'served bytes: ' . strlen($served));
        self::assertDoesNotMatchRegularExpression('~innerHTML|outerHTML|insertAdjacentHTML|document\.write|\beval\b|new Function~', $source);
    }

    /** Скрипт лишився лише для фокуса на відмові в захованій формі; без відмови він нічого не робить. */
    public function testScriptOnlyFocusesRefusedCheckbox(): void
    {
        $source = (string) file_get_contents(self::DESIGN . '/js/personal_data.js');

        self::assertStringContainsString(".sviat-pd__input[aria-invalid=true]", $source);
        self::assertStringContainsString('refused.focus()', $source);
        self::assertDoesNotMatchRegularExpression('~popover|sviat-pd-text|addEventListener~', $source);
    }

    public function testScriptIsRegisteredInFooter(): void
    {
        $registered = include self::DESIGN . '/js.php';
        $scripts = array_values(array_filter($registered, static fn($js) => $js instanceof Js && $js->getFilename() === 'personal_data.js'));

        self::assertCount(1, $scripts);
        // Скрипт шукає вікно й поля в DOM, тож має виконатися після розмітки сторінки.
        self::assertSame('footer', $scripts[0]->getPosition());
    }
}
