<?php

namespace Modules\Sviat\PersonalData;

require_once __DIR__ . '/Support/ModuleTestCase.php';
require_once __DIR__ . '/Support/BuildsConsentLedger.php';

use Modules\Sviat\PersonalData\Support\BuildsConsentLedger;
use Modules\Sviat\PersonalData\Support\MigratedSettings;
use Okay\Core\EntityFactory;
use Okay\Core\Modules\Extender\ExtenderFacade;
use Okay\Entities\CallbacksEntity;
use Okay\Entities\CommentsEntity;
use Okay\Entities\FeedbacksEntity;
use Okay\Entities\UsersEntity;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentLedger;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentRecorder;
use Okay\Modules\Sviat\PersonalData\Entities\ConsentsEntity;
use Okay\Modules\Sviat\PersonalData\Forms\FormDescriptor;
use Okay\Modules\Sviat\PersonalData\Forms\FormRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Modules\Sviat\PersonalData\Support\ModuleTestCase;
use Modules\Sviat\PersonalData\Support\RequestStub;

/**
 * Квиток запиту й легкий запис: рядок згоди з'являється лише для запису, створеного після згоди
 * в цьому самому запиті, з мовою показу й сторінкою політики, на яку вело посилання.
 */
class ConsentRecorderTest extends ModuleTestCase
{
    use BuildsConsentLedger;

    private const array ENABLED = [
        'sviat__personal_data__form_cart' => 1,
        'sviat__personal_data__form_register' => 1,
        'sviat__personal_data__form_feedback' => 1,
        'sviat__personal_data__form_callback' => 1,
        'sviat__personal_data__form_comment_product' => 1,
        'sviat__personal_data__form_comment_post' => 1,
    ];

    private const array CONFIRMED = ['sviat__personal_data__confirm' => '1'];

    protected function setUp(): void
    {
        $this->resetLedgerState(self::ENABLED);
        $this->records(FeedbacksEntity::class, [1, 2, 3, 4, 5]);
        $this->records(CallbacksEntity::class, [1, 2, 3]);
        $this->records(UsersEntity::class, [1, 2, 3]);
    }

    protected function tearDown(): void
    {
        $this->cleanLedgerState();
        parent::tearDown();
    }

    public function testAcceptThenLinkWritesOneRow(): void
    {
        $this->shown('feedback', 1, self::POLICY_PAGE);
        $ledger = $this->ledger();

        self::assertNull($this->consentGate($this->settings, self::CONFIRMED, sink: $ledger)->check(null, 'feedback'));
        $this->addRecord(FeedbacksEntity::class, 6);

        self::assertTrue($this->recorder($ledger)->link('feedback', 6));
        $rows = $this->consentRows();
        self::assertCount(1, $rows);
        $expected = ['form' => 'feedback', 'record_type' => 'feedback', 'record_id' => 6, 'lang_id' => 1, 'policy_page_id' => self::POLICY_PAGE, 'ip' => null, 'anonymized_at' => null];
        self::assertSame($expected, array_map(static fn(string $field) => $rows[0]->$field, array_combine(array_keys($expected), array_keys($expected))));
        self::assertMatchesRegularExpression('~^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$~', $rows[0]->given_at);
        self::assertSame([], $this->logged);
    }

    /** Згода попередньої відправки не переходить у відправку без відмітки: квиток живе один запит. */
    public function testRefusedAfterAcceptWritesNothing(): void
    {
        $this->shown('feedback', 1);
        self::assertNull($this->consentGate($this->settings, self::CONFIRMED, sink: $this->ledger())->check(null, 'feedback'));

        $nextRequest = $this->ledger();
        self::assertNotNull($this->consentGate($this->settings, [], sink: $nextRequest)->check(null, 'feedback'));
        self::assertFalse($nextRequest->has('feedback'));
        $this->addRecord(FeedbacksEntity::class, 6);

        self::assertFalse($this->recorder($nextRequest)->link('feedback', 6));
        self::assertSame([], $this->consentRows());
    }

    public function testTicketIsPerRequestAndPerForm(): void
    {
        $ledger = $this->ledger();
        $ledger->issue('feedback');
        $this->addRecord(FeedbacksEntity::class, 6);
        $this->addRecord(CallbacksEntity::class, 6);

        self::assertFalse($this->recorder($ledger)->link('callback', 6), 'квиток іншої форми');
        self::assertTrue($ledger->has('feedback'), 'чужа форма квиток не забирає');
        self::assertFalse($this->recorder($this->ledger())->link('feedback', 6), 'інший запит');
        self::assertSame([], $this->consentRows());

        self::assertTrue($this->recorder($ledger)->link('feedback', 6));
        self::assertSame(['feedback'], array_column(array_map(get_object_vars(...), $this->consentRows()), 'form'));
    }

