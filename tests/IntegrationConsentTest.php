<?php

namespace Modules\Sviat\PersonalData;

require_once __DIR__ . '/Support/ModuleTestCase.php';
require_once __DIR__ . '/Support/BuildsConsentLedger.php';

use Modules\Sviat\PersonalData\Support\BuildsConsentLedger;
use Modules\Sviat\PersonalData\Support\MigratedSettings;
use Okay\Core\Design;
use Okay\Core\FrontTranslations;
use Okay\Core\Languages;
use Okay\Core\Modules\Modules;
use Okay\Core\Request;
use Okay\Entities\CommentsEntity;
use Okay\Entities\FeedbacksEntity;
use Okay\Modules\Sviat\InformBackInStock\Entities\InformBackInStockEntity;
use Okay\Modules\Sviat\OrderReturns\Entities\OrderReturnEntity;
use Okay\Modules\Sviat\PartsSelection\Entities\PartsSelectionEntity;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentLedger;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentRecorder;
use Okay\Modules\Sviat\PersonalData\Consent\ErrorText;
use Okay\Modules\Sviat\PersonalData\Consent\RenderProof;
use Okay\Modules\Sviat\PersonalData\Forms\FormDescriptor;
use Okay\Modules\Sviat\PersonalData\Forms\FormRegistry;
use Okay\Modules\Sviat\PersonalData\Forms\FormSettings;
use Okay\Modules\Sviat\PersonalData\Integration\Consent;
use Okay\Modules\Sviat\PersonalData\Plugins\ConfirmPersonalDataProcessingPlugin;
use Smarty\Smarty;
use Modules\Sviat\PersonalData\Support\ModuleTestCase;
use Modules\Sviat\PersonalData\Support\RequestStub;

/**
 * Фасад для модулів власника: check видає квиток лише формі з фасадом, link прив'язує лише запис,
 * створений після check у цьому запиті (T9), і жоден виклик не ламає модуль, що його кличе.
 */
class IntegrationConsentTest extends ModuleTestCase
{
    use BuildsConsentLedger;

    private const string MODULE = __DIR__ . '/../../../../Okay/Modules/Sviat/PersonalData';

    private const array ENABLED = [
        'sviat__personal_data__form_back_in_stock' => 1,
        'sviat__personal_data__form_parts_selection' => 1,
        'sviat__personal_data__form_order_return' => 1,
        'sviat__personal_data__form_review_request' => 1,
        'sviat__personal_data__form_feedback' => 1,
    ];

    private const array CONFIRMED = ['sviat__personal_data__confirm' => '1'];

    private const string NEW_IP = '198.51.100.9';

    private const int NEW_POLICY_PAGE = 42;

    private const array SUBSCRIPTION = ['variant_id' => '10', 'email' => 'buyer@example.com', 'name' => 'Ірина'];

    protected function setUp(): void
    {
        $this->resetLedgerState(self::ENABLED);
        $this->records(PartsSelectionEntity::class, [1, 2, 3, 4, 5]);
        $this->records(OrderReturnEntity::class, [1, 2]);
        $this->records(FeedbacksEntity::class, [1, 2]);
        $this->records(InformBackInStockEntity::class, []);
        $this->recordFields(InformBackInStockEntity::class, 3, ['variant_id' => '10', 'email' => 'buyer@example.com']);
        $this->recordFields(InformBackInStockEntity::class, 4, ['variant_id' => '11', 'email' => 'other@example.com']);
        foreach (['back_in_stock', 'parts_selection', 'order_return', 'review_request', 'feedback'] as $form) {
            $this->shown($form, 1, self::POLICY_PAGE);
        }
    }

    protected function tearDown(): void
    {
        $this->cleanLedgerState();
        parent::tearDown();
    }

    public function testCheckRefusesWithoutTick(): void
    {
        $ledger = $this->ledger();

        self::assertSame('Позначте згоду.', $this->facade($ledger)->check('parts_selection'));
        self::assertFalse($ledger->has('parts_selection'));
        self::assertTrue($this->requestState->isRefused('parts_selection'), 'чекбокс модуля покаже стан відмови');

        self::assertNull($this->facade($ledger, self::CONFIRMED)->check('parts_selection'));
        self::assertTrue($ledger->has('parts_selection'), 'контроль: з відміткою квиток є');
    }

