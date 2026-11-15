<?php

namespace Modules\Sviat\PersonalData;

require_once __DIR__ . '/Support/ModuleTestCase.php';
require_once __DIR__ . '/Support/BuildsConsentGate.php';

use Modules\Sviat\PersonalData\Support\BuildsConsentGate;
use Modules\Sviat\PersonalData\Support\MigratedSettings;
use Okay\Core\FrontTranslations;
use Okay\Modules\Sviat\PersonalData\Forms\FormSettings;
use Okay\Modules\Sviat\PersonalData\Consent\ErrorText;
use Okay\Modules\Sviat\PersonalData\Forms\Adapters\CallbackAdapter;
use Okay\Modules\Sviat\PersonalData\Forms\Adapters\CartAdapter;
use Okay\Modules\Sviat\PersonalData\Forms\Adapters\CommentAdapter;
use Okay\Modules\Sviat\PersonalData\Forms\Adapters\FeedbackAdapter;
use Okay\Modules\Sviat\PersonalData\Forms\Adapters\RegisterAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use Modules\Sviat\PersonalData\Support\ModuleTestCase;

/**
 * Ланки форм ядра: кожна передає помилку ядра й свій ключ форми шлюзу згоди.
 * Ключ форми задає ланка, а не запит.
 */
class CoreAdaptersTest extends ModuleTestCase
{
    use BuildsConsentGate;

    private const string ERROR = 'Позначте згоду.';

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

    /** @return array<string, array{class-string, string, string, list<mixed>}> адаптер, метод ланки, форма, решта аргументів ядра */
    public static function links(): array
    {
        return [
            'кошик' => [CartAdapter::class, 'extendGetCartSecurityError', 'cart', [(object) [], []]],
            'реєстрація' => [RegisterAdapter::class, 'extendGetUserRegisterError', 'register', [(object) []]],
            'зв\'язок' => [FeedbackAdapter::class, 'extendGetFeedbackValidateError', 'feedback', [(object) []]],
            'передзвін' => [CallbackAdapter::class, 'extendGetCallbackValidateError', 'callback', [(object) []]],
            'відгук на товар' => [CommentAdapter::class, 'extendGetCommentValidateError', 'comment_product', [(object) [], 'product']],
            'коментар до статті' => [CommentAdapter::class, 'extendGetCommentValidateError', 'comment_post', [(object) [], 'post']],
        ];
    }

    #[DataProvider('links')]
    public function testRefusedWithoutConfirmation(string $adapter, string $method, string $form, array $args): void
    {
        $settings = $this->enabled($form);
        $this->shown($form);

        self::assertSame(self::ERROR, $this->adapter($adapter, $settings)->$method(null, ...$args));
        self::assertTrue($this->requestState->isRefused($form));
        self::assertSame([], $this->issued);
    }

    #[DataProvider('links')]
    public function testConfirmedPassesAndIssuesTicket(string $adapter, string $method, string $form, array $args): void
    {
        $settings = $this->enabled($form);
        $this->shown($form);

        self::assertNull($this->adapter($adapter, $settings, ['sviat__personal_data__confirm' => '1'])->$method(null, ...$args));
        self::assertFalse($this->requestState->isRefused($form));
        self::assertSame([$form], $this->issued);
    }

    #[DataProvider('links')]
    public function testFieldErrorWins(string $adapter, string $method, string $form, array $args): void
    {
        $settings = $this->enabled($form);
        $this->shown($form);

        self::assertSame('empty_email', $this->adapter($adapter, $settings)->$method('empty_email', ...$args));
        self::assertSame('empty_email', $this->adapter($adapter, $settings, ['sviat__personal_data__confirm' => '1'])->$method('empty_email', ...$args));
        self::assertFalse($this->requestState->isRefused($form), 'помилка поля — не відмова через згоду');
        self::assertSame([], $this->issued, 'відправку з помилкою не прийнято, квитка немає');
    }

    #[DataProvider('links')]
    public function testEmptyStringCountsAsNoError(string $adapter, string $method, string $form, array $args): void
    {
        $settings = $this->enabled($form);
        $this->shown($form);

        self::assertSame(self::ERROR, $this->adapter($adapter, $settings)->$method('', ...$args));
        self::assertNull($this->adapter($adapter, $settings, ['sviat__personal_data__confirm' => '1'])->$method('', ...$args));
    }

    #[DataProvider('links')]
    public function testDisabledFormPassesUntouched(string $adapter, string $method, string $form, array $args): void
    {
        $this->shown($form);
        $settings = new MigratedSettings();

        self::assertNull($this->adapter($adapter, $settings)->$method(null, ...$args));
        self::assertFalse($this->adapter($adapter, $settings)->isActive());
        self::assertTrue($this->adapter($adapter, $this->enabled($form))->isActive(), 'контроль: увімкнена форма активна');
    }

    /** Помилка — текст для покупця: теми друкують невідому помилку як є, а код `empty_name` показав би «Введіть ім'я». */
    public function testErrorIsTextNotCode(): void
    {
        $this->shown('feedback');
        $error = $this->adapter(FeedbackAdapter::class, $this->enabled('feedback'))->extendGetFeedbackValidateError(null, (object) []);

        self::assertSame(self::ERROR, $error);
        self::assertDoesNotMatchRegularExpression('~^[a-z_]+$~', (string) $error);
    }