    /** @return iterable<string, array{?int, int, int}> мова доказу, мова сторінки, очікуваний lang_id */
    public static function languages(): iterable
    {
        yield 'доказ показу іншою мовою' => [1, 2, 1];
        yield 'без доказу — мова сторінки' => [null, 2, 2];
    }

    #[DataProvider('languages')]
    public function testRecordsLanguageShownNotCurrent(?int $proofLangId, int $pageLangId, int $langId): void
    {
        $this->pageLangId = $pageLangId;
        if ($proofLangId !== null) {
            $this->shown('feedback', $proofLangId);
        }
        $ledger = $this->ledger();
        $ledger->issue('feedback');
        $this->addRecord(FeedbacksEntity::class, 6);

        self::assertTrue($this->recorder($ledger)->link('feedback', 6));
        self::assertSame($langId, $this->consentRows()[0]->lang_id);
    }

    /** @return iterable<string, array{?int, array<int, object>, ?int}> вибрана сторінка, сторінки магазину, очікуваний policy_page_id */
    public static function policyPages(): iterable
    {
        $visible = (object) ['id' => '41', 'url' => 'privacy', 'visible' => '1'];
        yield 'видима сторінка' => [41, [41 => $visible], 41];
        yield 'не вибрано' => [null, [41 => $visible], null];
        yield 'прихована' => [41, [41 => (object) ['id' => '41', 'url' => 'privacy', 'visible' => '0']], null];
        yield 'видалена' => [41, [], null];
        yield 'слаг не пройшов білий список' => [41, [41 => (object) ['id' => '41', 'url' => '\\evil.example', 'visible' => '1']], null];
    }

    /**
     * Без доказу показу рядок бере сторінку, яку мітка показала б за поточних налаштувань, а без посилання — null.
     *
     * @param array<int, object> $shopPages
     */
    #[DataProvider('policyPages')]
    public function testRecordsPolicyPageTheLinkPointedTo(?int $selected, array $shopPages, ?int $expected): void
    {
        $this->settings->set('sviat__personal_data__policy_page_id', $selected ?? 0);
        $this->shopPages = $shopPages;
        $ledger = $this->ledger();
        $ledger->issue('feedback');
        $this->addRecord(FeedbacksEntity::class, 6);

        self::assertTrue($this->recorder($ledger)->link('feedback', 6));
        self::assertSame($expected, $this->consentRows()[0]->policy_page_id);
        self::assertCount($selected === null ? 0 : 1, $this->pageQueries, 'без вибраної сторінки запиту немає');
    }

    /** @return iterable<string, array{?int, ?int}> сторінка в доказі показу, очікуваний policy_page_id */
    public static function proofPages(): iterable
    {
        yield 'посилання вело на іншу сторінку, ніж зараз у налаштуваннях' => [42, 42];
        yield 'мітка була простим текстом' => [null, null];
    }

    /** З доказом рядок бере сторінку з показу, а не з налаштувань на момент відправки, і запиту до сторінок немає. */
    #[DataProvider('proofPages')]
    public function testRecordsPolicyPageFromProof(?int $proofPage, ?int $expected): void
    {
        $this->shown('feedback', 1, $proofPage);
        $ledger = $this->ledger();
        $ledger->issue('feedback');
        $this->addRecord(FeedbacksEntity::class, 6);

        self::assertTrue($this->recorder($ledger)->link('feedback', 6));
        self::assertSame($expected, $this->consentRows()[0]->policy_page_id);
        self::assertSame([], $this->pageQueries);
    }

    public function testVersionFieldInPostIgnored(): void
    {
        $this->shown('feedback', 1, self::POLICY_PAGE);
        $ledger = $this->ledger();
        $post = self::CONFIRMED + ['policy_page_id' => '9', 'lang_id' => '2', 'sviat__personal_data__policy_page_id' => '9', 'sviat__personal_data__lang_id' => '2'];

        $this->consentGate($this->settings, $post, sink: $ledger)->check(null, 'feedback');
        $this->addRecord(FeedbacksEntity::class, 6);
        $this->recorder($ledger)->link('feedback', 6);

        self::assertSame([self::POLICY_PAGE, 1], [$this->consentRows()[0]->policy_page_id, $this->consentRows()[0]->lang_id]);
    }

