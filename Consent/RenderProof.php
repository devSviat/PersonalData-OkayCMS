<?php

namespace Okay\Modules\Sviat\PersonalData\Consent;

/**
 * Доказ показу форми: якою мовою покупець бачив чекбокс і на яку сторінку політики вело посилання.
 * Живе в сесії, а не в базі, бо показ нічого не пише в базу; відправка з тієї ж сесії бере його звідси.
 * Доказ переживає зміну шаблона в межах сесії: покупець, який уже бачив чекбокс, отримає відмову
 * без відмітки, навіть якщо тема його більше не показує. Доказ свідомо не стирається.
 */
final class RenderProof
{
    private const string SESSION_KEY = 'sviat__personal_data';

    /** @param int|null $policyPageId сторінка в посиланні мітки; null — мітка була простим текстом */
    public function record(string $form, int $langId, ?int $policyPageId): void
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }
        $_SESSION[self::SESSION_KEY]['shown'][$form] = ['lang' => $langId, 'page' => $policyPageId];
    }

    /** @return array{langId: int, policyPageId: int|null}|null null — форму в цій сесії не показували */
    public function shown(string $form): ?array
    {
        $proof = $_SESSION[self::SESSION_KEY]['shown'][$form] ?? null;
        $langId = is_array($proof) ? ($proof['lang'] ?? null) : null;
        if (!is_int($langId) || $langId <= 0) {
            return null;
        }
        $pageId = $proof['page'] ?? null;

        return ['langId' => $langId, 'policyPageId' => is_int($pageId) && $pageId > 0 ? $pageId : null];
    }
}