    public function testCheckNullWhenNotRequired(): void
    {
        $ledger = $this->ledger();

        $_SESSION = [];
        self::assertNull($this->facade($ledger)->check('parts_selection'), 'ні доказу, ні живого анкера');

        $this->shown('parts_selection');
        $this->settings->values['sviat__personal_data__form_parts_selection'] = 0;
        self::assertNull($this->facade($ledger)->check('parts_selection'), 'форму вимкнено');

        $this->settings->values['sviat__personal_data__form_parts_selection'] = 1;
        $this->modulesActive = false;
        self::assertNull($this->facade($ledger, modulesActive: false)->check('parts_selection'), 'модуля форми немає');

        self::assertFalse($ledger->has('parts_selection'));
        $this->modulesActive = true;
        self::assertNotNull($this->facade($ledger)->check('parts_selection'), 'контроль: та сама форма вимагає згоду');
    }

    /** Форма ядра має свій шов; квиток через фасад на неї означав би прив'язку з чужого коду. */
    public function testCheckRejectsNonIntegrationForm(): void
    {
        $ledger = $this->ledger();

        self::assertNull($this->facade($ledger)->check('feedback'));
        self::assertNull($this->facade($ledger, self::CONFIRMED)->check('feedback'));
        self::assertNull($this->facade($ledger)->check('subscribe'));
        self::assertFalse($ledger->has('feedback'));
        self::assertFalse($this->requestState->isRefused('feedback'));
        self::assertStringContainsString('not an integration form', implode("\n", $this->logged));

        self::assertNotNull($this->consentGate($this->settings, sink: $ledger)->check(null, 'feedback'), 'контроль: шлюз ядра форму вимагає');
    }

    public function testLinkWithoutCheckIgnored(): void
    {
        $this->addRecord(PartsSelectionEntity::class, 6);

        $this->facade($this->ledger())->link('parts_selection', 6);

        self::assertSame([], $this->consentRows());
    }

    /** Вимкнена форма (типовий стан) і відправка без доказу й без відмітки не дають квитка; це не збій, лог мовчить. */
    public function testLinkWithoutTicketIsSilent(): void
    {
        $this->settings->values['sviat__personal_data__form_parts_selection'] = 0;
        $facade = $this->facade($this->ledger(), self::CONFIRMED);
        self::assertNull($facade->check('parts_selection'));
        $this->addRecord(PartsSelectionEntity::class, 6);
        $facade->link('parts_selection', 6);

        $this->settings->values['sviat__personal_data__form_parts_selection'] = 1;
        $_SESSION = [];
        $facade = $this->facade($this->ledger());
        self::assertNull($facade->check('parts_selection'), 'ні доказу, ні відмітки');
        $this->addRecord(PartsSelectionEntity::class, 7);
        $facade->link('parts_selection', 7);

        self::assertSame([], $this->consentRows());
        self::assertSame([], $this->logged);

        // Контроль: справжній збій із квитком у руках лог бачить.
        $this->shown('parts_selection');
        $this->consents->addFails = true;
        $facade = $this->facade($this->ledger(), self::CONFIRMED);
        $facade->check('parts_selection');
        $this->addRecord(PartsSelectionEntity::class, 8);
        $facade->link('parts_selection', 8);
        self::assertCount(1, $this->logged);
        self::assertStringContainsString('insert failed', $this->logged[0]);
        self::assertStringNotContainsString(self::IP, $this->logged[0]);
    }

    /**
     * Сесія скінчилася між показом і відправкою: доказу немає, але покупець відмітив чекбокс.
     * Згода записується з мовою сторінки й сторінкою політики, на яку зараз веде посилання.
     */
    public function testTickWithoutProofStillRecorded(): void
    {
        $_SESSION = [];
        $ledger = $this->ledger();
        $facade = $this->facade($ledger, self::CONFIRMED);

        self::assertNull($facade->check('parts_selection'));
        $this->addRecord(PartsSelectionEntity::class, 6);
        $facade->link('parts_selection', 6);

        $rows = $this->consentRows();
        self::assertCount(1, $rows);
        self::assertSame('parts_selection', $rows[0]->form);
        self::assertSame(6, (int) $rows[0]->record_id);
        self::assertSame(2, (int) $rows[0]->lang_id, 'мова сторінки');
        self::assertSame(self::POLICY_PAGE, $rows[0]->policy_page_id);
        self::assertSame([], $this->logged);
    }

