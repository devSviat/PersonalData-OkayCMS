<?php

namespace Modules\Sviat\PersonalData\Support;

use Okay\Core\Database;
use Okay\Core\EntityFactory;
use Okay\Core\ServiceLocator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Спільна база для тестів модулів.
 *
 * Майже кожен сервіс тут отримує EntityFactory і кличе ->get(FooEntity::class),
 * тож замість бази підставляється мапа FQCN → мок сутності.
 *
 * Скрізь, де немає expects(), використовується createStub(), а не createMock():
 * PHPUnit 13 на кожен мок без очікувань пише нотис "No expectations were
 * configured", і кількасот таких рядків топлять корисний вивід.
 *
 * ВИНЯТОК — mockEntity(): їй потрібні одночасно onlyMethods() і
 * disableOriginalConstructor(), а це дає лише MockBuilder, тобто мок. Тест, що
 * кличе mockEntity(), мусить сам нести #[AllowMockObjectsWithoutExpectations]:
 * атрибут читається тільки з конкретного класу і з базового не успадковується.
 */
#[AllowMockObjectsWithoutExpectations]
abstract class ModuleTestCase extends TestCase
{
    /** @var array<string, object|null> що лежало в контейнері до підміни */
    private array $replacedServices = [];

    /** @var list<string> сервіси модулів, яких тестовий контейнер не знав: їхні визначення прибираються після тесту */
    private array $addedDefinitions = [];

    protected function tearDown(): void
    {
        $this->restoreServicesInContainer();
        parent::tearDown();
    }

    /**
     * Підміняє сервіс у контейнері на час тесту.
     *
     * Потрібно там, де код дістає залежність не з конструктора, а сам:
     * QueryFactory\AbstractQuery бере Database через ServiceLocator, тож
     * ін'єкція фейка в Image нічого не дає — запит однаково пробує підняти
     * справжнє з'єднання. Локально воно є і тест зеленів помилково; у CI бази
     * немає, і та сама перевірка падала PDOException.
     */
    protected function replaceServiceInContainer(string $id, object $service): void
    {
        $container = (new \ReflectionProperty(ServiceLocator::class, 'DI'))
            ->getValue(ServiceLocator::getInstance());

        $store = new \ReflectionProperty($container, 'serviceStore');
        $current = $store->getValue($container);

        if (!array_key_exists($id, $this->replacedServices)) {
            $this->replacedServices[$id] = $current[$id] ?? null;
        }

        $current[$id] = $service;
        $store->setValue($container, $current);

        // Тестовий контейнер не завантажує Init/services.php модулів, а get() віддає лише визначені сервіси.
        $definitions = new \ReflectionProperty($container, 'services');
        $known = $definitions->getValue($container);
        if (!isset($known[$id])) {
            $known[$id] = ['class' => $service::class];
            $definitions->setValue($container, $known);
            $this->addedDefinitions[] = $id;
        }
    }

    private function restoreServicesInContainer(): void
    {
        if (!$this->replacedServices) {
            return;
        }

        $container = (new \ReflectionProperty(ServiceLocator::class, 'DI'))
            ->getValue(ServiceLocator::getInstance());

        if ($this->addedDefinitions) {
            $definitions = new \ReflectionProperty($container, 'services');
            $definitions->setValue($container, array_diff_key($definitions->getValue($container), array_flip($this->addedDefinitions)));
            $this->addedDefinitions = [];
        }

        $store = new \ReflectionProperty($container, 'serviceStore');
        $current = $store->getValue($container);

        foreach ($this->replacedServices as $id => $previous) {
            if ($previous === null) {
                unset($current[$id]);
            } else {
                $current[$id] = $previous;
            }
        }

        $store->setValue($container, $current);
        $this->replacedServices = [];
    }

