<?php

namespace Modules\Sviat\PersonalData\Support;

use Okay\Core\Config;
use Okay\Core\Database;
use Okay\Core\EntityFactory;
use Okay\Core\QueryFactory;
use Okay\Core\QueryFactory\SqlQuery;
use Okay\Core\Settings;
use Okay\Modules\Sviat\PersonalData\Core\SchemaState;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** Для тестів на основі PHPUnit\Framework\TestCase. */
trait BuildsSchemaState
{
    /**
     * Деструктор Database безумовно кличе $this->pdo->disconnect(), а в дубля без конструктора pdo порожній.
     */
    private function withPdo(Database $database): Database
    {
        new \ReflectionProperty(Database::class, 'pdo')->setValue($database, new class {
            public function disconnect(): void {}
        });

        return $database;
    }

    private function schemaState(
        Settings $settings,
        ?Database $database = null,
        ?EntityFactory $entityFactory = null,
        ?LoggerInterface $logger = null,
        ?string $rootDir = null,
    ): SchemaState {
        $queryFactory = $this->createStub(QueryFactory::class);
        $queryFactory->method('newSqlQuery')->willReturnCallback(static fn() => new SqlQuery());
        $config = $this->createStub(Config::class);
        $config->method('get')->willReturn('ok_');

        return new SchemaState(
            $settings,
            $this->withPdo($database ?? $this->createStub(Database::class)),
            $queryFactory,
            $config,
            $entityFactory ?? $this->createStub(EntityFactory::class),
            $logger ?? new NullLogger(),
            $rootDir ?? sys_get_temp_dir() . '/sviat-pd-' . bin2hex(random_bytes(4)),
        );
    }
}