    public function testNoTickNoProofAcceptedWithoutRow(): void
    {
        $_SESSION = [];
        $ledger = $this->ledger();
        $facade = $this->facade($ledger);

        self::assertNull($facade->check('parts_selection'));
        self::assertFalse($this->requestState->isRefused('parts_selection'));
        $this->addRecord(PartsSelectionEntity::class, 6);
        $facade->link('parts_selection', 6);

        self::assertSame([], $this->consentRows());
        self::assertFalse($ledger->has('parts_selection'));
    }

    public function testNoTickWithProofRefused(): void
    {
        $ledger = $this->ledger();

        self::assertSame('Позначте згоду.', $this->facade($ledger)->check('parts_selection'));
        self::assertTrue($this->requestState->isRefused('parts_selection'));
        self::assertFalse($ledger->has('parts_selection'));
        self::assertSame([], $this->consentRows());
    }

    public function testLinkOtherFormIgnored(): void
    {
        $ledger = $this->ledger();
        $facade = $this->facade($ledger, self::CONFIRMED);
        self::assertNull($facade->check('parts_selection'));
        $this->addRecord(OrderReturnEntity::class, 3);

        $facade->link('order_return', 3);

        self::assertSame([], $this->consentRows());
        self::assertTrue($ledger->has('parts_selection'), 'квиток іншої форми не витрачено');
    }

    public function testLinkToOlderRecordIgnored(): void
    {
        $ledger = $this->ledger();
        $facade = $this->facade($ledger, self::CONFIRMED);

        $facade->check('parts_selection');
        $facade->link('parts_selection', 3);
        self::assertSame([], $this->consentRows());
        self::assertStringContainsString('record older than consent', implode("\n", $this->logged));

        $facade->check('parts_selection');
        $this->addRecord(PartsSelectionEntity::class, 6);
        $facade->link('parts_selection', 6);
        self::assertSame([6], $this->linkedIds(), 'контроль: запис, створений після check');
    }

    public function testLinkToMissingRecordIgnored(): void
    {
        $facade = $this->facade($this->ledger(), self::CONFIRMED);
        $facade->check('parts_selection');

        $facade->link('parts_selection', 99);

        self::assertSame([], $this->consentRows());
        self::assertStringContainsString('record not found', implode("\n", $this->logged));
    }

    public function testExistingMatcherRejectsForeignRecord(): void
    {
        $facade = $this->facade($this->ledger(), self::CONFIRMED + self::SUBSCRIPTION);

        self::assertNull($facade->check('back_in_stock'));
        $facade->link('back_in_stock', 4, existing: true);
        self::assertSame([], $this->consentRows(), 'чужий товар і email');

        self::assertNull($facade->check('back_in_stock'));
        $facade->link('back_in_stock', 3, existing: true);
        self::assertSame([3], $this->linkedIds(), 'контроль: запис цієї відправки');
        self::assertSame(self::IP, $this->consentRows()[0]->ip, 'запис повтору не має IP');
    }

    public function testMatcherComparesBothVariantAndEmail(): void
    {
        $matcher = $this->registry()->get('back_in_stock')?->existingMatcher;
        self::assertInstanceOf(\Closure::class, $matcher);
        $record = (object) ['id' => '3', 'variant_id' => '10', 'email' => 'Buyer@Example.com'];

        self::assertTrue($matcher($record, new RequestStub(self::SUBSCRIPTION)));
        self::assertFalse($matcher($record, new RequestStub(['variant_id' => '11'] + self::SUBSCRIPTION)), 'інший товар');
        self::assertFalse($matcher($record, new RequestStub(['email' => 'other@example.com'] + self::SUBSCRIPTION)), 'інший email');
        self::assertFalse($matcher($record, new RequestStub(['email' => ''] + self::SUBSCRIPTION)), 'порожній email');
        self::assertFalse($matcher((object) ['id' => '3', 'variant_id' => '0', 'email' => ''], new RequestStub(['variant_id' => '0', 'email' => ''])));
    }

