<?php

namespace Modules\Sviat\PersonalData;

require_once __DIR__ . '/Support/ModuleTestCase.php';
require_once __DIR__ . '/Support/BuildsConsentGate.php';

use Modules\Sviat\PersonalData\Support\BuildsConsentGate;
use Modules\Sviat\PersonalData\Support\MigratedSettings;
use Okay\Core\Config;
use Okay\Core\Modules\DTO\ModificationDTO;
use Okay\Core\Modules\Module;
use Okay\Core\TplMod\Parser;
use Okay\Core\TplMod\TplMod;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentGate;
use Okay\Modules\Sviat\PersonalData\Forms\Adapters\FastOrderAdapter;
use Psr\Log\NullLogger;
use Modules\Sviat\PersonalData\Support\ModuleTestCase;

/**
 * Ланка FastOrder\Extenders\BackendExtender::ValidateFastOrder: FastOrder повертає список помилок,
 * і згода додається в нього лише тоді, коли інших помилок немає.
 */
class FastOrderAdapterTest extends ModuleTestCase
{
    use BuildsConsentGate;

    private const string FORM = 'fast_order';
    private const string ERROR = 'Позначте згоду.';
    private const string NAME_ERROR = 'Введіть ім\'я';
    private const string MARKER = '{confirm_personal_data_processing form="fast_order"}';
    private const string FIXTURE = __DIR__ . '/fixtures/fast_order_form.tpl';
    private const string MODULE_TEMPLATE = '/Okay/Modules/OkayCMS/FastOrder/design/html/fast_order_form.tpl';

    protected function setUp(): void
    {
        $this->resetConsentState();
    }

    protected function tearDown(): void
    {
        $this->cleanConsentState();
        $_SESSION = [];
        parent::tearDown();
    }

    public function testEmptyErrorsGetConsentError(): void
    {
        $this->shown(self::FORM);

        self::assertSame([ConsentGate::FIELD => self::ERROR], $this->adapter()->extendValidateFastOrder([], (object) [], 17));
        self::assertTrue($this->requestState->isRefused(self::FORM));
        self::assertSame([], $this->issued);
    }

    public function testExistingErrorsUntouched(): void
    {
        $this->shown(self::FORM);
        $errors = [self::NAME_ERROR];

        self::assertSame($errors, $this->adapter()->extendValidateFastOrder($errors, (object) [], 17));
        self::assertSame($errors, $this->adapter(['sviat__personal_data__confirm' => '1'])->extendValidateFastOrder($errors, (object) [], 17));
        self::assertFalse($this->requestState->isRefused(self::FORM), 'помилка поля — не відмова через згоду');
        self::assertSame([], $this->issued, 'відправку з помилкою не прийнято, квитка немає');
    }

    public function testTicketIssuedOnConsent(): void
    {
        $this->shown(self::FORM);

        self::assertSame([], $this->adapter(['sviat__personal_data__confirm' => '1'])->extendValidateFastOrder([], (object) [], 17));
        self::assertFalse($this->requestState->isRefused(self::FORM));
        self::assertSame([self::FORM], $this->issued);
    }

    public function testInactiveWhenModuleNotInstalled(): void
    {
        $this->shown(self::FORM);
        $enabled = $this->enabled();
        $notInstalled = $this->fastOrderAdapter($this->consentGate($enabled), $enabled, false);

        self::assertFalse($notInstalled->isActive());
        self::assertSame([], $notInstalled->extendValidateFastOrder([], (object) [], 17), 'без модуля форми немає, згода не вимагається');
        self::assertSame([], $this->issued);

        self::assertTrue($this->adapter()->isActive(), 'контроль: модуль є, форма увімкнена');
        $disabled = new MigratedSettings();
        self::assertFalse($this->fastOrderAdapter($this->consentGate($disabled, modulesActive: true), $disabled, true)->isActive(), 'форма вимкнена');
    }

    /** FastOrder віддає помилки в JSON як є; ключ поля відрізняє відмову через згоду від помилки імені. */
    public function testErrorKeyedByConfirmField(): void
    {
        $this->shown(self::FORM);

        $errors = $this->adapter()->extendValidateFastOrder([], (object) [], 17);

        self::assertSame(['sviat__personal_data__confirm'], array_keys($errors));
        self::assertSame(ConsentGate::FIELD, array_key_first($errors));
        self::assertSame('{"errors":{"sviat__personal_data__confirm":"' . self::ERROR . '"}}', json_encode(['errors' => $errors], JSON_UNESCAPED_UNICODE));
    }

    /**
     * FastOrder у магазині може бути не встановлено, тож анкер перевіряється на копії його шаблона,
     * а там, де модуль є, ще й на справжньому файлі.
     */
    public function testFastOrderAnchorMatchesFixture(): void
    {
        $this->replaceServiceInContainer(Config::class, $this->createStub(Config::class));
        $fixture = (string) file_get_contents(self::FIXTURE);

        self::assertGreaterThanOrEqual(1, $this->checkboxes($fixture));

        $withoutButton = (string) preg_replace('~<input\b[^\n]*fn_fast_order_submit[^\n]*\n~', '', $fixture, -1, $removed);
        self::assertSame(1, $removed);
        self::assertSame(0, $this->checkboxes($withoutButton), 'контроль: без кнопки відправки анкер мертвий');

        $real = dirname(__DIR__, 4) . self::MODULE_TEMPLATE;
        if (is_file($real)) {
            self::assertGreaterThanOrEqual(1, $this->checkboxes((string) file_get_contents($real)), $real);
        }
    }

    private function checkboxes(string $template): int
    {
        $changes = [];
        /** @var ModificationDTO $modification */
        foreach (new Module(new NullLogger())->getModuleParams('Sviat', 'PersonalData')->getFrontModifications() as $modification) {
            if ($modification->getFile() === 'fast_order_form.tpl') {
                $changes = array_merge($changes, $modification->getChanges());
            }
        }
        self::assertNotSame([], $changes, 'module.json має модифікацію fast_order_form.tpl');

        return substr_count((string) new TplMod(new Parser(), $this->createStub(Config::class))->buildFile($template, $changes), self::MARKER);
    }

    /** @param array<string, mixed> $post */
    private function adapter(array $post = []): FastOrderAdapter
    {
        $settings = $this->enabled();

        return $this->fastOrderAdapter($this->consentGate($settings, $post, modulesActive: true), $settings, true);
    }

    private function enabled(): MigratedSettings
    {
        return new MigratedSettings(['sviat__personal_data__form_' . self::FORM => 1]);
    }
}
