<?php

namespace Modules\Sviat\PersonalData;

require_once __DIR__ . '/Support/ModuleTestCase.php';
require_once __DIR__ . '/Support/RequestStub.php';
require_once __DIR__ . '/Support/FakeConsentsEntity.php';

use Modules\Sviat\PersonalData\Support\FakeConsentsEntity;
use Okay\Core\BackendTranslations;
use Okay\Core\Design;
use Okay\Core\Languages;
use Okay\Core\Modules\Modules;
use Okay\Entities\PagesEntity;
use Okay\Modules\Sviat\PersonalData\Backend\Helpers\ConsentBadges;
use Okay\Modules\Sviat\PersonalData\Backend\Helpers\ConsentLogHelper;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentLedger;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentRecorder;
use Okay\Modules\Sviat\PersonalData\Entities\ConsentsEntity;
use Okay\Modules\Sviat\PersonalData\Forms\FormDescriptor;
use Okay\Modules\Sviat\PersonalData\Forms\FormRegistry;
use Psr\Log\NullLogger;
use ReflectionClass;
use Modules\Sviat\PersonalData\Support\ModuleTestCase;
use Modules\Sviat\PersonalData\Support\RequestStub;

/** Бейдж відкликаної згоди на записі магазину: одна вибірка на сторінку списку, і з нього один клік до рядка журналу. */
class ConsentBadgesTest extends ModuleTestCase
{
    private FakeConsentsEntity $consents;

    protected function setUp(): void
    {
        $this->consents = new FakeConsentsEntity();
    }

    public function testBadgesOneQueryPerPage(): void
    {
        $pageIds = range(101, 120);
        foreach ($pageIds as $id) {
            $this->consents->seed(['record_id' => $id, 'withdrawn_at' => '2026-11-05 12:00:00']);
        }
        $badges = $this->badges();

        foreach ($pageIds as $id) {
            self::assertNotNull($badges->forRecord('feedback', $id, $pageIds), "запис $id");
        }

        self::assertCount(1, $this->consents->finds);
    }

    public function testRecordWithoutConsentHasNoBadgeAndNoSecondQuery(): void
    {
        $this->consents->seed(['record_id' => 5, 'withdrawn_at' => '2026-11-05 12:00:00']);
        $badges = $this->badges();

        self::assertNull($badges->forRecord('feedback', 6, [5, 6]));
        self::assertNotNull($badges->forRecord('feedback', 5, [5, 6]));
        self::assertCount(1, $this->consents->finds);
    }

    public function testTypesDoNotMix(): void
    {
        $this->consents->seed(['record_id' => 5, 'record_type' => 'callback', 'form' => 'callback', 'withdrawn_at' => '2026-11-05 12:00:00']);

        self::assertNull($this->badges()->forRecord('feedback', 5, [5]));
        self::assertNotNull($this->badges()->forRecord('callback', 5, [5]));
    }

    public function testBadgesIgnoreAnonymized(): void
    {
        $this->consents->seed(['record_id' => 5, 'anonymized_at' => '2026-11-06 12:00:00']);
        $badges = $this->badges();

        self::assertNull($badges->forRecord('feedback', 5, [5]));
        self::assertArrayHasKey('not_anonymized', $this->consents->finds[0]);
    }

    /** Без згоди форму не надіслати, тож чинна згода є в кожного запису: бейдж лише для відкликаної. */
    public function testActiveConsentHasNoBadge(): void
    {
        $this->consents->seed(['record_id' => 5]);

        self::assertNull($this->badges()->forRecord('feedback', 5, [5]));
    }

    /** Повторна згода після відкликання знову чинна: старий відкликаний рядок бейджа не дає. */
    public function testRenewedConsentHidesOlderWithdrawal(): void
    {
        $this->consents->seed(['record_id' => 5, 'given_at' => '2026-11-01 10:00:00', 'withdrawn_at' => '2026-11-02 10:00:00']);
        $this->consents->seed(['record_id' => 5, 'given_at' => '2026-11-03 10:00:00']);

        self::assertNull($this->badges()->forRecord('feedback', 5, [5]));
    }

    public function testWithdrawnBadgeSaysWhoAndWhen(): void
    {
        $this->consents->seed(['record_id' => 5, 'withdrawn_at' => '2026-11-05 12:00:00', 'withdrawn_by' => 'anna']);

        $badge = $this->badges()->forRecord('feedback', 5, [5]);

        self::assertSame('withdrawn', $badge?->state);
        self::assertSame('anna', $badge?->withdrawnBy);
        self::assertSame('2026-11-05 12:00:00', $badge?->withdrawnAt);
    }