    public function testExistingWithoutMatcherIgnored(): void
    {
        $facade = $this->facade($this->ledger(), self::CONFIRMED);
        $facade->check('parts_selection');

        $facade->link('parts_selection', 3, existing: true);

        self::assertSame([], $this->consentRows());
        self::assertStringContainsString('existing record not matched', implode("\n", $this->logged));
    }

    /**
     * Повтор на запис з активною згодою не оновлює доказ: перша згода лишається з її часом, IP, мовою й сторінкою політики (T8).
     * Інакше будь-хто, хто знає товар і email, переписав би чужий доказ своїм IP.
     */
    public function testActiveRowStaysUntouched(): void
    {
        $this->firstSubscription();

        $this->resubscribe();

        $rows = $this->consentRows();
        self::assertCount(1, $rows);
        self::assertSame(['2026-11-01 10:00:00', self::IP, self::POLICY_PAGE, 1, null], [$rows[0]->given_at, $rows[0]->ip, $rows[0]->policy_page_id, $rows[0]->lang_id, $rows[0]->withdrawn_at]);
        self::assertSame([], $this->logged, 'повтор — звичайна дія покупця, не збій');
    }

    /** Рішення власника: відкликана згода й нова підписка на той самий запис — нова згода в тому самому рядку. */
    public function testResubscribeRestoresWithdrawnConsent(): void
    {
        $rowId = $this->firstSubscription();
        $this->consents->rows[$rowId]->withdrawn_at = '2026-11-05 12:00:00';
        $this->consents->rows[$rowId]->withdrawn_by = "anna\nforged";

        $this->resubscribe();

        $rows = $this->consentRows();
        self::assertCount(1, $rows);
        self::assertSame(
            [$rowId, 3, self::NEW_IP, self::NEW_POLICY_PAGE, 2, null, null, null],
            [$rows[0]->id, $rows[0]->record_id, $rows[0]->ip, $rows[0]->policy_page_id, $rows[0]->lang_id, $rows[0]->withdrawn_at, $rows[0]->withdrawn_by, $rows[0]->anonymized_at],
        );
        self::assertNotSame('2026-11-01 10:00:00', $rows[0]->given_at);
        self::assertMatchesRegularExpression('~^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$~', $rows[0]->given_at);
        // Попереднє відкликання рядок втратив, тож воно лишається в лозі одним рядком, без IP і даних покупця.
        self::assertSame(
            ['info Sviat/PersonalData: withdrawn consent restored: form=back_in_stock, type=back_in_stock, id=3, withdrawn_at=2026-11-05 12:00:00, withdrawn_by=anna forged []'],
            $this->logged,
        );
        foreach ([self::IP, self::NEW_IP, self::SUBSCRIPTION['email'], self::SUBSCRIPTION['name']] as $personal) {
            self::assertStringNotContainsString($personal, $this->logged[0]);
        }
    }

    public function testFailedRestoreLogsWithoutPersonalData(): void
    {
        $rowId = $this->firstSubscription();
        $this->consents->rows[$rowId]->withdrawn_at = '2026-11-05 12:00:00';
        $this->consents->updateFails = true;

        $this->resubscribe();

        self::assertSame('2026-11-05 12:00:00', $this->consentRows()[0]->withdrawn_at);
        self::assertCount(1, $this->logged);
        self::assertStringContainsString('restore failed', $this->logged[0]);
        foreach ([self::IP, self::NEW_IP, 'buyer@example.com', self::SUBSCRIPTION['name']] as $personal) {
            self::assertStringNotContainsString($personal, $this->logged[0]);
        }
    }

