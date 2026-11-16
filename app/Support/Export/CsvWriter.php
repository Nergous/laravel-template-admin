<?php

namespace App\Support\Export;

/**
 * CSV for Excel: UTF-8 with a BOM and ";" as the separator, so Cyrillic and
 * the columns open without the import wizard. Formula-like values get a "'"
 * prefix (CSV injection protection).
 */
final class CsvWriter implements TableWriter
{
    private const SEPARATOR = ';';

    public function write($out, array $header, iterable $rows, string $title): int
    {
        fwrite($out, "\xEF\xBB\xBF");
        $this->line($out, $header);

        $count = 0;
        foreach ($rows as $row) {
            $this->line($out, array_map($this->cell(...), $row));
            $count++;
        }

        return $count;
    }

    /**
     * @param  resource  $out
     * @param  list<string|int|float|null>  $cells
     */
    private function line($out, array $cells): void
    {
        fputcsv($out, $cells, self::SEPARATOR, '"', '');
    }

    /** Defuses formulas: Excel will not evaluate a cell starting with =, +, -, @ or a tab. */
    private function cell(string|int|float|null $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