    public function testBadgeLinksToItsJournalRow(): void
    {
        for ($i = 0; $i < 130; $i++) {
            $this->consents->seed(['record_id' => 1000 + $i, 'withdrawn_at' => '2026-11-05 12:00:00', 'given_at' => date('Y-m-d H:i:s', strtotime('2026-11-01 10:00:00') - $i * 60)]);
        }

        $badge = $this->badges()->forRecord('feedback', 1120, [1120]);
        parse_str((string) parse_url((string) $badge?->url, PHP_URL_QUERY), $query);

        self::assertSame('Sviat.PersonalData.ConsentLogAdmin', $query['controller'] ?? null);
        self::assertArrayHasKey('consent_id', $query);
        $journal = $this->journal($query);
        $filter = $journal->filterFromRequest();
        self::assertSame(3, $filter['page']);
        self::assertContains((int) $badge->id, array_column($journal->rows($filter), 'id'));
    }

    /** Заявки «Повідомити про наявність» згруповано за товаром: усі бейджі сторінки — однією вибіркою. */
    public function testGroupedListOneQueryPerPage(): void
    {
        $grouped = [];
        foreach (range(201, 212) as $i => $id) {
            $this->consents->seed(['form' => 'back_in_stock', 'record_type' => 'back_in_stock', 'record_id' => $id, 'withdrawn_at' => '2026-11-05 12:00:00']);
            $grouped[700 + intdiv($i, 4)][] = (object) ['id' => (string) $id];
        }
        $design = $this->createStub(Design::class);
        $vars = ['records' => $grouped];
        $design->method('getVar')->willReturnCallback(static function (string $name) use (&$vars) {
            return $vars[$name] ?? null;
        });
        $design->method('assign')->willReturnCallback(static function (string $name, $value) use (&$vars) {
            $vars[$name] = $value;
        });
        $badges = $this->badges();

        foreach ($grouped as $records) {
            foreach ($records as $record) {
                $vars['record'] = $record;
                $badges->assign($design, 'back_in_stock', 'record', 'records');
                self::assertNotNull($vars['sviat_pd_badge'], "заявка $record->id");
            }
        }

        self::assertCount(1, $this->consents->finds);
    }

    /** Вибірка називає форми свого типу запису, тож іде унікальним ключем (form, record_type, record_id). */
    public function testQueryNamesFormsOfItsRecordType(): void
    {
        $this->badges()->forRecord('feedback', 5, [5]);

        self::assertSame(['feedback'], $this->consents->finds[0]['form'] ?? null);
    }

    private function badges(): ConsentBadges
    {
        return new ConsentBadges($this->mockEntityFactory([ConsentsEntity::class => $this->consents]), $this->registry());
    }

    /** @param array<string, mixed> $get */
    private function journal(array $get): ConsentLogHelper
    {
        $languages = $this->createStub(Languages::class);
        $languages->method('getAllLanguages')->willReturn([]);
        $entityFactory = $this->mockEntityFactory([ConsentsEntity::class => $this->consents, PagesEntity::class => $this->createStub(PagesEntity::class)]);
        $request = new RequestStub([], $get, 'GET');
        $recorder = new ConsentRecorder(new ReflectionClass(ConsentLedger::class)->newInstanceWithoutConstructor(), $this->registry(), $entityFactory, new NullLogger(), $request);

        return new ConsentLogHelper($request, $this->registry(), $entityFactory, $languages, $this->createStub(BackendTranslations::class), $recorder, new NullLogger());
    }

    private function registry(): FormRegistry
    {
        $modules = $this->createStub(Modules::class);
        $modules->method('getRunningModules')->willReturn([]);
        $modules->method('isActiveModule')->willReturn(false);

        return new FormRegistry(
            $modules,
            new FormDescriptor('feedback', 'requests', ['name'], 'feedback.tpl', 'x', 'feedback', 'X', true, null, false),
            new FormDescriptor('callback', 'requests', ['name'], 'callback.tpl', 'x', 'callback', 'X', false, null, false),
            new FormDescriptor('back_in_stock', 'requests', ['name'], 'b.tpl', 'x', 'back_in_stock', 'X', false, null, true),
        );
    }
}