    /** Знеособлене не оживає: ні рядок без record_id, ні (захисно) рядок, що ще тримає record_id. */
    public function testAnonymizedRowNeverRestored(): void
    {
        $anonymized = ['form' => 'back_in_stock', 'record_type' => 'back_in_stock', 'ip' => null,
            'withdrawn_at' => '2026-11-05 12:00:00', 'withdrawn_by' => 'manager', 'anonymized_at' => '2026-11-06 12:00:00'];
        $detached = $this->consents->seed(['record_id' => null] + $anonymized);
        $stillLinked = $this->consents->seed(['record_id' => 3] + $anonymized);
        $before = [$detached => clone $this->consents->rows[$detached], $stillLinked => clone $this->consents->rows[$stillLinked]];

        $this->resubscribe();

        foreach ($before as $id => $row) {
            self::assertEquals($row, $this->consents->rows[$id], "рядок $id");
        }
        self::assertCount(2, $this->consentRows(), 'унікальний ключ не дає другого рядка для запису 3');
    }

    /** Перша підписка на запис 3 з датою в минулому. */
    private function firstSubscription(): int
    {
        $facade = $this->facade($this->ledger(), self::CONFIRMED + self::SUBSCRIPTION);
        $facade->check('back_in_stock');
        $facade->link('back_in_stock', 3, existing: true);
        $rows = $this->consentRows();
        self::assertCount(1, $rows);
        $this->consents->rows[$rows[0]->id]->given_at = '2026-11-01 10:00:00';

        return $rows[0]->id;
    }

    /** Новий запит: інший IP, покупець бачив чекбокс мовою 2, політика вже на іншій сторінці; той самий товар і email. */
    private function resubscribe(): void
    {
        $_SERVER['REMOTE_ADDR'] = self::NEW_IP;
        $_SESSION = [];
        $this->shown('back_in_stock', 2, self::NEW_POLICY_PAGE);
        $this->settings->set('sviat__personal_data__policy_page_id', self::NEW_POLICY_PAGE);
        $this->shopPages[self::NEW_POLICY_PAGE] = (object) ['id' => (string) self::NEW_POLICY_PAGE, 'url' => 'privacy-2026', 'visible' => '1'];
        $facade = $this->facade($this->ledger(), self::CONFIRMED + self::SUBSCRIPTION);
        self::assertNull($facade->check('back_in_stock'));
        $facade->link('back_in_stock', 3, existing: true);
    }

    public function testPreMigrationCheckNullLinkNoop(): void
    {
        $this->settings = MigratedSettings::pending(self::ENABLED);
        $ledger = $this->ledger();
        $facade = $this->facade($ledger);

        self::assertNull($facade->check('parts_selection'));
        self::assertFalse($this->requestState->isRefused('parts_selection'));
        $ledger->issue('parts_selection');
        $this->addRecord(PartsSelectionEntity::class, 6);
        $facade->link('parts_selection', 6);

        self::assertSame([], $this->consentRows());
        self::assertTrue($ledger->has('parts_selection'), 'link до оновлення не чіпає журнал квитків');
        self::assertSame(0, $this->consents->adds);
    }

    public function testFacadeNeverThrows(): void
    {
        $translations = $this->createStub(FrontTranslations::class);
        $translations->method('getTranslation')->willThrowException(new \RuntimeException('translations down'));
        $gate = $this->consentGate($this->settings, frontTranslations: $translations, sink: $this->ledger(), modulesActive: true);
        $facade = new Consent($this->schemaState($this->settings), $this->registry(), $gate, $this->recorder($this->ledger()), $this->logger());

        self::assertNull($facade->check('parts_selection'));
        $facade->link('parts_selection', 6);
        self::assertStringContainsString('consent check failed', implode("\n", $this->logged));
    }

