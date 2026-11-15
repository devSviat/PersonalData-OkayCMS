<?php

namespace Modules\Sviat\PersonalData\Support;

require_once __DIR__ . '/BuildsSchemaState.php';
require_once __DIR__ . '/FakeDatabase.php';
require_once __DIR__ . '/MigratedSettings.php';

use Okay\Entities\ModulesEntity;
use Okay\Modules\Sviat\PersonalData\Core\SchemaState;

/** Справжній SchemaState після «Оновити»: маркер, таблиця й версія модуля на місці. */
trait BuildsMigratedSchema
{
    use BuildsSchemaState;

    private function migratedSchema(): SchemaState
    {
        $database = new FakeDatabase();
        $database->tables = ['ok_sviat__personal_data__consents'];
        $modules = $this->createStub(ModulesEntity::class);
        $modules->method('getByVendorModuleName')->willReturn((object) ['version' => '1.1.0']);

        return $this->schemaState(new MigratedSettings(), $database, $this->mockEntityFactory([ModulesEntity::class => $modules]));
    }
}
