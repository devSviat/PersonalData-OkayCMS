<?php

namespace Modules\Sviat\PersonalData;

require_once __DIR__ . '/Support/MigratedSettings.php';

use Modules\Sviat\PersonalData\Support\MigratedSettings;
use Okay\Modules\Sviat\PersonalData\Forms\FormSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FormSettingsTest extends TestCase
{
    public function testMissingKeyIsOff(): void
    {
        $settings = new FormSettings(new MigratedSettings(['pd_cart' => 1]));

        self::assertFalse($settings->isEnabled('cart'));
    }

    /** @return array<string, array{mixed, int}> */
    public static function retentionValues(): array
    {
        return [
            'не задано' => [null, 3],
            'нуль' => [0, 1],
            'відʼємне' => ['-4', 1],
            'забагато' => [99, 10],
            'у межах' => ['5', 5],
            'не число' => ['abc', 3],
        ];
    }

    #[DataProvider('retentionValues')]
    public function testRetentionClamped(mixed $stored, int $expected): void
    {
        $settings = new FormSettings(new MigratedSettings(['sviat__personal_data__retention_years' => $stored]));

        self::assertSame($expected, $settings->retentionYears());
    }

    public function testEnableAndDisable(): void
    {
        $store = new MigratedSettings();
        $settings = new FormSettings($store);

        $settings->enable('callback');

        self::assertTrue($settings->isEnabled('callback'));
        self::assertSame(1, $store->values['sviat__personal_data__form_callback']);
        self::assertFalse($settings->isEnabled('feedback'));

        $settings->disable('callback');

        self::assertFalse($settings->isEnabled('callback'));
        self::assertSame(0, $store->values['sviat__personal_data__form_callback']);
    }
}
