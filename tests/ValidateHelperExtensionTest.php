<?php

namespace Modules\Sviat\PersonalData;

use Okay\Core\FrontTranslations;
use Okay\Modules\Sviat\PersonalData\Extensions\ValidateHelperExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/RequestStub.php';

use Modules\Sviat\PersonalData\Support\RequestStub;

/**
 * Згода на обробку персональних даних. Розширення підвішується до шести форм
 * магазину (реєстрація, відгук, кошик, передзвін, коментар, підписка) і
 * блокує сабміт, поки чекбокс не поставлено.
 *
 * Правило одне на всі шість методів, тож і тест один — з провайдером по
 * методах, а не шість копій того самого.
 */
class ValidateHelperExtensionTest extends TestCase
{
    private const CONFIRM_ERROR_KEY = 'sviat__personal_data__confirm_error_message';

    /** @var array<string, string> */
    private array $addedTranslations = [];

    private function buildExtension(array $post): ValidateHelperExtension
    {
        $this->addedTranslations = [];

        $translations = $this->createStub(FrontTranslations::class);
        $translations->method('getTranslation')->willReturnArgument(0);
        $translations->method('addTranslation')->willReturnCallback(
            function (string $key, $value): void {
                $this->addedTranslations[$key] = (string) $value;
            }
        );

        return new ValidateHelperExtension(new RequestStub($post), $translations);
    }

    /** @return string[] усі шість точок підключення */
    public static function extensionMethodProvider(): array
    {
        return [
            'реєстрація' => ['extendGetUserRegisterError'],
            'відгук'     => ['extendGetFeedbackValidateError'],
            'кошик'      => ['extendGetCartValidateError'],
            'передзвін'  => ['extendGetCallbackValidateError'],
            'коментар'   => ['extendGetCommentValidateError'],
            'підписка'   => ['extendGetSubscribeValidateError'],
        ];
    }

    /** Чекбокс потрібен, але не поставлений — форма не проходить. */
    #[DataProvider('extensionMethodProvider')]
    public function testUnconfirmedCheckboxBlocksEveryForm(string $method): void
    {
        $extension = $this->buildExtension(['sviat__personal_data__need_confirm' => '1']);

        self::assertSame('empty_name', $extension->$method(null));
    }

    /** Чекбокс поставлено — форма проходить далі без змін. */
    #[DataProvider('extensionMethodProvider')]
    public function testConfirmedCheckboxLetsEveryFormThrough(string $method): void
    {
        $extension = $this->buildExtension([
            'sviat__personal_data__need_confirm' => '1',
            'sviat__personal_data__confirm'      => '1',
        ]);

        self::assertNull($extension->$method(null));
    }

    /**
     * Форма без чекбоксу (модуль на ній не увімкнено) не має раптом вимагати
     * згоди — ознакою є саме приховане поле need_confirm.
     */
    #[DataProvider('extensionMethodProvider')]
    public function testFormWithoutTheCheckboxIsUnaffected(string $method): void
    {
        $extension = $this->buildExtension([]);

        self::assertNull($extension->$method(null));
    }

    /**
     * Чужа помилка має пріоритет: якщо форма вже не пройшла валідацію з іншої
     * причини, її повідомлення не підміняється нашим.
     */
    #[DataProvider('extensionMethodProvider')]
    public function testExistingErrorIsNotOverwritten(string $method): void
    {
        $extension = $this->buildExtension(['sviat__personal_data__need_confirm' => '1']);

        self::assertSame('empty_email', $extension->$method('empty_email'));
        self::assertSame([], $this->addedTranslations, 'переклад не мав підмінятись');
    }

    /**
     * Помилка віддається під кодом empty_name (свого коду в ядрі немає), тому
     * підміняється текст саме цього перекладу — інакше відвідувач побачив би
     * «Введіть імʼя» замість «Підтвердіть згоду».
     */
    public function testErrorMessageIsSwappedForTheConsentWording(): void
    {
        $extension = $this->buildExtension(['sviat__personal_data__need_confirm' => '1']);
        $extension->extendGetCartValidateError(null);

        self::assertSame([self::CONFIRM_ERROR_KEY], array_values($this->addedTranslations));
        self::assertArrayHasKey('form_enter_name', $this->addedTranslations);
    }

    /**
     * Порожні значення чекбокса рахуються як «не поставлено»: браузер шле
     * невідмічений чекбокс або взагалі ніяк, або порожнім рядком.
     */
    #[DataProvider('unconfirmedValueProvider')]
    public function testFalsyCheckboxValuesCountAsUnconfirmed(mixed $confirmValue): void
    {
        $extension = $this->buildExtension([
            'sviat__personal_data__need_confirm' => '1',
            'sviat__personal_data__confirm'      => $confirmValue,
        ]);

        self::assertSame('empty_name', $extension->extendGetCartValidateError(null));
    }

    public static function unconfirmedValueProvider(): array
    {
        return [
            'порожній рядок' => [''],
            'нуль рядком'    => ['0'],
            'null'           => [null],
        ];
    }
}
