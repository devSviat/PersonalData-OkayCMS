<?php

namespace Modules\Sviat\PersonalData;

use Okay\Core\Config;
use Okay\Core\Design;
use Okay\Core\DesignBlocks;
use Okay\Core\EntityFactory;
use Okay\Core\SmartyPlugins\Plugin;
use Okay\Core\SmartyPlugins\Plugins\GetDesignBlock;
use PHPUnit\Framework\TestCase;
use Smarty\Smarty;

/**
 * Вимкнений PersonalData не ламає сторінок. Шаблони теми, скомпільовані з його вставками, лишаються в compiled/,
 * а ядро для вимкненого модуля реєструє замість плагіна заглушку `fn() => ''` під тегом із властивості `$tag`
 * (Modules::startEnabledModules). Модулі-власники друкують `{get_design_block}`, який без модуля порожній.
 */
class DisabledModuleRenderTest extends TestCase
{
    private const string MODULE = __DIR__ . '/../../../../Okay/Modules/Sviat/PersonalData';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/sviat-pd-disabled-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/html', 0777, true);
        mkdir($this->root . '/compiled', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testStaleCompiledInsertsRenderEmptyWithCoreMock(): void
    {
        $inserts = $this->moduleJsonInserts();
        self::assertNotSame([], $inserts);
        file_put_contents($this->root . '/html/form.tpl', implode('', array_map(
            static fn(string $insert) => $insert . '<button type="submit">go</button>',
            $inserts,
        )));

        $active = $this->smarty();
        foreach ($this->pluginTags() as $type => $tags) {
            foreach ($tags as $tag) {
                $active->registerPlugin($type, $tag, static fn() => '<div class="sviat-pd"></div>');
            }
        }
        self::assertStringContainsString('sviat-pd', $active->fetch('form.tpl'));

        $disabled = $this->smarty();
        foreach ($this->pluginTags() as $type => $tags) {
            foreach ($tags as $tag) {
                $disabled->registerPlugin($type, $tag, fn() => '');
            }
        }

        self::assertSame(str_repeat('<button type="submit">go</button>', count($inserts)), $disabled->fetch('form.tpl'));
    }

    public function testOwnerModuleBlockIsEmptyWithoutPersonalData(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('get')->willReturn(false);
        $design = $this->createStub(Design::class);
        $blocks = new DesignBlocks($design, $this->createStub(EntityFactory::class));

        $html = new GetDesignBlock($blocks, $design, $config)->run([
            'block' => 'sviat_personal_data_parts_selection',
            'vars' => ['sviat_pd_instance' => 7],
        ]);

        self::assertSame('', $html);
    }

    private function smarty(): Smarty
    {
        $smarty = new Smarty();
        $smarty->setTemplateDir($this->root . '/html');
        $smarty->setCompileDir($this->root . '/compiled');

        return $smarty;
    }

    /**
     * Теги так, як їх бачить ядро для вимкненого модуля: властивість `$tag` за замовчуванням і тип плагіна.
     *
     * @return array<string, list<string>>
     */
    private function pluginTags(): array
    {
        $tags = [];
        foreach (include self::MODULE . '/Init/SmartyPlugins.php' as $class => $definition) {
            $type = Plugin::resolveType($definition['class']);
            $tag = new \ReflectionClass($definition['class'])->getDefaultProperties()['tag'] ?? null;
            self::assertNotNull($type, $class);
            self::assertIsString($tag, $class);
            $tags[$type][] = $tag;
        }

        return $tags;
    }

    /** @return list<string> */
    private function moduleJsonInserts(): array
    {
        $json = json_decode((string) file_get_contents(self::MODULE . '/Init/module.json'), true, flags: JSON_THROW_ON_ERROR);
        $inserts = [];
        foreach ($json['modifications']['front'] ?? [] as $file) {
            foreach ($file['changes'] as $change) {
                $inserts[] = $change['appendBefore'] ?? $change['appendAfter'] ?? '';
            }
        }

        return array_values(array_filter($inserts));
    }
}
