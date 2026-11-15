<?php

namespace Modules\Sviat\PersonalData;

require_once __DIR__ . '/Support/ModuleTestCase.php';

use Okay\Core\Config;
use Okay\Core\Modules\DTO\ModificationDTO;
use Okay\Core\Modules\Module;
use Okay\Core\Modules\Modules;
use Okay\Core\TplMod\Parser;
use Okay\Core\TplMod\TplMod;
use Okay\Modules\Sviat\PersonalData\Forms\FormDescriptor;
use Okay\Modules\Sviat\PersonalData\Forms\FormRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Modules\Sviat\PersonalData\Support\ModuleTestCase;

/**
 * Чекбокс форми ядра вставляє модифікація перед кнопкою відправки. Якщо анкер у темі мертвий, сервер
 * чекбокса не малює, доказу показу немає, і згода в цій формі мовчки не вимагається.
 * Тема — з `OKAY_ACTIVE_THEME`; без змінної перевіряється кожна тема в design/.
 */
class ConsentAnchorsTest extends ModuleTestCase
{
    private const string NO_THEMES = '';

    private const string FIXTURES = __DIR__ . '/fixtures';

    protected function setUp(): void
    {
        // TplMod::buildFile читає disable_tpl_mod через ServiceLocator.
        $this->replaceServiceInContainer(Config::class, $this->createStub(Config::class));
    }

    /** @return array<string, array{string}> */
    public static function themes(): array
    {
        $active = (string) getenv('OKAY_ACTIVE_THEME');
        $themes = $active !== '' ? [$active] : array_map(basename(...), glob(self::root() . '/design/*', GLOB_ONLYDIR) ?: []);
        if ($themes === []) {
            return ['no themes' => [self::NO_THEMES]];
        }

        return array_combine($themes, array_map(static fn(string $theme) => [$theme], $themes));
    }

    #[DataProvider('themes')]
    public function testEveryCoreFormHasLiveAnchorInTheme(string $theme): void
    {
        if ($theme === self::NO_THEMES) {
            self::markTestSkipped('design/ без тем і OKAY_ACTIVE_THEME не задано: перевіряти нічого');
        }
        self::assertDirectoryExists(self::root() . "/design/$theme/html", 'OKAY_ACTIVE_THEME вказує на тему, якої немає');

        $dead = [];
        $multiple = [];
        foreach (self::coreForms() as $form) {
            $path = self::root() . "/design/$theme/html/{$form->template}";
            $count = is_file($path) ? $this->checkboxes((string) file_get_contents($path), $form) : 0;
            if ($count === 0) {
                $dead[] = "{$form->key} ({$form->template})";
            } elseif ($count > 1) {
                $multiple[] = "{$form->key}: $count";
            }
        }

        if ($multiple !== []) {
            fwrite(STDERR, "\n[$theme] кілька чекбоксів в одній формі: " . implode(', ', $multiple) . "\n");
        }
        self::assertSame([], $dead);
        self::assertCount(6, self::coreForms());
    }

    /** @return array<string, array{string, int}> фікстура кошика, скільки чекбоксів вставить модифікація */
    public static function cartFixtures(): array
    {
        return [
            'живий анкер' => ['theme_live', 1],
            'копія кошика без кнопки' => ['theme_dead', 0],
            'button без type — це submit' => ['theme_button_no_type', 1],
            'type="button" — не submit' => ['theme_type_button', 0],
            'прихована aria-hidden кнопка не рахується' => ['theme_hidden_default', 1],
            '«>» у {$lang->…} не ламає анкер' => ['theme_lang_arrow', 1],
            'кнопка на кількох рядках' => ['theme_multiline', 1],
            'дві кнопки' => ['theme_two_buttons', 2],
        ];
    }

    #[DataProvider('cartFixtures')]
    public function testCartAnchorOnFixture(string $fixture, int $expected): void
    {
        $cart = (string) file_get_contents(self::FIXTURES . "/$fixture/cart.tpl");

        self::assertSame($expected, $this->checkboxes($cart, self::form('cart')));
    }

    public function testHiddenDefaultSubmitGetsNoCheckbox(): void
    {
        $built = $this->build((string) file_get_contents(self::FIXTURES . '/theme_hidden_default/cart.tpl'), self::form('cart'));

        self::assertDoesNotMatchRegularExpression('~\{confirm_personal_data_processing form="cart"\}\s*<button type="submit" name="checkout" class="hidden"~', $built);
    }

    /** @return array<string, array{string}> */
    public static function integrationForms(): array
    {
        $keys = array_map(
            static fn(FormDescriptor $form) => $form->key,
            array_filter(self::registry()->all(), static fn(FormDescriptor $form) => $form->integration),
        );

        return array_combine($keys, array_map(static fn(string $key) => [$key], $keys));
    }

    /**
     * Чекбокс форми з фасадом малює блок дизайну в шаблоні модуля-власника. Без блоку доказу показу немає,
     * і згода в цій формі мовчки не вимагається.
     */
    #[DataProvider('integrationForms')]
    public function testOwnerTemplatePrintsConsentBlock(string $key): void
    {
        $form = self::form($key);
        if (!is_dir(self::root() . "/Okay/Modules/{$form->requiredModule}")) {
            self::markTestSkipped("модуля {$form->requiredModule} немає: шаблону власника перевіряти нічим");
        }
        $path = self::root() . "/Okay/Modules/{$form->requiredModule}/design/html/{$form->template}";
        self::assertFileExists($path);
        $block = 'get_design_block block="sviat_personal_data_' . $key . '"';
        $template = (string) file_get_contents($path);

        self::assertSame(1, substr_count($template, '{' . $block), $path);
    }

    public function testCheckboxPostsTheConfirmField(): void
    {
        $tpl = (string) file_get_contents(self::root() . '/Okay/Modules/Sviat/PersonalData/design/html/confirm_personal_data_processing.tpl');

        self::assertMatchesRegularExpression('~<input\b(?=[^>]*type="checkbox")[^>]*name="sviat__personal_data__confirm"~', $tpl);
    }

    private function checkboxes(string $template, FormDescriptor $form): int
    {
        return substr_count($this->build($template, $form), '{confirm_personal_data_processing form="' . $form->key . '"}');
    }

    /** Шаблон після модифікацій цього модуля для файла форми, як його збирає Design::applyTplModifiers. */
    private function build(string $template, FormDescriptor $form): string
    {
        $modification = array_find(
            new Module(new NullLogger())->getModuleParams('Sviat', 'PersonalData')->getFrontModifications(),
            static fn(ModificationDTO $modification) => $modification->getFile() === $form->template,
        );
        self::assertNotNull($modification, "module.json не модифікує {$form->template}");

        return (string) new TplMod(new Parser(), $this->createStub(Config::class))->buildFile($template, $modification->getChanges());
    }

    /** @return list<FormDescriptor> */
    private static function coreForms(): array
    {
        return array_values(array_filter(self::registry()->all(), static fn(FormDescriptor $form) => $form->requiredModule === null));
    }

    private static function form(string $key): FormDescriptor
    {
        $form = self::registry()->get($key);
        self::assertNotNull($form);

        return $form;
    }

    private static function registry(): FormRegistry
    {
        $services = require self::root() . '/Okay/Modules/Sviat/PersonalData/Init/services.php';
        $modules = new \ReflectionClass(Modules::class)->newInstanceWithoutConstructor();

        return new FormRegistry($modules, ...array_slice($services[FormRegistry::class]['arguments'], 1));
    }

    private static function root(): string
    {
        return dirname(__DIR__, 4);
    }
}