    public function testMissingTranslationStillRefuses(): void
    {
        $this->shown('callback');
        $gate = $this->consentGate($this->enabled('callback'), translations: null);

        $error = new CallbackAdapter($gate, new FormSettings($this->enabled('callback')))->extendGetCallbackValidateError(null, (object) []);

        self::assertSame(ErrorText::BUILT_IN, $error);
        self::assertNotSame('', trim((string) $error));
        self::assertSame(ErrorText::BUILT_IN, $this->errorText(['sviat__personal_data__error' => '  '])->get(), 'порожній переклад теж відсутній');
        self::assertSame('Текст.', $this->errorText(['sviat__personal_data__error' => 'Текст.'])->get());
    }

    /** Шлюз не підміняє чужих перекладів: `form_enter_name` лишається «Введіть ім'я». */
    public function testFormEnterNameIsNotOverwritten(): void
    {
        $translations = $this->createMock(FrontTranslations::class);
        $translations->expects(self::never())->method('addTranslation');
        $translations->method('getTranslation')->willReturn(self::ERROR);
        $this->shown('register');
        $settings = $this->enabled('register');
        $gate = $this->consentGate($settings, frontTranslations: $translations);

        self::assertSame(self::ERROR, new RegisterAdapter($gate, new FormSettings($settings))->extendGetUserRegisterError(null, (object) []));
    }

    /** T4: поле `form` у POST кошика нічого не змінює; у POST береться лише відмітка. */
    public function testFormKeyNeverReadFromRequest(): void
    {
        $post = ['form' => 'feedback', 'sviat__personal_data__form' => 'feedback', 'key' => 'feedback'];
        $this->shown('cart');
        $this->shown('feedback');

        $cartOnly = new MigratedSettings(['sviat__personal_data__form_cart' => 1]);
        self::assertSame(self::ERROR, $this->adapter(CartAdapter::class, $cartOnly, $post)->extendGetCartSecurityError(null, (object) [], []));
        self::assertTrue($this->requestState->isRefused('cart'));
        self::assertFalse($this->requestState->isRefused('feedback'));

        $this->resetConsentState();
        $this->shown('cart');
        $this->shown('feedback');
        $feedbackOnly = new MigratedSettings(['sviat__personal_data__form_feedback' => 1]);
        self::assertNull($this->adapter(CartAdapter::class, $feedbackOnly, $post)->extendGetCartSecurityError(null, (object) [], []), 'вимкнений кошик не стає зв\'язком');
        self::assertFalse($this->requestState->isRefused('feedback'));

        $this->resetConsentState();
        $this->shown('cart');
        self::assertNull($this->adapter(CartAdapter::class, $cartOnly, $post + ['sviat__personal_data__confirm' => '1'])->extendGetCartSecurityError(null, (object) [], []));
        self::assertSame(['cart'], $this->issued, 'квиток на форму ланки, а не з запиту');
    }

    public function testCommentFormsByType(): void
    {
        $this->shown('comment_product');
        $this->shown('comment_post');
        $productOnly = $this->enabled('comment_product');
        $both = new MigratedSettings(['sviat__personal_data__form_comment_product' => 1, 'sviat__personal_data__form_comment_post' => 1]);

        self::assertSame(self::ERROR, $this->adapter(CommentAdapter::class, $productOnly)->extendGetCommentValidateError(null, (object) [], 'product'));
        self::assertTrue($this->requestState->isRefused('comment_product'));
        self::assertNull($this->adapter(CommentAdapter::class, $productOnly)->extendGetCommentValidateError(null, (object) [], 'post'), 'стаття вимкнена');
        self::assertFalse($this->requestState->isRefused('comment_post'));

        self::assertSame(self::ERROR, $this->adapter(CommentAdapter::class, $both)->extendGetCommentValidateError(null, (object) [], 'post'));
        self::assertTrue($this->requestState->isRefused('comment_post'));

        foreach ([null, '', 'category', 'Product', ['product']] as $type) {
            self::assertNull($this->adapter(CommentAdapter::class, $both)->extendGetCommentValidateError(null, (object) [], $type), var_export($type, true));
            self::assertSame('empty_name', $this->adapter(CommentAdapter::class, $both)->extendGetCommentValidateError('empty_name', (object) [], $type));
        }
        self::assertNull($this->adapter(CommentAdapter::class, $both)->extendGetCommentValidateError(null), 'без типу від ядра');

        self::assertTrue($this->adapter(CommentAdapter::class, $this->enabled('comment_post'))->isActive());
        self::assertFalse($this->adapter(CommentAdapter::class, new MigratedSettings())->isActive());
    }

    /** Невідмічений чекбокс браузер не шле або шле порожнім; приймається лише значення поля з шаблона. */
    #[DataProvider('unconfirmedValues')]
    public function testOnlyTemplateValueConfirms(mixed $value): void
    {
        $this->shown('callback');

        self::assertSame(self::ERROR, $this->adapter(CallbackAdapter::class, $this->enabled('callback'), ['sviat__personal_data__confirm' => $value])->extendGetCallbackValidateError(null, (object) []));
    }

    /** @return array<string, array{mixed}> */
    public static function unconfirmedValues(): array
    {
        return [
            'порожній рядок' => [''],
            'нуль' => ['0'],
            'null' => [null],
            'масив' => [['1']],
        ];
    }

    /** @param array<string, mixed> $post */
    private function adapter(string $class, MigratedSettings $settings, array $post = []): object
    {
        return $this->coreAdapters($this->consentGate($settings, $post), $settings)[$class];
    }

    private function enabled(string $form): MigratedSettings
    {
        return new MigratedSettings(['sviat__personal_data__form_' . $form => 1]);
    }

    /** @param array<string, mixed> $translations */
    private function errorText(array $translations): ErrorText
    {
        $stub = $this->createStub(FrontTranslations::class);
        $stub->method('getTranslation')->willReturnCallback(static fn($key) => $translations[$key] ?? null);

        return new ErrorText($stub);
    }
}