    public function testDoubleLinkWritesOneRow(): void
    {
        $ledger = $this->ledger();
        $recorder = $this->recorder($ledger);
        $ledger->issue('feedback');
        $this->addRecord(FeedbacksEntity::class, 6);

        self::assertTrue($recorder->link('feedback', 6));
        self::assertFalse($recorder->link('feedback', 6), 'квиток уже забрано');
        $ledger->issue('feedback');
        self::assertFalse($recorder->link('feedback', 6), 'новий квиток не прив\'язує запис, старший за згоду');

        self::assertCount(1, $this->consentRows());
    }

    /** Повтор без нового запису дозволено лише формі з existingMatcher, і лише коли той визнає запис. */
    public function testExistingWithoutMatcherIgnored(): void
    {
        $ledger = $this->ledger();
        $ledger->issue('feedback');

        self::assertFalse($this->recorder($ledger)->link('feedback', 3, existing: true));
        self::assertSame([], $this->consentRows());
        self::assertStringContainsString('existing record not matched', $this->logged[0] ?? '');
    }

    public function testExistingMatcherDecides(): void
    {
        $registry = new FormRegistry($this->createStub(\Okay\Core\Modules\Modules::class), new FormDescriptor(
            'feedback', 'requests', ['email'], 'feedback.tpl', 'x', 'feedback', FeedbacksEntity::class, false, null, true,
            static fn(object $record): bool => (int) $record->id === 3,
        ));
        $ledger = new ConsentLedger($registry, ...array_slice($this->ledgerArguments($this->ledger()), 1));
        $recorder = new ConsentRecorder($ledger, $registry, $this->entityFactory(), $this->logger(), new RequestStub());

        $ledger->issue('feedback');
        self::assertFalse($recorder->link('feedback', 2, existing: true), 'чужий наявний запис');
        $ledger->issue('feedback');
        self::assertTrue($recorder->link('feedback', 3, existing: true));
        $ledger->issue('feedback');
        self::assertFalse($recorder->link('feedback', 3, existing: true), 'рядок для запису вже є');

        self::assertSame([3], array_map(static fn(object $row) => $row->record_id, $this->consentRows()));
    }

    public function testIpOnlyForFormsWithoutRecordIp(): void
    {
        $ledger = $this->ledger();
        $recorder = $this->recorder($ledger);
        $ledger->issue('register');
        $ledger->issue('feedback');
        $this->addRecord(UsersEntity::class, 4);
        $this->addRecord(FeedbacksEntity::class, 6);

        $recorder->link('register', 4);
        $recorder->link('feedback', 6);

        self::assertSame(['register' => self::IP, 'feedback' => null], array_column(array_map(get_object_vars(...), $this->consentRows()), 'ip', 'form'));
    }

    public function testInvalidRemoteAddressStoresNoIp(): void
    {
        $_SERVER['REMOTE_ADDR'] = 'not-an-ip';
        $ledger = $this->ledger();
        $ledger->issue('register');
        $this->addRecord(UsersEntity::class, 4);

        self::assertTrue($this->recorder($ledger)->link('register', 4));
        self::assertNull($this->consentRows()[0]->ip);
    }

    /** Жоден збій не пише в лог IP чи дані запису; лише форму, тип і id. */
    public function testNothingSensitiveIsLogged(): void
    {
        $this->shown('register', 1);
        $ledger = $this->ledger();
        $recorder = $this->recorder($ledger);
        $failures = [
            static fn() => $recorder->link('register', 4),
            static function () use ($ledger, $recorder) { $ledger->issue('register'); return $recorder->link('register', false); },
            static function () use ($ledger, $recorder) { $ledger->issue('register'); return $recorder->link('register', 2); },
            static function () use ($ledger, $recorder) { $ledger->issue('register'); return $recorder->link('register', 99); },
            function () use ($ledger, $recorder) { $ledger->issue('register'); $this->addRecord(UsersEntity::class, 4); $this->consents->addFails = true; return $recorder->link('register', 4); },
            function () use ($ledger) {
                $ledger->issue('register');
                $factory = $this->createStub(EntityFactory::class);
                $factory->method('get')->willThrowException(new \RuntimeException('Duplicate entry ' . self::IP));

                return $this->recorder($ledger, $factory)->link('register', 5);
            },
        ];
        foreach ($failures as $failure) {
            self::assertFalse($failure());
        }

        self::assertCount(count($failures) - 1, $this->logged, 'перший випадок — без квитка: не збій, лог мовчить');
        foreach ($this->logged as $line) {
            self::assertStringNotContainsString(self::IP, $line);
            self::assertStringNotContainsString('Duplicate entry', $line);
            self::assertMatchesRegularExpression('~form=register, type=user, id=\d+~', $line);
        }
    }

