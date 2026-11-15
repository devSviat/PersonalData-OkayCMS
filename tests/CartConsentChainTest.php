<?php

namespace Modules\Sviat\PersonalData;

require_once __DIR__ . '/Support/ModuleTestCase.php';
require_once __DIR__ . '/Support/RequestStub.php';
require_once __DIR__ . '/Support/BuildsConsentGate.php';

use Modules\Sviat\PersonalData\Support\BuildsConsentGate;
use Modules\Sviat\PersonalData\Support\MigratedSettings;
use Okay\Core\EntityFactory;
use Okay\Core\FrontTranslations;
use Okay\Core\Modules\Extender\ChainExtender;
use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Core\Scheduler\Schedule;
use Okay\Core\Settings;
use Okay\Core\Validator;
use Okay\Entities\PaymentsEntity;
use Okay\Helpers\OrdersHelper;
use Okay\Helpers\ValidateHelper;
use Okay\Modules\Sviat\CheckoutContacts\Extensions\ValidateExtension;
use Okay\Modules\Sviat\PersonalData\Core\SchemaState;
use Okay\Modules\Sviat\PersonalData\Forms\Adapters\CartAdapter;
use Okay\Modules\Sviat\PersonalData\Init\Init;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionProperty;
use Modules\Sviat\PersonalData\Support\ModuleTestCase;
use Modules\Sviat\PersonalData\Support\RequestStub;

/**
 * Згода в кошику разом із модулем контактів, що знімає помилку порожнього email. Ланка згоди стоїть
 * на getCartSecurityError: її помилку, як і інші помилки безпеки, ядро показує раніше за помилку
 * контактів, і жодна ланка контактів її не знімає. Порядок ланок не має значення.
 */
class CartConsentChainTest extends ModuleTestCase
{
    use BuildsConsentGate;

    private const string ERROR = 'Позначте згоду.';

    private const array TRIGGERS = [
        ValidateHelper::class . '::getCartValidateError',
        ValidateHelper::class . '::getCartSecurityError',
    ];

    protected function setUp(): void
    {
        if (!class_exists(ValidateExtension::class)) {
            self::markTestSkipped('модуля Sviat/CheckoutContacts немає: ланцюжок кошика з ним перевіряти нічим');
        }
        $this->resetConsentState();
    }

    protected function tearDown(): void
    {
        $this->cleanConsentState();
        $triggers = self::TRIGGERS;
        \Closure::bind(static function () use ($triggers): void {
            foreach ($triggers as $trigger) {
                unset(ChainExtender::$triggers[$trigger]);
            }
        }, null, ChainExtender::class)();
        PersonalDataLink::$target = null;
        CheckoutContactsLink::$target = null;
        $_SESSION = [];
        parent::tearDown();
    }

    /** @return array<string, array{bool, string}> */
    public static function ordersAndEmails(): array
    {
        return [
            'згода перед контактами, email порожній' => [true, ''],
            'контакти перед згодою, email порожній' => [false, ''],
            'згода перед контактами, email заповнений' => [true, 'ivan@example.com'],
            'контакти перед згодою, email заповнений' => [false, 'ivan@example.com'],
        ];
    }

    #[DataProvider('ordersAndEmails')]
    public function testConsentIsChecked(bool $consentFirst, string $email): void
    {
        $this->wire($consentFirst, []);

        self::assertSame(self::ERROR, $this->validator()->getCartValidateError($this->order(email: $email), []));
        self::assertTrue($this->requestState->isRefused('cart'));
    }

    /** Покупцеві в цій сесії чекбокса не показували: згода не вимагається, і квитка немає. */
    #[DataProvider('ordersAndEmails')]
    public function testWithoutProofConsentIsNotRequired(bool $consentFirst, string $email): void
    {
        $this->wire($consentFirst, [], proof: false);

        self::assertNull($this->validator()->getCartValidateError($this->order(email: $email), []));
        self::assertFalse($this->requestState->isRefused('cart'));
        self::assertSame([], $this->issued);
    }

    #[DataProvider('ordersAndEmails')]
    public function testControlConsentGivenPasses(bool $consentFirst, string $email): void
    {
        $this->wire($consentFirst, ['sviat__personal_data__confirm' => '1']);

        self::assertNull($this->validator()->getCartValidateError($this->order(email: $email), []));
        self::assertSame(['cart'], $this->issued, 'один квиток на відправку');
    }

    /**
     * Помилку згоди, як і помилку безпеки, покупець бачить раніше за помилку контактів, і ланка
     * контактів її не знімає.
     */
    #[DataProvider('ordersAndEmails')]
    public function testConsentErrorShownBeforeContactError(bool $consentFirst, string $email): void
    {
        $this->wire($consentFirst, []);

        self::assertSame(self::ERROR, $this->validator()->getCartValidateError($this->order(email: $email, name: ''), []));
        self::assertSame(self::ERROR, $this->validator()->getCartValidateError($this->order(email: 'not-an-email'), []));
    }

