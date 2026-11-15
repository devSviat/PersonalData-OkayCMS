<?php

namespace Modules\Sviat\PersonalData;

use PHPUnit\Framework\TestCase;

/**
 * Модуль публікується окремим публічним репозиторієм, тож назви магазину й тем не мають
 * потрапляти ні в код, ні в тести. Слова задає phpunit.xml магазину; поза ним список порожній.
 */
class NoProjectNamesTest extends TestCase
{
    private const array ROOTS = [
        'Okay/Modules/Sviat/PersonalData',
        'tests/Modules/Sviat/PersonalData',
    ];

    public function testModuleNeverNamesTheShopOrItsThemes(): void
    {
        $words = array_values(array_filter(array_map('trim', explode(',', (string) getenv('OKAY_FORBIDDEN_NAMES')))));
        if ($words === []) {
            self::markTestSkipped('OKAY_FORBIDDEN_NAMES порожня: перевіряти нічого');
        }

        $base = dirname(__DIR__, 4);
        $hits = [];
        foreach (self::ROOTS as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base . '/' . $root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $relative = substr($file->getPathname(), strlen($base) + 1);
                $haystack = $relative . "\n" . file_get_contents($file->getPathname());
                foreach ($words as $word) {
                    if (stripos($haystack, $word) !== false) {
                        $hits[] = $relative;
                    }
                }
            }
        }

        self::assertSame([], array_values(array_unique($hits)));
    }
}