    public function testLinkThrowableIsCaughtAndLogged(): void
    {
        $ledger = $this->ledger();
        $ledger->issue('feedback');
        $this->addRecord(FeedbacksEntity::class, 6);
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willThrowException(new \RuntimeException('boom'));

        self::assertFalse($this->recorder($ledger, $factory)->link('feedback', 6));
        self::assertCount(1, $this->logged);
        self::assertStringContainsString('warning Sviat/PersonalData: consent not linked (error RuntimeException): form=feedback, type=feedback, id=6', $this->logged[0]);
    }

    public function testAddFalseIsLogged(): void
    {
        $ledger = $this->ledger();
        $ledger->issue('feedback');
        $this->addRecord(FeedbacksEntity::class, 6);
        $this->consents->addFails = true;

        self::assertFalse($this->recorder($ledger)->link('feedback', 6));
        self::assertCount(1, $this->logged);
        self::assertStringContainsString('consent not linked (insert failed): form=feedback, type=feedback, id=6', $this->logged[0]);
    }

    /** @return iterable<string, array{mixed}> */
    public static function emptyIds(): iterable
    {
        yield 'false' => [false];
        yield 'null' => [null];
        yield 'порожній рядок' => [''];
        yield 'нуль' => [0];
        yield 'рядок нуль' => ['0'];
        yield 'від\'ємний' => [-6];
        yield 'не число' => ['6abc'];
        yield 'масив' => [[6]];
    }

    #[DataProvider('emptyIds')]
    public function testEmptyOrFalseIdSkipped(mixed $id): void
    {
        $ledger = $this->ledger();
        $ledger->issue('feedback');
        $this->addRecord(FeedbacksEntity::class, 6);

        self::assertFalse($this->recorder($ledger)->link('feedback', $id));
        self::assertSame([], $this->consentRows());
        self::assertCount(1, $this->logged);
        self::assertStringContainsString('consent not linked (no record id): form=feedback, type=feedback, id=0', $this->logged[0]);
    }

    public function testNumericStringIdLinks(): void
    {
        $ledger = $this->ledger();
        $ledger->issue('feedback');
        $this->addRecord(FeedbacksEntity::class, 6);

        self::assertTrue($this->recorder($ledger)->link('feedback', '6'));
        self::assertSame(6, $this->consentRows()[0]->record_id);
    }

    public function testRecordOlderThanTicketIgnored(): void
    {
        $ledger = $this->ledger();
        $ledger->issue('feedback');

        self::assertFalse($this->recorder($ledger)->link('feedback', 5));
        self::assertSame([], $this->consentRows());
    }

    public function testMissingRecordIgnored(): void
    {
        $ledger = $this->ledger();
        $ledger->issue('feedback');

        self::assertFalse($this->recorder($ledger)->link('feedback', 6));
        self::assertSame([], $this->consentRows());
        self::assertStringContainsString('(record not found)', $this->logged[0] ?? '');
    }

    /** Видалення через Entity: шов знеособлення стирає IP і зв'язок із записом, факт згоди лишається. */
    public function testDeleteAnonymizes(): void
    {
        $this->initWithLedger($this->ledger());
        $deleted = $this->consents->seed(['form' => 'register', 'record_type' => 'user', 'record_id' => 3, 'ip' => self::IP]);
        $kept = $this->consents->seed(['form' => 'register', 'record_type' => 'user', 'record_id' => 2, 'ip' => self::IP]);
        $otherType = $this->consents->seed(['form' => 'callback', 'record_type' => 'callback', 'record_id' => 3, 'ip' => self::IP]);

        $this->removeRecord(UsersEntity::class, 3);
        ExtenderFacade::execute([UsersEntity::class, 'delete'], true, [[3]]);

        $row = $this->consents->rows[$deleted];
        self::assertNull($row->ip);
        self::assertNull($row->record_id);
        self::assertNotNull($row->anonymized_at);
        self::assertSame(['register', 'user', '2026-11-01 10:00:00'], [$row->form, $row->record_type, $row->given_at]);
        self::assertSame([2, self::IP, null], [$this->consents->rows[$kept]->record_id, $this->consents->rows[$kept]->ip, $this->consents->rows[$kept]->anonymized_at]);
        self::assertSame(3, $this->consents->rows[$otherType]->record_id, 'запис іншого типу з тим самим id не чіпається');
    }

    /** CRUD::delete відповідає true і після проковтнутої помилки DELETE: запис лишився, і доказ теж. */
    public function testDeleteThatRemovedNothingKeepsConsent(): void
    {
        $this->initWithLedger($this->ledger());
        $row = $this->consents->seed(['form' => 'register', 'record_type' => 'user', 'record_id' => 3, 'ip' => self::IP]);

        ExtenderFacade::execute([UsersEntity::class, 'delete'], true, [[3]]);

        self::assertSame([3, self::IP, null], [$this->consents->rows[$row]->record_id, $this->consents->rows[$row]->ip, $this->consents->rows[$row]->anonymized_at]);
    }

