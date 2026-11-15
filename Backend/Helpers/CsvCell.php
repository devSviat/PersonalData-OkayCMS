<?php

namespace Okay\Modules\Sviat\PersonalData\Backend\Helpers;

use Okay\Core\Export\CsvWriter;

/** Комірки експорту журналу: ті самі правила, що й у експортах ядра, але рядок віддається потоком, а не у файл. */
final class CsvCell
{
    public static function safe(mixed $value): string
    {
        return CsvWriter::escapeValue($value ?? '');
    }

    /** @param list<mixed> $values */
    public static function line(array $values): string
    {
        $handle = fopen('php://memory', 'r+');
        fputcsv($handle, array_map(self::safe(...), $values), ';', '"', '');
        rewind($handle);
        $line = (string) stream_get_contents($handle);
        fclose($handle);

        return $line;
    }
}
