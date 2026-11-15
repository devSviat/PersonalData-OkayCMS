<?php

namespace Modules\Sviat\PersonalData;

require_once __DIR__ . '/Support/ModuleTestCase.php';
require_once __DIR__ . '/Support/BuildsConsentLedger.php';

use Modules\Sviat\PersonalData\Support\BuildsConsentLedger;
use Okay\Core\Modules\Extender\ExtenderFacade;
use Okay\Core\OkayContainer\Reference\ServiceReference;
use Okay\Entities\CallbacksEntity;
use Okay\Entities\CommentsEntity;
use Okay\Entities\FeedbacksEntity;
use Okay\Entities\OrdersEntity;
use Okay\Entities\UsersEntity;
use Okay\Helpers\OrdersHelper;
use Okay\Helpers\UserHelper;
use Okay\Modules\Sviat\InformBackInStock\Entities\InformBackInStockEntity;
use Okay\Modules\Sviat\OrderReturns\Entities\OrderReturnEntity;
use Okay\Modules\Sviat\PartsSelection\Entities\PartsSelectionEntity;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentGate;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentLedger;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentRecorder;
use Okay\Modules\Sviat\PersonalData\Extensions\LinkExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use Modules\Sviat\PersonalData\Support\ModuleTestCase;

/**
 * Шви прив'язки, які реєструє Init: виклик методу ядра після створення запису дає рядок згоди
 * саме цієї форми й цього id. Ланки виконує справжній ExtenderFacade.
 */
class LinkSeamsTest extends ModuleTestCase
{
    use BuildsConsentLedger;

    private const array ENABLED = [
        'sviat__personal_data__form_cart' => 1,
        'sviat__personal_data__form_fast_order' => 1,
        'sviat__personal_data__form_register' => 1,
        'sviat__personal_data__form_feedback' => 1,
        'sviat__personal_data__form_callback' => 1,
        'sviat__personal_data__form_comment_product' => 1,
        'sviat__personal_data__form_comment_post' => 1,
    ];

    protected function setUp(): void
    {
        $this->resetLedgerState(self::ENABLED);
    }

    protected function tearDown(): void
    {
        $this->cleanLedgerState();
        parent::tearDown();
    }

    /** @return iterable<string, array{string, string, array{class-string, string}, mixed, list<mixed>}> */
    public static function seams(): iterable
    {
        yield 'cart' => ['cart', OrdersEntity::class, [OrdersHelper::class, 'finalCreateOrderProcedure'], null, [(object) ['id' => 6, 'email' => '']]];
        yield 'fast_order' => ['fast_order', OrdersEntity::class, [OrdersHelper::class, 'finalCreateOrderProcedure'], null, [(object) ['id' => 6]]];
        yield 'register' => ['register', UsersEntity::class, [UserHelper::class, 'register'], 6, [(object) ['email' => 'a@example.com']]];
        yield 'feedback' => ['feedback', FeedbacksEntity::class, [FeedbacksEntity::class, 'add'], 6, [(object) ['message' => 'm']]];
        yield 'callback' => ['callback', CallbacksEntity::class, [CallbacksEntity::class, 'add'], 6, [(object) ['phone' => '1']]];
        yield 'comment_product' => ['comment_product', CommentsEntity::class, [CommentsEntity::class, 'add'], 6, [(object) ['type' => 'product', 'object_id' => 1]]];
        yield 'comment_post' => ['comment_post', CommentsEntity::class, [CommentsEntity::class, 'add'], 6, [(object) ['type' => 'post', 'object_id' => 1]]];
    }

    /**
     * @param array{class-string, string} $trigger
     * @param list<mixed> $arguments
     */
    #[DataProvider('seams')]
    public function testSeamLinksItsFormAndId(string $form, string $entity, array $trigger, mixed $output, array $arguments): void
    {
        $ledger = $this->ledger();
        $this->initWithLedger($ledger);
        $ledger->issue($form);
        $this->addRecord($entity, 6);

        ExtenderFacade::execute($trigger, $output, $arguments);

        self::assertSame([[$form, 6]], array_map(static fn(object $row) => [$row->form, $row->record_id], $this->consentRows()));
        self::assertFalse($ledger->has($form));
    }

    /** Вимкнена форма не має шва: квиток, навіть якщо він є, рядка не дає. */
    #[DataProvider('seams')]
    public function testDisabledFormHasNoSeam(string $form, string $entity, array $trigger, mixed $output, array $arguments): void
    {
        $this->settings->values = array_diff_key($this->settings->values, self::ENABLED);
        $ledger = $this->ledger();
        $this->initWithLedger($ledger);
        $ledger->issue($form);
        $this->addRecord($entity, 6);

        ExtenderFacade::execute($trigger, $output, $arguments);

        self::assertSame([], $this->consentRows());
    }

