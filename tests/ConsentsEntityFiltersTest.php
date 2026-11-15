<?php

namespace Modules\Sviat\PersonalData;

require_once __DIR__ . '/Support/ModuleTestCase.php';
require_once __DIR__ . '/Support/FakeDatabase.php';

use Aura\SqlQuery\QueryFactory as AuraQueryFactory;
use Modules\Sviat\PersonalData\Support\FakeDatabase;
use Okay\Core\Database;
use Okay\Core\QueryFactory;
use Okay\Core\QueryFactory\Select;
use Okay\Modules\Sviat\PersonalData\Entities\ConsentsEntity;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionProperty;
use Modules\Sviat\PersonalData\Support\ModuleTestCase;

/** Фільтри журналу згод: значення з адмінки йдуть лише параметрами, невідоме не розширює вибірку. */
class ConsentsEntityFiltersTest extends ModuleTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function states(): iterable
    {
        yield 'active' => ['active', 'pdc.withdrawn_at IS NULL AND pdc.anonymized_at IS NULL'];
        yield 'withdrawn' => ['withdrawn', 'pdc.withdrawn_at IS NOT NULL AND pdc.anonymized_at IS NULL'];
        yield 'anonymized' => ['anonymized', 'pdc.anonymized_at IS NOT NULL'];
    }

    #[DataProvider('states')]
    public function testStateFilter(string $state, string $condition): void
    {
        self::assertStringContainsString($condition, $this->apply('state', $state)[0]);
    }

    public function testUnknownStateMatchesNothing(): void
    {
        [$statement] = $this->apply('state', "active' OR '1'='1");

        self::assertStringContainsString('1 = 0', $statement);
        self::assertStringNotContainsString("'1'='1", $statement);
    }

    public function testPeriodIncludesBothDays(): void
    {
        [$statement, $bind] = $this->apply('given_from', '2026-11-01');
        self::assertStringContainsString('pdc.given_at >= :pdc_given_from', $statement);
        self::assertSame('2026-11-01 00:00:00', $bind['pdc_given_from']);

        [$statement, $bind] = $this->apply('given_to', '2026-11-15');
        self::assertStringContainsString('pdc.given_at < :pdc_given_to', $statement);
        self::assertSame('2026-11-16 00:00:00', $bind['pdc_given_to']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function badDates(): iterable
    {
        yield 'sql' => ["2026-11-01' OR '1'='1"];
        yield 'overflow' => ['2026-02-31'];
        yield 'datetime' => ['2026-11-01 10:00:00'];
        yield 'array' => [['2026-11-01']];
    }

    #[DataProvider('badDates')]
    public function testInvalidDateRejected(mixed $date): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->apply('given_to', $date);
    }

    public function testRecordIdsBindIntegers(): void
    {
        [$statement, $bind] = $this->apply('record_ids', ['6', '7 OR 1=1', 8]);

        self::assertStringContainsString('pdc.record_id IN (:pdc_record_ids)', $statement);
        self::assertSame([6, 7, 8], $bind['pdc_record_ids']);
    }

    /** Автофільтр ядра на порожньому списку зникає й віддав би всю таблицю. */
    public function testEmptyRecordIdsMatchNothing(): void
    {
        [$statement] = $this->apply('record_ids', []);

        self::assertStringContainsString('1 = 0', $statement);
        self::assertStringNotContainsString('record_id IN', $statement);
    }

    public function testNotAnonymized(): void
    {
        self::assertStringContainsString('pdc.anonymized_at IS NULL', $this->apply('not_anonymized', 1)[0]);
    }

    public function testAnonymizedBefore(): void
    {
        [$statement, $bind] = $this->apply('anonymized_before', '2023-11-15 03:47:00');

        self::assertStringContainsString('pdc.anonymized_at IS NOT NULL AND pdc.anonymized_at < :pdc_anonymized_before', $statement);
        self::assertSame('2023-11-15 03:47:00', $bind['pdc_anonymized_before']);
    }

    public function testAnonymizedBeforeRejectsInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->apply('anonymized_before', "2023-11-15' OR '1'='1");
    }

    public function testRankedAboveFollowsJournalOrder(): void
    {
        [$statement, $bind] = $this->apply('ranked_above', ['2026-11-01 10:00:00', 42]);

        self::assertStringContainsString('(pdc.given_at > :pdc_rank_at OR (pdc.given_at = :pdc_rank_at_same AND pdc.id > :pdc_rank_id))', $statement);
        self::assertSame(['pdc_rank_at' => '2026-11-01 10:00:00', 'pdc_rank_at_same' => '2026-11-01 10:00:00', 'pdc_rank_id' => 42], $bind);
    }

    public function testRankedAboveRejectsInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->apply('ranked_above', ["2026-11-01' OR '1'='1", 42]);
    }

    /** @return array{string, array<string, mixed>} */
    private function apply(string $filter, mixed $value): array
    {
        $this->replaceServiceInContainer(Database::class, new FakeDatabase());
        $entity = new ReflectionClass(ConsentsEntity::class)->newInstanceWithoutConstructor();
        $select = new QueryFactory(new AuraQueryFactory('mysql'))->newSelect();
        $select->cols(['pdc.id'])->from('__sviat__personal_data__consents AS pdc')->orderBy(['pdc.given_at DESC']);
        new ReflectionProperty($entity, 'select')->setValue($entity, $select);

        new \ReflectionMethod($entity, 'filter__' . $filter)->invoke($entity, $value, []);

        /** @var Select $select */
        $statement = preg_replace('~\s+~', ' ', str_replace('`', '', $select->getStatement()));

        return [$statement, $select->getBindValues()];
    }
}