    /**
     * Мок EntityFactory, що мапить FQCN → мок сутності.
     *
     * @param array<class-string, object> $entityMocks
     */
    protected function mockEntityFactory(array $entityMocks): EntityFactory
    {
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturnCallback(
            static fn (string $class) => $entityMocks[$class] ?? null
        );

        return $factory;
    }

    /**
     * Мок сутності з робочим fluent-ланцюжком.
     *
     * Дві пастки, обидві пекучі:
     *
     * 1. Мокати можна ЛИШЕ перелічені методи: Entity::$selectFields — приватне
     *    поле без значення за замовчуванням, тож на моці без конструктора
     *    справжній setSelectFields() падає на array_merge(null, ...).
     *
     * 2. Entity::mappedBy() оголошений final — PHPUnit його не підміняє. Тому
     *    ланцюжок фільтрується по тому, що реально можна замокати: final,
     *    статичні й неіснуючі методи відкидаються. Метод, який усередині кличе
     *    mappedBy(), протестувати цим моком не вийде — справжня реалізація
     *    звіряється з getFields(), якого на моці без конструктора немає.
     *
     * @param class-string $entityClass
     * @param string[] $extraMethods
     */
    protected function mockEntity(string $entityClass, array $extraMethods = [])
    {
        $chainMethods = array_values(array_filter(
            ['cols', 'noLimit', 'orderBy'],
            static function (string $method) use ($entityClass): bool {
                if (!method_exists($entityClass, $method)) {
                    return false;
                }
                $reflection = new \ReflectionMethod($entityClass, $method);
                return !$reflection->isFinal() && !$reflection->isStatic();
            }
        ));

        $entity = $this->getMockBuilder($entityClass)
            ->disableOriginalConstructor()
            ->onlyMethods(array_merge($chainMethods, $extraMethods))
            ->getMock();

        foreach ($chainMethods as $method) {
            $entity->method($method)->willReturnSelf();
        }

        return $entity;
    }

    /**
     * Мок Database, що стрінгує кожен переданий у query() запит у $captured.
     *
     * @param array<int, mixed> $results
     * @param array<int, string> $captured  Заповнюється за посиланням.
     */
    protected function mockDatabase(array $results = [], array &$captured = [], int $affectedRows = 0): Database
    {
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->getMock();

        $db->method('query')->willReturnCallback(function ($query) use (&$captured): bool {
            $captured[] = (string) $query;
            return true;
        });
        $db->method('results')->willReturn($results);
        $db->method('result')->willReturn($results[0] ?? null);
        $db->method('affectedRows')->willReturn($affectedRows);

        // Конструктор вимкнено, тож Database::$pdo лишається null, а деструктор
        // безумовно кличе $this->pdo->disconnect(). PHPUnit 13 доводить мок до
        // деструктора, тому підкладаємо заглушку з тим самим методом.
        $pdo = new class {
            public function disconnect(): void {}
        };
        (new \ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);

        return $db;
    }

    /** Викликає приватний/захищений метод. */
    protected function callPrivate(object $object, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($object, $method))->invokeArgs($object, $args);
    }

    /** Читає приватну/захищену властивість екземпляра. */
    protected function readPrivate(object $object, string $property): mixed
    {
        return (new \ReflectionProperty($object, $property))->getValue($object);
    }

    /** Пише у приватну/захищену властивість екземпляра. */
    protected function writePrivate(object $object, string $property, mixed $value): void
    {
        (new \ReflectionProperty($object, $property))->setValue($object, $value);
    }

    /**
     * Створює екземпляр в обхід конструктора і заповнює властивості.
     *
     * Потрібно там, де конструктор ходить у базу або ServiceLocator.
     *
     * @param class-string $class
     * @param array<string, mixed> $properties
     */
    protected function instanceWithout(string $class, array $properties = []): object
    {
        $object = (new \ReflectionClass($class))->newInstanceWithoutConstructor();

        foreach ($properties as $name => $value) {
            $this->writePrivate($object, $name, $value);
        }

        return $object;
    }
}
