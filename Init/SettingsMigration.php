<?php

namespace Okay\Modules\Sviat\PersonalData\Init;

/** Стан форм 1.0.1 у ключах 1.1.0. */
final class SettingsMigration
{
    /**
     * Форма 1.1.0 => ключ 1.0.1. `pd_comment` і `pd_fast_order` не переносяться: у 1.0.1 за ними
     * не малювався чекбокс і не перевірялася згода, тож увімкнений ключ нічого не означав.
     */
    public const array CARRIED = [
        'cart' => 'pd_cart',
        'register' => 'pd_register',
        'feedback' => 'pd_feedback',
        'callback' => 'pd_callback',
        'comment_product' => 'pd_comment_product',
    ];

    /**
     * @param array<string, mixed> $old значення `pd_*` з налаштувань 1.0.1
     * @param list<string> $forms ключі форм 1.1.0
     * @return array<string, bool> ключ форми => чи ввімкнена, для кожної з `$forms`
     */
    public static function from101(array $old, array $forms): array
    {
        $result = [];
        foreach ($forms as $key) {
            $oldKey = self::CARRIED[$key] ?? null;
            $result[$key] = $oldKey !== null && (bool) ($old[$oldKey] ?? false);
        }

        return $result;
    }
}
