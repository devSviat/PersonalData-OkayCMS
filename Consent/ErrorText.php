<?php

namespace Okay\Modules\Sviat\PersonalData\Consent;

use Okay\Core\FrontTranslations;

/** Текст відмови без згоди мовою сторінки. Порожнім не буває: порожня помилка означала б, що згоду дано. */
final readonly class ErrorText
{
    public const string KEY = 'sviat__personal_data__error';
    public const string BUILT_IN = 'Потрібна ваша згода';

    public function __construct(private FrontTranslations $translations) {}

    public function get(): string
    {
        $text = $this->translations->getTranslation(self::KEY);

        return is_string($text) && trim($text) !== '' ? $text : self::BUILT_IN;
    }
}