    /** Перевірку видалення теж може проковтнути база: тоді згода лишається незнеособленою, а в лозі попередження. */
    public function testUnconfirmedDeleteKeepsConsent(): void
    {
        $this->initWithLedger($this->ledger());
        $row = $this->consents->seed(['form' => 'register', 'record_type' => 'user', 'record_id' => 3]);
        $this->removeRecord(UsersEntity::class, 3);
        $this->records[UsersEntity::class]->fails = true;

        ExtenderFacade::execute([UsersEntity::class, 'delete'], true, [[3]]);

        self::assertSame(3, $this->consents->rows[$row]->record_id);
        self::assertStringContainsString('deletion not confirmed', implode("\n", $this->logged));
    }

    /** Рядок для цього запису вже є: другий не пишеться, і це не збій, тож у лозі тихо. */
    public function testExistingRowIsNotDuplicated(): void
    {
        $ledger = $this->ledger();
        $ledger->issue('feedback');
        $this->addRecord(FeedbacksEntity::class, 6);
        $this->consents->seed(['form' => 'feedback', 'record_type' => 'feedback', 'record_id' => 6]);

        self::assertFalse($this->recorder($ledger)->link('feedback', 6));

        self::assertCount(1, $this->consentRows());
        self::assertSame(0, $this->consents->adds, 'вставки не було');
        self::assertSame([], $this->logged);
    }

    public function testFailedDeleteAnonymizesNothing(): void
    {
        $this->initWithLedger($this->ledger());
        $row = $this->consents->seed(['form' => 'register', 'record_type' => 'user', 'record_id' => 3, 'ip' => self::IP]);

        ExtenderFacade::execute([UsersEntity::class, 'delete'], false, [[3]]);

        self::assertSame(3, $this->consents->rows[$row]->record_id);
    }

    public function testAnonymizeRowClearsIpAndRecordId(): void
    {
        $recorder = $this->recorder($this->ledger());
        $first = $this->consents->seed(['form' => 'register', 'record_type' => 'user', 'record_id' => 2, 'ip' => self::IP]);
        $second = $this->consents->seed(['form' => 'register', 'record_type' => 'user', 'record_id' => $first, 'ip' => self::IP]);

        self::assertTrue($recorder->anonymizeRow($first));

        self::assertSame([null, null], [$this->consents->rows[$first]->ip, $this->consents->rows[$first]->record_id]);
        self::assertNotNull($this->consents->rows[$first]->anonymized_at);
        self::assertSame([self::IP, $first, null], [$this->consents->rows[$second]->ip, $this->consents->rows[$second]->record_id, $this->consents->rows[$second]->anonymized_at]);
        self::assertFalse($recorder->anonymizeRow($first), 'уже знеособлений');
        self::assertFalse($recorder->anonymizeRow(999), 'немає рядка');
    }

    /** Відмітка без доказу показу й без вибраної сторінки однаково записується: тексту для цього не треба. */
    public function testTickWithoutProofAndWithoutPolicyPageIsWritten(): void
    {
        $this->settings = new MigratedSettings(self::ENABLED);
        $ledger = $this->ledger();
        $ledger->issue('feedback');
        $this->addRecord(FeedbacksEntity::class, 6);

        self::assertTrue($this->recorder($ledger)->link('feedback', 6));
        self::assertSame([2, null], [$this->consentRows()[0]->lang_id, $this->consentRows()[0]->policy_page_id]);
        self::assertSame([], $this->logged);
    }

    public function testIssueFailureDoesNotThrowIntoForm(): void
    {
        $this->database->maxIdQueryFails = true;
        $ledger = $this->ledger();

        $ledger->issue('feedback');

        self::assertFalse($ledger->has('feedback'));
        self::assertCount(1, $this->logged);
        self::assertStringContainsString('consent ticket not issued for form "feedback": RuntimeException', $this->logged[0]);
    }

    /** @return list<mixed> аргументи конструктора журналу, щоб зібрати його з іншим реєстром */
    private function ledgerArguments(ConsentLedger $ledger): array
    {
        $reflection = new \ReflectionClass($ledger);

        return array_map(
            static fn(\ReflectionParameter $parameter) => $reflection->getProperty($parameter->getName())->getValue($ledger),
            $reflection->getConstructor()->getParameters(),
        );
    }
}
