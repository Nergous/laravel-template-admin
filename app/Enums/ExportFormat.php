<?php

namespace App\Enums;

use App\Support\Export\CsvWriter;
use App\Support\Export\TableWriter;
use App\Support\Export\XlsxWriter;

/** List export format: file extension, content type and table writer. */
enum ExportFormat: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';

    public function extension(): string
    {
        return $this->value;
    }

    public function contentType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv; charset=UTF-8',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }

    public function writer(): TableWriter
    {
        return match ($this) {
            self::Csv => new CsvWriter,
            self::Xlsx => new XlsxWriter,
        };
    }
}
