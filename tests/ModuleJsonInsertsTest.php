<?php

namespace Modules\Sviat\PersonalData;

use Okay\Core\Modules\Modules;
use Okay\Modules\Sviat\PersonalData\Forms\FormDescriptor;
use Okay\Modules\Sviat\PersonalData\Forms\FormRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Модифікації модуля вставляють у тему лише статичний тег плагіна з ключем форми з реєстру,
 * перед кнопкою відправки за анкером дескриптора. Нічого змінного чи редагованого з адмінки.
 */
class ModuleJsonInsertsTest extends TestCase
{
    public function testModuleJsonInsertsAreStaticRegistryTags(): void
    {
        $registry = $this->registry();
        $inserted = [];
        foreach ($this->frontModifications() as $modification) {
            foreach ($modification['changes'] as $change) {
                self::assertSame(['like', 'appendBefore'], array_keys($change), $modification['file']);
                self::assertMatchesRegularExpression('~^\{confirm_personal_data_processing form="([a-z_]+)"\}$~', $change['appendBefore']);
                preg_match('~form="([a-z_]+)"~', $change['appendBefore'], $m);
                $form = $registry->get($m[1]);

                self::assertNotNull($form, "невідома форма {$m[1]}");
                self::assertFalse($form->integration, 'інтегровані форми малює блок дизайну, а не модифікація');
                self::assertSame($form->template, $modification['file']);
                self::assertSame($form->anchor, $change['like']);
                $inserted[] = $form->key;
            }
        }

        $adapted = array_map(
            static fn(FormDescriptor $form) => $form->key,
            array_filter($registry->all(), static fn(FormDescriptor $form) => !$form->integration),
        );
        sort($adapted);
        sort($inserted);
        self::assertSame($adapted, $inserted, 'кожна форма з адаптером рівно один раз');
    }

    /** @return list<array{file: string, changes: list<array<string, string>>}> */
    private function frontModifications(): array
    {
        $json = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/Okay/Modules/Sviat/PersonalData/Init/module.json'), true);

        return $json['modifications']['front'];
    }

    private function registry(): FormRegistry
    {
        $services = require dirname(__DIR__, 4) . '/Okay/Modules/Sviat/PersonalData/Init/services.php';

        return new FormRegistry($this->createStub(Modules::class), ...array_slice($services[FormRegistry::class]['arguments'], 1));
    }
}