    /** Помилку контактів, яку модуль контактів не знімає, згода не підміняє, коли згоду дано. */
    #[DataProvider('ordersAndEmails')]
    public function testControlContactErrorsStillShow(bool $consentFirst, string $email): void
    {
        $this->wire($consentFirst, ['sviat__personal_data__confirm' => '1']);

        self::assertSame('invalid_email', $this->validator()->getCartValidateError($this->order(email: 'not-an-email'), []));
        self::assertSame('empty_name', $this->validator()->getCartValidateError($this->order(email: $email, name: ''), []));
    }

    /**
     * Ланки PersonalData беруться з її Init, тож тест бачить, куди модуль справді підвішує згоду.
     * Ланки модуля контактів — ті самі, що в його Init.
     */
    private function wire(bool $consentFirst, array $post, bool $proof = true): void
    {
        $settings = new MigratedSettings(['sviat__personal_data__form_cart' => 1]);
        if ($proof) {
            $this->shown('cart');
        }
        $this->replaceServiceInContainer(SchemaState::class, $this->schemaState($settings));
        $gate = $this->consentGate($settings, $post, ['sviat__personal_data__error' => self::ERROR]);
        $this->replaceAdaptersInContainer($gate, $settings);
        PersonalDataLink::$target = $this->coreAdapters($gate, $settings)[CartAdapter::class];

        $validator = $this->createStub(Validator::class);
        $validator->method('isPhone')->willReturn(true);
        $validator->method('isComment')->willReturn(true);
        CheckoutContactsLink::$target = new ValidateExtension($validator);

        $consent = [];
        foreach ($this->personalDataChains() as [$class, $method, $extensionClass, $extensionMethod]) {
            if ($class === ValidateHelper::class && in_array($class . '::' . $method, self::TRIGGERS, true)) {
                self::assertSame(CartAdapter::class, $extensionClass);
                $consent[] = [$method, PersonalDataLink::class, $extensionMethod];
            }
        }
        self::assertNotEmpty($consent, 'PersonalData hooks the cart');
        $contacts = [
            ['getCartValidateError', CheckoutContactsLink::class, 'emailNotRequired'],
            ['getCartValidateError', CheckoutContactsLink::class, 'validatePhone'],
        ];

        $chain = new ChainExtender();
        foreach ($consentFirst ? [...$consent, ...$contacts] : [...$contacts, ...$consent] as [$method, $class, $extensionMethod]) {
            $chain->newExtension(ValidateHelper::class, $method, $class, $extensionMethod);
        }
    }

    /** @return list<array{string, string, string, string}> */
    private function personalDataChains(): array
    {
        $init = new ReflectionClass(RecordingInit::class)->newInstanceWithoutConstructor();
        $init->init();

        return $init->chains;
    }

    private function order(string $email = '', string $name = 'Іван'): object
    {
        return (object)[
            'name' => $name,
            'last_name' => '',
            'email' => $email,
            'phone' => '+380671112233',
            'comment' => '',
            'delivery_id' => null,
            'payment_method_id' => null,
        ];
    }

    private function validator(): ValidateHelper
    {
        $settings = new class extends Settings {
            public function __construct() {}
            public function get($param) { return null; }
        };
        // Справжній isPhone() звертається до бази через Phone, а тест має йти без неї.
        $validator = new class extends Validator {
            public function __construct() {}
            public function isPhone($phone = '', $is_required = false) { return true; }
        };
        new ReflectionProperty(Validator::class, 'settings')->setValue($validator, $settings);
        $translations = new class extends FrontTranslations {
            public function __construct() {}
            public function getTranslation($var) { return null; }
        };
        $orders = new ReflectionClass(OrdersHelper::class)->newInstanceWithoutConstructor();
        $entities = $this->createStub(EntityFactory::class);
        $entities->method('get')->willReturn($this->createStub(PaymentsEntity::class));
        new ReflectionProperty(OrdersHelper::class, 'entityFactory')->setValue($orders, $entities);

        return new ValidateHelper($validator, $settings, new RequestStub([]), $translations, $orders);
    }
}

/** ChainExtender створює ланку без аргументів; справжній екземпляр модуля лежить у $target. */
class PersonalDataLink implements ExtensionInterface
{
    public static ?object $target = null;

    public function __call(string $name, array $arguments): mixed
    {
        return self::$target->$name(...$arguments);
    }
}

/** Init модуля без ядра: ланки лише записуються. */
class RecordingInit extends Init
{
    public array $chains = [];

    protected function registerChainExtension($expandable, $extension)
    {
        $this->chains[] = [...$expandable, ...$extension];
    }

    protected function registerQueueExtension($expandable, $extension) {}

    public function registerSchedule(Schedule $schedule): void {}

    protected function addPermission($permission) {}
    protected function registerBackendController($controllerClass) {}
    protected function addBackendControllerPermission($controllerClass, $permission) {}
    protected function addFrontBlock($blockName, $blockTplFile, $callback = null) {}
    protected function addBackendBlock($blockName, $blockTplFile, $callback = null) {}
    protected function extendBackendMenu($firstLevelName, array $menuItemsByControllers, $icon = null) {}
}

class CheckoutContactsLink implements ExtensionInterface
{
    public static ?object $target = null;

    public function __call(string $name, array $arguments): mixed
    {
        return self::$target->$name(...$arguments);
    }
}