    /** F19: без модуля-власника фасад не доходить до матчера, навіть з квитком у журналі. */
    public function testMatcherNotCalledWhenModuleUnavailable(): void
    {
        $calls = 0;
        $registry = $this->registryWithMatcherSpy($calls);
        $ledger = new ConsentLedger($registry, ...array_slice($this->ledgerArguments($this->ledger()), 1));
        $recorder = new ConsentRecorder($ledger, $registry, $this->entityFactory(), $this->logger(), new RequestStub(self::SUBSCRIPTION));
        $facade = new Consent($this->schemaState($this->settings), $registry, $this->consentGate($this->settings, sink: $ledger), $recorder, $this->logger());

        $this->modulesActive = false;
        $ledger->issue('back_in_stock');
        $facade->link('back_in_stock', 3, existing: true);

        self::assertSame(0, $calls);
        self::assertSame([], $this->consentRows());

        $this->modulesActive = true;
        $registry = $this->registryWithMatcherSpy($calls);
        $ledger = new ConsentLedger($registry, ...array_slice($this->ledgerArguments($this->ledger()), 1));
        $recorder = new ConsentRecorder($ledger, $registry, $this->entityFactory(), $this->logger(), new RequestStub(self::SUBSCRIPTION));
        $ledger->issue('back_in_stock');
        new Consent($this->schemaState($this->settings), $registry, $this->consentGate($this->settings, sink: $ledger), $recorder, $this->logger())
            ->link('back_in_stock', 3, existing: true);
        self::assertSame(1, $calls, 'контроль: з модулем матчер викликано');
        self::assertSame([3], $this->linkedIds());
    }

