<?php

namespace Modules\Sviat\PersonalData;

use Okay\Core\Config;
use Okay\Core\Modules\Modules;
use Okay\Core\TplMod\Parser;
use Okay\Core\TplMod\TplMod;
use Okay\Modules\Sviat\PersonalData\Forms\FormRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Модифікація змушує TplMod розібрати шаблон і надрукувати його заново. Якщо друк губить
 * щось, крім пробілів, сторінка форми мовчки втрачає частину розмітки. Перевіряються файли
 * з module.json у кожній темі, шаблони форм модулів-власників і власні шаблони модуля.
 */
class TemplateRoundTripTest extends TestCase
{
    private const string NO_TEMPLATES = '';

    /** @return array<string, array{string}> */
    public static function templates(): array
    {
        $root = self::root();
        $json = json_decode((string) file_get_contents($root . '/Okay/Modules/Sviat/PersonalData/Init/module.json'), true);
        $files = array_column($json['modifications']['front'] ?? [], 'file');
        $themes = array_map(basename(...), glob($root . '/design/*', GLOB_ONLYDIR) ?: []);
        $paths = glob($root . '/Okay/Modules/Sviat/PersonalData/design/html/*.tpl') ?: [];
        foreach (self::registry()->all() as $form) {
            if ($form->requiredModule !== null) {
                $paths[] = "$root/Okay/Modules/{$form->requiredModule}/design/html/{$form->template}";
            }
        }
        foreach ($themes as $theme) {
            foreach ($files as $file) {
                $paths[] = "$root/design/$theme/html/$file";
            }
            foreach (self::registry()->all() as $form) {
                if ($form->requiredModule !== null) {
                    $paths[] = "$root/design/$theme/modules/{$form->requiredModule}/html/{$form->template}";
                    $paths[] = "$root/design/$theme/html/{$form->template}";
                }
            }
        }

        $cases = [];
        foreach (array_unique(array_filter($paths, is_file(...))) as $path) {
            $relative = substr($path, strlen($root) + 1);
            $cases[$relative] = [$relative];
        }

        return $cases === [] ? ['no templates' => [self::NO_TEMPLATES]] : $cases;
    }

    #[DataProvider('templates')]
    public function testTemplateSurvivesParseAndPrint(string $relativePath): void
    {
        if ($relativePath === self::NO_TEMPLATES) {
            self::markTestSkipped('ні тем, ні шаблонів модуля: перевіряти нічого');
        }
        $original = (string) file_get_contents(self::root() . '/' . $relativePath);

        self::assertSame(self::withoutWhitespace($original), self::withoutWhitespace($this->rebuild($original)), $relativePath);
    }

    public function testLessThanInTextBreaksRoundTrip(): void
    {
        $original = (string) file_get_contents(__DIR__ . '/fixtures/theme_lt/cart.tpl');

        self::assertNotSame(self::withoutWhitespace($original), self::withoutWhitespace($this->rebuild($original)));
    }

    private function rebuild(string $template): string
    {
        $tplMod = new TplMod(new Parser(), $this->createStub(Config::class));

        return new \ReflectionMethod(TplMod::class, 'build')->invoke($tplMod, new Parser()->parse($template));
    }

    private static function registry(): FormRegistry
    {
        $services = require self::root() . '/Okay/Modules/Sviat/PersonalData/Init/services.php';
        $modules = new \ReflectionClass(Modules::class)->newInstanceWithoutConstructor();

        return new FormRegistry($modules, ...array_slice($services[FormRegistry::class]['arguments'], 1));
    }

    private static function withoutWhitespace(string $template): string
    {
        return (string) preg_replace('~\s+~', '', $template);
    }

    private static function root(): string
    {
        return dirname(__DIR__, 4);
    }
}