    public function testCartAndFastOrderTicketsNeverCross(): void
    {
        $ledger = $this->ledger();
        $this->initWithLedger($ledger);
        $ledger->issue('cart');
        $ledger->issue('fast_order');
        $this->addRecord(OrdersEntity::class, 6);

        ExtenderFacade::execute([OrdersHelper::class, 'finalCreateOrderProcedure'], null, [(object) ['id' => 6]]);

        self::assertSame([['cart', 6]], array_map(static fn(object $row) => [$row->form, $row->record_id], $this->consentRows()));
        self::assertTrue($ledger->has('fast_order'), 'квиток другої форми не забрано');

        $this->cleanLedgerState();
        $this->resetLedgerState(self::ENABLED);
        $onlyCart = $this->ledger();
        $this->initWithLedger($onlyCart);
        $onlyCart->issue('cart');
        $this->addRecord(OrdersEntity::class, 7);
        $this->addRecord(OrdersEntity::class, 8);
        ExtenderFacade::execute([OrdersHelper::class, 'finalCreateOrderProcedure'], null, [(object) ['id' => 7]]);
        ExtenderFacade::execute([OrdersHelper::class, 'finalCreateOrderProcedure'], null, [(object) ['id' => 8]]);

        self::assertSame([['cart', 7]], array_map(static fn(object $row) => [$row->form, $row->record_id], $this->consentRows()), 'друге замовлення без квитка');
    }

    /** Відгук за запрошенням іде через фасад модуля; спільний шов коментарів його квитка не бере. */
    public function testCommentSeamNeverTakesIntegrationTicket(): void
    {
        $ledger = $this->ledger();
        $this->initWithLedger($ledger);
        $ledger->issue('review_request');
        $this->addRecord(CommentsEntity::class, 6);

        ExtenderFacade::execute([CommentsEntity::class, 'add'], 6, [(object) ['type' => 'product', 'object_id' => 1]]);

        self::assertSame([], $this->consentRows());
        self::assertTrue($ledger->has('review_request'));
        self::assertSame([], $this->logged, 'коментар без квитка форми — звичайний випадок, без попередження');
    }

    /** Відповідь менеджера на відгук теж іде через FeedbacksEntity::add: без квитка шов мовчить. */
    public function testRecordWithoutTicketIsSilent(): void
    {
        $this->initWithLedger($this->ledger());
        $this->addRecord(FeedbacksEntity::class, 6);

        ExtenderFacade::execute([FeedbacksEntity::class, 'add'], 6, [(object) ['message' => 'відповідь']]);

        self::assertSame([], $this->consentRows());
        self::assertSame([], $this->logged);
    }

    /** @return iterable<string, array{class-string, string}> */
    public static function deleteSeams(): iterable
    {
        yield 'order' => [OrdersEntity::class, 'order'];
        yield 'user' => [UsersEntity::class, 'user'];
        yield 'feedback' => [FeedbacksEntity::class, 'feedback'];
        yield 'callback' => [CallbacksEntity::class, 'callback'];
        yield 'comment' => [CommentsEntity::class, 'comment'];
        yield 'back_in_stock' => [InformBackInStockEntity::class, 'back_in_stock'];
        yield 'parts_selection' => [PartsSelectionEntity::class, 'parts_selection'];
        yield 'order_return' => [OrderReturnEntity::class, 'order_return'];
    }

    /** @param class-string $entity */
    #[DataProvider('deleteSeams')]
    public function testDeleteSeamAnonymizes(string $entity, string $type): void
    {
        $this->settings->values = array_diff_key($this->settings->values, self::ENABLED);
        $this->initWithLedger($this->ledger());
        $row = $this->consents->seed(['form' => 'x', 'record_type' => $type, 'record_id' => 6, 'ip' => self::IP]);

        ExtenderFacade::execute([$entity, 'delete'], true, [6]);

        self::assertNull($this->consents->rows[$row]->record_id);
        self::assertNotNull($this->consents->rows[$row]->anonymized_at);
    }

    /** Контейнер не має псевдонімів: шлюз і шви мусять посилатися на один сервіс журналу, інакше квитків шви не бачать. */
    public function testGateAndSeamsShareOneLedger(): void
    {
        $services = require dirname(__DIR__, 4) . '/Okay/Modules/Sviat/PersonalData/Init/services.php';
        $ledgerArguments = static fn(string $service): array => array_values(array_filter(
            $services[$service]['arguments'],
            static fn($argument) => $argument instanceof ServiceReference && is_a((string) $argument->getName(), ConsentLedger::class, true)
                || $argument instanceof ServiceReference && $argument->getName() === \Okay\Modules\Sviat\PersonalData\Consent\ConsentSink::class,
        ));

        foreach ([ConsentGate::class, ConsentRecorder::class, LinkExtension::class] as $service) {
            $references = $ledgerArguments($service);
            self::assertCount(1, $references, $service);
            self::assertSame(ConsentLedger::class, $references[0]->getName(), $service);
        }
        self::assertArrayNotHasKey(\Okay\Modules\Sviat\PersonalData\Consent\ConsentSink::class, $services, 'окремий запис дав би другий екземпляр журналу');
    }
}
