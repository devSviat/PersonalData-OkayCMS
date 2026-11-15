<?php

namespace Modules\Sviat\PersonalData;

use PHPUnit\Framework\TestCase;

/**
 * Адмінка друкує `{$x}` без автоекранування, тож кожен вивід у шаблонах модуля має `|escape`, без винятків.
 */
class AdminTemplatesEscapeTest extends TestCase
{
    private const string TEMPLATES = __DIR__ . '/../../../../Okay/Modules/Sviat/PersonalData/Backend/design/html';

    public function testEveryPrintIsEscaped(): void
    {
        $files = glob(self::TEMPLATES . '/*.tpl') ?: [];
        self::assertGreaterThanOrEqual(2, count($files));

        $unescaped = [];
        foreach ($files as $file) {
            foreach (self::unescapedPrints((string) file_get_contents($file)) as $tag) {
                $unescaped[] = basename($file) . ': ' . $tag;
            }
        }

        self::assertSame([], $unescaped);
    }

    public function testControlUnescapedPrintIsCaught(): void
    {
        self::assertSame(['{$row.title}', '{$x|date}', '{$p.before_html}'], self::unescapedPrints('<b>{$row.title}</b>{$row.title|escape}{$x|date}{$a = $b|escape}{$p.before_html}'));
    }

    /** @return list<string> */
    private static function unescapedPrints(string $tpl): array
    {
        preg_match_all('~\{\$[^{}]*\}~u', $tpl, $m);

        return array_values(array_filter($m[0], static function (string $tag): bool {
            $body = substr($tag, 1, -1);
            if (preg_match('~^\$[\w.\->\[\]]+\s*=[^=]~u', $body) === 1) {
                return false;
            }

            return preg_match('~\|escape(?::[^|}]*)?(?:\||$)~u', $body) !== 1;
        }));
    }
}
