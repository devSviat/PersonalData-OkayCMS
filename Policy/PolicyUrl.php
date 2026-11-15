<?php

namespace Okay\Modules\Sviat\PersonalData\Policy;

/** Адреса сторінки політики з роутера: лише шлях від кореня `/…`, що не веде на інший хост. */
final class PolicyUrl
{
    public const int MAX_LENGTH = 255;

    public static function validate(string $url): bool
    {
        if ($url === '' || strlen($url) > self::MAX_LENGTH) {
            return false;
        }
        // Перевіряється й декодований вигляд: `%0a`, `%5c` чи `%2f` на початку сервер або браузер може розкодувати.
        foreach ([$url, rawurldecode($url)] as $form) {
            // Браузер читає `\` як `/`, тож `/\evil.example` веде на інший хост.
            if (str_contains($form, '\\') || preg_match('~[\s\p{C}\p{Z}]~u', $form) !== 0) {
                return false;
            }
        }

        return str_starts_with($url, '/') && !str_starts_with(rawurldecode($url), '//');
    }
}
