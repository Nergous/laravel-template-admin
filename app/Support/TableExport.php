<?php

namespace App\Support;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A list export in CSV or XLSX with a user-chosen set of columns.
 *
 * Columns are declared once as key => [label, value getter]. The request picks
 * the format (?format=csv|xlsx, CSV by default) and the columns
 * (?columns=key1,key2 — unknown keys are ignored, none means all), keeping the
 * declared order. options() gives the list page the same columns for its picker.
 *
 * @template TRow
 */
final class TableExport
{
    public const FORMATS = ['csv', 'xlsx'];

    /**
     * @param  array<string, array{0: string, 1: Closure(TRow): (string|int|float|null)}>  $columns
     */
    private function __construct(
        private readonly array $columns,
        private readonly string $format,
    ) {}

    /**
     * @template T
     *
     * @param  array<string, array{0: string, 1: Closure(T): (string|int|float|null)}>  $columns
     * @return self<T>
     */
    public static function fromRequest(Request $request, array $columns): self
    {
        $format = in_array($request->query('format'), self::FORMATS, true)
            ? (string) $request->query('format')
            : 'csv';
        $requested = array_filter(explode(',', (string) $request->query('columns', '')));
        $picked = array_intersect_key($columns, array_flip($requested));

        return new self($picked !== [] ? $picked : $columns, $format);
    }

    /**
     * Columns for the export picker on the list page.
     *
     * @param  array<string, array{0: string, 1: Closure}>  $columns
     * @return list<array{key: string, label: string}>
     */
    public static function options(array $columns): array
    {
        return array_map(
            fn (string $key) => ['key' => $key, 'label' => $columns[$key][0]],
            array_keys($columns),
        );
    }

    /**
     * Streams the file. $done receives the number of exported rows once the
     * file has been written (for the activity log).
     *
     * @param  iterable<TRow>  $rows
     * @param  (Closure(int): void)|null  $done
     */
    public function download(iterable $rows, string $basename, string $sheetName, ?Closure $done = null): StreamedResponse
    {
        $header = array_map(fn (array $column) => $column[0], array_values($this->columns));
        $values = function () use ($rows) {
            foreach ($rows as $row) {
                yield array_map(fn (array $column) => $column[1]($row), array_values($this->columns));
            }
        };

        if ($this->format === 'xlsx') {
            return response()->streamDownload(function () use ($header, $values, $sheetName, $done) {
                $path = tempnam(sys_get_temp_dir(), 'xlsx');
                try {
                    $count = XlsxWriter::write($path, $header, $values(), $sheetName);
                    readfile($path);
                } finally {
                    @unlink($path);
                }
                if ($done) {
                    $done($count);
                }
            }, $basename.'.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        }

        return response()->streamDownload(function () use ($header, $values, $done) {
            $out = fopen('php://output', 'wb');
            // BOM and ";" so Excel opens the UTF-8 file with Cyrillic correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, ';');
            $count = 0;
            foreach ($values() as $row) {
                fputcsv($out, array_map(self::csvCell(...), $row), ';');
                $count++;
            }
            fclose($out);
            if ($done) {
                $done($count);
            }
        }, $basename.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** Neutralizes spreadsheet formulas in CSV cells (CSV injection). */
    private static function csvCell(string|int|float|null $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
