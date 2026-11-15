<?php

namespace Modules\Sviat\PersonalData;

require_once __DIR__ . '/Support/ModuleTestCase.php';
require_once __DIR__ . '/Support/FakeDatabase.php';
require_once __DIR__ . '/Support/BuildsConsentGate.php';

use Modules\Sviat\PersonalData\Support\BuildsConsentGate;
use Modules\Sviat\PersonalData\Support\FakeDatabase;
use Modules\Sviat\PersonalData\Support\MigratedSettings;
use Okay\Core\Modules\Modules;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentPolicy;
use Okay\Modules\Sviat\PersonalData\Consent\RenderProof;
use Okay\Modules\Sviat\PersonalData\Core\SchemaState;
use Okay\Modules\Sviat\PersonalData\Forms\FormSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use Modules\Sviat\PersonalData\Support\ModuleTestCase;

/**
 * Коли сервер вимагає згоду: схему оновлено, форма ввімкнена й доступна, і в цій сесії сервер показав
 * покупцеві чекбокс цієї форми (доказ у сесії).
 */
class ConsentPolicyTest extends ModuleTestCase
{
    use BuildsConsentGate;

    private FakeDatabase $database;

    protected function setUp(): void
    {
        $this->database = new FakeDatabase();
        $this->resetConsentState();
    }

    protected function tearDown(): void
    {
        $this->cleanConsentState();
        $_SESSION = [];
        parent::tearDown();
    }

    /** @return array<string, array{bool, bool, bool, bool}> оновлено, увімкнено, доступна, доказ */
    public static function combinations(): array
    {
        $cases = [];
        foreach ([true, false] as $migrated) {
            foreach ([true, false] as $enabled) {
                foreach ([true, false] as $available) {
                    foreach ([true, false] as $proof) {
                        $name = sprintf('оновлено=%d увімкнено=%d доступна=%d доказ=%d', $migrated, $enabled, $available, $proof);
                        $cases[$name] = [$migrated, $enabled, $available, $proof];
                    }
                }
            }
        }

        return $cases;
    }

    /** Форма з модулем-власником, щоб доступність могла бути й хибною. */
    #[DataProvider('combinations')]
    public function testDecisionTable(bool $migrated, bool $enabled, bool $available, bool $proof): void
    {
        $values = ['sviat__personal_data__form_fast_order' => (int) $enabled];
        $settings = $migrated ? new MigratedSettings($values) : MigratedSettings::pending($values);
        if ($proof) {
            $this->shown('fast_order');
        }

        self::assertSame(
            $migrated && $enabled && $available && $proof,
            $this->consentPolicy($settings, modulesActive: $available)->isRequired('fast_order'),
        );
    }

    /** Покупця, якому чекбокса не показували, згода не блокує; контроль — той самий запит із доказом. */
    public function testNoProofNeverRequires(): void
    {
        $settings = $this->settings(['callback' => 1, 'cart' => 1]);

        self::assertFalse($this->consentPolicy($settings)->isRequired('callback'));
        self::assertFalse($this->consentPolicy($settings)->isRequired('cart'));

        $this->shown('callback');
        self::assertTrue($this->consentPolicy($settings)->isRequired('callback'), 'контроль: доказ вимагає згоду');
    }

    /** Доказ лише посилює вимогу: не вмикає вимкнену форму, не діє до оновлення й не переходить на іншу форму. */
    public function testProofCanOnlyTightenNeverRelax(): void
    {
        $this->shown('callback');

        self::assertTrue($this->consentPolicy($this->settings(['callback' => 1, 'feedback' => 1]))->isRequired('callback'));
        self::assertFalse($this->consentPolicy($this->settings(['callback' => 1, 'feedback' => 1]))->isRequired('feedback'), 'доказ іншої форми не вимагає згоди тут');
        self::assertFalse($this->consentPolicy($this->settings(['feedback' => 1]))->isRequired('callback'), 'доказ не вмикає вимкнену форму');
        self::assertFalse($this->consentPolicy(MigratedSettings::pending(['sviat__personal_data__form_callback' => 1]))->isRequired('callback'), 'доказ не діє до оновлення');
    }

    public function testPreMigrationNeverRequires(): void
    {
        $this->shown('callback');
        $this->shown('cart');
        $settings = MigratedSettings::pending(['sviat__personal_data__form_callback' => 1, 'sviat__personal_data__form_cart' => 1]);

        self::assertFalse($this->consentPolicy($settings)->isRequired('callback'));
        self::assertFalse($this->consentPolicy($settings)->isRequired('cart'));
        self::assertTrue($this->consentPolicy($this->settings(['callback' => 1]))->isRequired('callback'), 'контроль: після оновлення вимагає');
    }

    /**
     * Рішення без запитів до бази: маркер і прапорці — з уже завантажених налаштувань, доказ — із сесії,
     * доступність форм модулів — зі списку запущених модулів. База рахує кожен запит схеми стану.
     */
    public function testDecisionReadsNoDatabase(): void
    {
        $settings = $this->settings(['callback' => 1, 'feedback' => 1, 'fast_order' => 1, 'parts_selection' => 1]);
        $modules = $this->createMock(Modules::class);
        $modules->method('getRunningModules')->willReturn([
            'OkayCMS/FastOrder' => ['is_active' => 1],
            'Sviat/PartsSelection' => ['is_active' => 1],
        ]);
        $modules->expects(self::never())->method('isActiveModule');
        // Таблиці модуля є: запит до них не змінив би відповідь, тож його ловить лише лічильник запитів.
        $this->database->tables = array_map(static fn(string $table) => 'ok_' . substr($table, 2), SchemaState::TABLES);
        $policy = new ConsentPolicy($this->schemaState($settings, $this->database), $this->consentRegistry($modules), new FormSettings($settings), new RenderProof());
        $this->shown('callback');
        $this->shown('fast_order');

        self::assertTrue($policy->isRequired('callback'));
        self::assertTrue($policy->isRequired('fast_order'));
        self::assertFalse($policy->isRequired('feedback'));
        self::assertFalse($policy->isRequired('parts_selection'));
        self::assertTrue($policy->applies('parts_selection'));
        self::assertSame([], $this->database->statements);
    }

    public function testUnknownFormNeverRequires(): void
    {
        $this->shown('subscribe');

        self::assertFalse($this->consentPolicy($this->settings(['subscribe' => 1]))->isRequired('subscribe'));
    }

    /** @param array<string, int> $forms */
    private function settings(array $forms): MigratedSettings
    {
        $values = [];
        foreach ($forms as $form => $enabled) {
            $values['sviat__personal_data__form_' . $form] = $enabled;
        }

        return new MigratedSettings($values);
    }
}
