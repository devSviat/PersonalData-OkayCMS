<?php

namespace Modules\Sviat\PersonalData;

require_once __DIR__ . '/Support/ModuleTestCase.php';
require_once __DIR__ . '/Support/FakeDatabase.php';

use Aura\SqlQuery\QueryFactory as AuraQueryFactory;
use Modules\Sviat\PersonalData\Support\FakeDatabase;
use Okay\Core\Database;
use Okay\Core\QueryFactory;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\PersonalData\Forms\FormDescriptor;
use Okay\Modules\Sviat\PersonalData\Forms\RecordProbe;
use Okay\Modules\Sviat\PersonalData\Init\SettingsMigration;
use Modules\Sviat\PersonalData\Support\ModuleTestCase;

/** Перенесення стану форм з 1.0.1: старі `pd_*` → `sviat__personal_data__form_<key>`. */
class SettingsMigrationTest extends ModuleTestCase
{
    private const array FORMS = ['cart', 'register', 'feedback', 'callback', 'comment_product', 'comment_post', 'fast_order', 'back_in_stock'];

    public function testExistingConsentFormsKeepState(): void
    {
        $result = SettingsMigration::from101(
            ['pd_cart' => '1', 'pd_register' => '0', 'pd_feedback' => 1, 'pd_callback' => null, 'pd_comment_product' => '1'],
            self::FORMS,
        );

        self::assertTrue($result['cart']);
        self::assertFalse($result['register']);
        self::assertTrue($result['feedback']);
        self::assertFalse($result['callback']);
        self::assertTrue($result['comment_product']);
    }

    public function testNewFormsStartOff(): void
    {
        $old = ['pd_comment' => '1', 'pd_fast_order' => '1', 'pd_cart' => '1'];

        $result = SettingsMigration::from101($old, self::FORMS);

        foreach (['comment_post', 'fast_order', 'back_in_stock'] as $key) {
            self::assertFalse($result[$key], $key);
        }
        self::assertTrue($result['cart'], 'контроль: перенесена форма ввімкнена');
    }

    public function testEveryFormGetsAState(): void
    {
        self::assertSame(self::FORMS, array_keys(SettingsMigration::from101(['pd_cart' => '1'], self::FORMS)));
    }

    public function testOldKeysAreNotDeleted(): void
    {
        $old = ['pd_cart' => '1', 'pd_comment' => '1'];

        $result = SettingsMigration::from101($old, self::FORMS);

        self::assertSame([], array_values(array_filter(array_keys($result), static fn(string $key) => str_starts_with($key, 'pd_'))));
        self::assertSame(['pd_cart' => '1', 'pd_comment' => '1'], $old);
    }

    public function testRecordProbeReadsMaxIdOfRecordTable(): void
    {
        $database = new FakeDatabase();
        $database->maxIds['__orders'] = 4521;
        $this->replaceServiceInContainer(Database::class, $database);

        $maxId = $this->probe($database)->maxId($this->form(OrdersEntity::class));

        self::assertSame(4521, $maxId);
        self::assertMatchesRegularExpression('~^SELECT MAX\(id\) AS max_id FROM __orders$~', $database->statements[0]);
    }

    public function testRecordProbeEmptyTableGivesZero(): void
    {
        $database = new FakeDatabase();
        $this->replaceServiceInContainer(Database::class, $database);

        self::assertSame(0, $this->probe($database)->maxId($this->form(OrdersEntity::class)));
    }

    public function testRecordProbeFailedQueryThrows(): void
    {
        $database = new FakeDatabase();
        $database->maxIdQueryFails = true;
        $this->replaceServiceInContainer(Database::class, $database);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MAX(id)');

        $this->probe($database)->maxId($this->form(OrdersEntity::class));
    }

    public function testRecordProbeMissingEntityClassQueriesNothing(): void
    {
        $database = new FakeDatabase();
        $this->replaceServiceInContainer(Database::class, $database);

        self::assertSame(0, $this->probe($database)->maxId($this->form('Okay\\Modules\\Absent\\Module\\Entities\\RecordsEntity')));
        self::assertSame([], $database->statements);
    }

    private function probe(Database $database): RecordProbe
    {
        return new RecordProbe($database, new QueryFactory(new AuraQueryFactory('mysql')));
    }

    private function form(string $entity): FormDescriptor
    {
        return new FormDescriptor('cart', 'orders', [], 'cart.tpl', 'x', 'order', $entity, true, null, false);
    }
}
