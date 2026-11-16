<?php

namespace App\Support\Export;

use App\Enums\ExportFormat;
use App\Services\System\DisplayTime;
use Closure;

/**
 * A list export with a user-chosen set of columns.
 *
 * Columns are declared once: key => [label, value getter].
 * The user picks the format and the columns (App\Http\Requests\Concerns\ChoosesExport);
 * unknown keys are skipped, an empty choice means all columns, and the order
 * stays as declared. options() gives the same columns to the list page to
 * choose from. The file is written by the format writer (ExportFormat::writer())
 * and sent over HTTP by App\Http\Responses\TableDownload.
 *
 * @template TRow
 */
final class TableExport
{
    /**
     * @param  array<string, array{0: string, 1: Closure(TRow): (string|int|float|null)}>  $columns
     */
    private function __construct(
        private readonly array $columns,
        private readonly ExportFormat $format,
        private readonly DisplayTime $time,
    ) {}

    /**
     * @template T
     *
     * @param  array<string, array{0: string, 1: Closure(T): (string|int|float|null)}>  $columns
     * @param  DisplayTime  $time  Display time zone for the file name
     * @param  list<string>  $picked  Keys of the chosen columns; empty — all
     * @return self<T>
     */
    public static function make(array $columns, ExportFormat $format, DisplayTime $time, array $picked = []): self
    {
        $chosen = array_intersect_key($columns, array_flip($picked));

        return new self($chosen !== [] ? $chosen : $columns, $format, $time);
    }

    /**
     * Columns to choose from on the list page.
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

    public function format(): ExportFormat
    {
        return $this->format;
    }

    /** File name: $name, the export date and time in the display time zone, the format extension. */
    public function filename(string $name): string
    {
        return $name.'-'.$this->time->now()->format('Y-m-d_His').'.'.$this->format->extension();
    }

    /**
     * Writes the table to the stream and returns the number of data rows.
     *
     * @param  resource  $out
     * @param  iterable<TRow>  $rows
     */
    public function write($out, iterable $rows, string $title): int
    {
        $columns = array_values($this->columns);
        $header = array_map(fn (array $column) => $column[0], $columns);
        $values = (function () use ($rows, $columns) {
            foreach ($rows as $row) {
                yield array_map(fn (array $column) => $column[1]($row), $columns);
            }
        })();

        return $this->format->writer()->write($out, $header, $values, $title);
    }
}
