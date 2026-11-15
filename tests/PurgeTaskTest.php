<?php

namespace Modules\Sviat\PersonalData;

require_once __DIR__ . '/Support/ModuleTestCase.php';
require_once __DIR__ . '/Support/BuildsConsentLedger.php';

use Modules\Sviat\PersonalData\Support\BuildsConsentLedger;
use Okay\Modules\Sviat\PersonalData\Forms\FormSettings;
use Okay\Modules\Sviat\PersonalData\Scheduler\PurgeTask;
use Modules\Sviat\PersonalData\Support\ModuleTestCase;

/** Строк зберігання рахується від знеособлення, а не від дати згоди. */
class PurgeTaskTest extends ModuleTestCase
{
    use BuildsConsentLedger;

    protected function setUp(): void
    {
        $this->resetLedgerState(['sviat__personal_data__retention_years' => 3]);
    }

    protected function tearDown(): void
    {
        $this->cleanLedgerState();
        parent::tearDown();
    }

    public function testCountsFromAnonymizedAt(): void
    {
        $oldConsentRecentlyAnonymized = $this->consents->seed(['given_at' => self::ago('-6 years'), 'anonymized_at' => self::ago('-1 year')]);
        $expired = $this->consents->seed(['given_at' => self::ago('-4 years'), 'anonymized_at' => self::ago('-3 years -1 day')]);
        $justInside = $this->consents->seed(['given_at' => self::ago('-4 years'), 'anonymized_at' => self::ago('-3 years +1 day')]);
        $oldButActive = $this->consents->seed(['given_at' => self::ago('-9 years'), 'record_id' => 5]);

        self::assertSame(1, $this->task()->run());

        self::assertSame([$oldConsentRecentlyAnonymized, $justInside, $oldButActive], array_keys($this->consents->rows));
        self::assertArrayNotHasKey($expired, $this->consents->rows);
    }

    public function testPurgeIsIdempotent(): void
    {
        foreach (range(1, 501) as $ignored) {
            $this->consents->seed(['anonymized_at' => self::ago('-5 years')]);
        }
        $kept = $this->consents->seed(['anonymized_at' => self::ago('-1 year')]);

        self::assertSame(501, $this->task()->run());
        self::assertSame(0, $this->task()->run());
        self::assertSame([$kept], array_keys($this->consents->rows));
    }

    /** DELETE, який база «ковтнула», не зациклює завдання. */
    public function testSilentDeleteFailureStops(): void
    {
        foreach (range(1, 600) as $ignored) {
            $this->consents->seed(['anonymized_at' => self::ago('-5 years')]);
        }
        $this->consents->deleteSilentlyFails = true;

        $this->task()->run();

        self::assertCount(600, $this->consents->rows);
        self::assertStringContainsString('consent purge stopped', implode("\n", $this->logged));
    }

    private static function ago(string $modifier): string
    {
        return new \DateTimeImmutable()->modify($modifier)->format('Y-m-d H:i:s');
    }

    private function task(): PurgeTask
    {
        return new PurgeTask(new FormSettings($this->settings), $this->entityFactory(), $this->logger());
    }
}
