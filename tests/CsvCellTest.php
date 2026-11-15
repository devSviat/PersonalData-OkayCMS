<?php

namespace Modules\Sviat\PersonalData;

use Okay\Modules\Sviat\PersonalData\Backend\Helpers\CsvCell;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Комірка експорту журналу не виконується як формула, а рядок CSV лишається одним рядком таблиці. */
class CsvCellTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function formulas(): iterable
    {
        yield 'equals' => ['=1+1'];
        yield 'plus' => ['+cmd'];
        yield 'minus' => ['-2+3'];
        yield 'at' => ['@SUM(A1)'];
        yield 'tab' => ["\t=1+1"];
        yield 'cr' => ["\r=1+1"];
    }

    #[DataProvider('formulas')]
    public function testExportNeutralizesFormulas(string $value): void
    {
        self::assertSame("'" . $value, CsvCell::safe($value));
    }

    public function testPlainValuesUntouched(): void
    {
        self::assertSame('anna', CsvCell::safe('anna'));
        self::assertSame('', CsvCell::safe(null));
        self::assertSame('17', CsvCell::safe(17));
    }

    public function testLineQuotesSeparatorsAndBreaks(): void
    {
        $line = CsvCell::line(['a;b', "x\ny", '=1', null]);

        self::assertSame("\"a;b\";\"x\ny\";'=1;\n", $line);
    }
}