    /** @return iterable<string, array{string, string}> */
    public static function blocks(): iterable
    {
        yield 'back_in_stock' => ['back_in_stock', 'sviat-pd-back_in_stock'];
        yield 'parts_selection' => ['parts_selection', 'sviat-pd-parts_selection'];
        yield 'order_return' => ['order_return', 'sviat-pd-order_return'];
        yield 'review_request' => ['review_request', 'sviat-pd-review_request-42'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blocks')]
    public function testBlocksRenderNothingWhenFormOff(string $form, string $checkboxId): void
    {
        $_SESSION = [];
        $this->settings->values["sviat__personal_data__form_$form"] = 0;
        self::assertSame('', trim($this->renderBlock($form)));
        self::assertNull(new RenderProof()->shown($form), 'вимкнена форма не лишає доказу');

        $this->settings->values["sviat__personal_data__form_$form"] = 1;
        $html = $this->renderBlock($form);
        self::assertStringContainsString('id="' . $checkboxId . '"', $html, 'контроль: увімкнена форма малює свій чекбокс');
    }

    public function testBlocksRegisteredForEachIntegrationFormAfterMigration(): void
    {
        $integration = array_values(array_map(
            static fn(FormDescriptor $form) => $form->key,
            array_filter($this->registry()->all(), static fn(FormDescriptor $form) => $form->integration),
        ));
        self::assertSame(['back_in_stock', 'parts_selection', 'order_return', 'review_request'], $integration);

        $this->initWithLedger($this->ledger());
        $expected = [];
        foreach ($integration as $form) {
            $expected["sviat_personal_data_$form"] = "consent_$form.tpl";
        }
        $consentBlocks = array_filter($this->designBlocks, static fn(string $block) => str_starts_with($block, 'sviat_personal_data_'), ARRAY_FILTER_USE_KEY);
        self::assertSame($expected, array_map(basename(...), $consentBlocks));
        self::assertSame(
            [
                'order_contact', 'user_fields_2', 'feedbacks_item', 'callbacks_item', 'comments_comment_info',
                'inform_back_in_stock_item', 'parts_selection_item', 'order_returns_item',
            ],
            array_keys(array_filter($this->designBlocks, static fn(string $file) => basename($file) === 'consent_badge.tpl')),
            'бейджі в адмінці',
        );
        foreach ($consentBlocks as $block => $file) {
            self::assertSame(realpath(self::MODULE . '/design/html/' . basename($file)), realpath($file), $block);
            $form = substr($block, strlen('sviat_personal_data_'));
            self::assertStringContainsString("{confirm_personal_data_processing form=\"$form\"", (string) file_get_contents($file));
        }

        $this->settings = MigratedSettings::pending(self::ENABLED);
        $this->initWithLedger($this->ledger());
        self::assertSame([], $this->designBlocks, 'до «Оновити» блоків немає');
    }

    /** @param array<string, mixed> $post */
    private function facade(ConsentLedger $ledger, array $post = [], bool $modulesActive = true): Consent
    {
        return new Consent(
            $this->schemaState($this->settings),
            $this->registry(),
            $this->consentGate($this->settings, $post, sink: $ledger, modulesActive: $modulesActive),
            $this->recorderWithRequest($ledger, new RequestStub($post)),
            $this->logger(),
        );
    }

    private function recorderWithRequest(ConsentLedger $ledger, Request $request): ConsentRecorder
    {
        return new ConsentRecorder($ledger, $this->registry(), $this->entityFactory(), $this->logger(), $request);
    }

    /** @return list<int> */
    private function linkedIds(): array
    {
        return array_map(static fn(object $row) => $row->record_id, $this->consentRows());
    }

    /** Реєстр модуля, де матчер back_in_stock рахує виклики і далі віддає рішення справжньому. */
    private function registryWithMatcherSpy(int &$calls): FormRegistry
    {
        $modules = $this->createStub(Modules::class);
        $modules->method('getRunningModules')->willReturn([]);
        $modules->method('isActiveModule')->willReturnCallback(fn() => $this->modulesActive);
        $descriptors = array_map(static function (FormDescriptor $form) use (&$calls): FormDescriptor {
            if ($form->key !== 'back_in_stock') {
                return $form;
            }
            $real = $form->existingMatcher;
            self::assertNotNull($real, 'дескриптор back_in_stock тримає матчер');
            $spy = static function (object $record, Request $request) use (&$calls, $real): bool {
                $calls++;

                return $real($record, $request);
            };

            return new FormDescriptor($form->key, $form->group, $form->collects, $form->template, $form->anchor, $form->recordType,
                $form->recordEntity, $form->recordHasIp, $form->requiredModule, $form->integration, $spy);
        }, $this->registry()->all());

        return new FormRegistry($modules, ...$descriptors);
    }

    /** @return list<mixed> */
    private function ledgerArguments(ConsentLedger $ledger): array
    {
        $reflection = new \ReflectionClass($ledger);

        return array_map(
            static fn(\ReflectionParameter $parameter) => $reflection->getProperty($parameter->getName())->getValue($ledger),
            $reflection->getConstructor()->getParameters(),
        );
    }

    /** Блок модуля через справжній плагін, як його друкує `{get_design_block}` у шаблоні модуля-власника. */
    private function renderBlock(string $form): string
    {
        $languages = $this->createStub(Languages::class);
        $languages->method('getLangId')->willReturn(1);
        $languages->method('getMainLanguage')->willReturn((object) ['id' => 1, 'label' => 'ua']);
        $languages->method('getLangLabel')->willReturn('ua');
        $translations = $this->createStub(FrontTranslations::class);
        $translations->method('getTranslation')->willReturn(null);

        $inner = new Smarty();
        $inner->setTemplateDir(self::MODULE . '/design/html');
        $inner->setCompileDir($this->consentRoot . '/compiled-inner');
        $vars = [];
        $design = $this->createStub(Design::class);
        $design->method('assign')->willReturnCallback(function ($name, $value) use (&$vars) {
            $vars[$name] = $value;

            return null;
        });
        $design->method('getVar')->willReturnCallback(static fn($name) => $vars[$name] ?? null);
        $design->method('fetch')->willReturnCallback(static function (string $template) use ($inner, &$vars) {
            $inner->clearAllAssign();
            $inner->assign($vars);

            return $inner->fetch($template);
        });

        $plugin = new ConfirmPersonalDataProcessingPlugin(
            $design,
            $this->schemaState($this->settings),
            $this->registry(),
            new FormSettings($this->settings),
            $languages,
            $translations,
            new RenderProof(),
            $this->requestState,
            new ErrorText($translations),
        );

        $outer = new Smarty();
        $outer->setTemplateDir(self::MODULE . '/design/html');
        $outer->setCompileDir($this->consentRoot . '/compiled-outer');
        $outer->registerPlugin(Smarty::PLUGIN_FUNCTION, 'confirm_personal_data_processing', [$plugin, 'run']);
        // get_design_block кладе vars у глобальні змінні дизайну на час рендеру блока.
        $outer->assign('sviat_pd_instance', 42);

        return $outer->fetch("consent_$form.tpl");
    }
}
