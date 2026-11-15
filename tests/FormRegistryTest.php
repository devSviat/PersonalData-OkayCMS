<?php

namespace Modules\Sviat\PersonalData;

use Okay\Core\Modules\Modules;
use Okay\Helpers\ValidateHelper;
use Okay\Modules\Sviat\PersonalData\Forms\FormDescriptor;
use Okay\Modules\Sviat\PersonalData\Forms\FormRegistry;
use PHPUnit\Framework\TestCase;

class FormRegistryTest extends TestCase
{
    private const string MODULE = __DIR__ . '/../../../../Okay/Modules/Sviat/PersonalData';

    private const array KEYS = [
        'cart', 'register', 'feedback', 'callback', 'comment_product', 'comment_post',
        'fast_order', 'back_in_stock', 'parts_selection', 'order_return', 'review_request',
    ];

    public function testRegistryHasElevenDescriptorsWithoutSubscribe(): void
    {
        $registry = $this->registry();
        $keys = array_map(static fn(FormDescriptor $form) => $form->key, $registry->all());

        self::assertSame(self::KEYS, $keys);
        self::assertNull($registry->get('subscribe'));
        self::assertSame('sviat__personal_data__form_cart', $registry->get('cart')?->settingKey());
    }

    public function testDescriptorRejectsUnknownGroup(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FormDescriptor('cart', 'marketing', ['email'], 'cart.tpl', 'x', 'order', 'X', true, null, false);
    }

    public function testRegistryRejectsDuplicateKeys(): void
    {
        $form = new FormDescriptor('cart', 'orders', ['email'], 'cart.tpl', 'x', 'order', 'X', true, null, false);

        $this->expectException(\InvalidArgumentException::class);

        new FormRegistry($this->createStub(Modules::class), $form, $form);
    }

    public function testIpOnlyWhereRecordHasNone(): void
    {
        $withoutIp = array_values(array_map(
            static fn(FormDescriptor $form) => $form->key,
            array_filter($this->registry()->all(), static fn(FormDescriptor $form) => !$form->recordHasIp),
        ));

        self::assertSame(['register', 'callback', 'back_in_stock', 'parts_selection', 'order_return'], $withoutIp);
    }

    public function testAvailabilityFollowsRequiredModule(): void
    {
        $modules = $this->createMock(Modules::class);
        $modules->expects(self::once())->method('isActiveModule')->with('Sviat', 'PartsSelection')->willReturn(false);
        $registry = $this->registry($modules);

        self::assertTrue($registry->isAvailable('cart'));
        self::assertFalse($registry->isAvailable('parts_selection'));
        self::assertFalse($registry->isAvailable('subscribe'));
    }

    public function testSubscribeLinkIsGone(): void
    {
        $init = (string) file_get_contents(self::MODULE . '/Init/Init.php');

        self::assertStringNotContainsString('getSubscribeValidateError', $init);
        self::assertStringNotContainsString('Subscribe', implode("\n", array_map(
            static fn(string $file) => (string) file_get_contents($file),
            glob(self::MODULE . '/Forms/Adapters/*.php') ?: [],
        )));
        self::assertTrue(method_exists(ValidateHelper::class, 'getSubscribeValidateError'), 'метод ядра на місці, тож відсутність ланки не випадкова');
    }

    public function testCheckboxTemplateHasNoNeedConfirm(): void
    {
        $tpl = (string) file_get_contents(self::MODULE . '/design/html/confirm_personal_data_processing.tpl');

        self::assertStringNotContainsString('need_confirm', $tpl);
        foreach (['ua', 'en', 'ru'] as $lang) {
            self::assertStringNotContainsString('description_1', (string) file_get_contents(self::MODULE . "/Backend/lang/$lang.php"));
        }
    }

    private function registry(?Modules $modules = null): FormRegistry
    {
        $services = require self::MODULE . '/Init/services.php';
        $arguments = $services[FormRegistry::class]['arguments'];
        $descriptors = array_values(array_filter($arguments, static fn($argument) => $argument instanceof FormDescriptor));
        self::assertCount(count($arguments) - 1, $descriptors, 'перший аргумент — Modules, решта — дескриптори');

        return new FormRegistry($modules ?? $this->createStub(Modules::class), ...$descriptors);
    }
}
