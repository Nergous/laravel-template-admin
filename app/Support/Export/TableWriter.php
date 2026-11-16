<?php

namespace App\Support\Export;

/** Writes a table (header and rows) to an open stream in its own format. */
interface TableWriter
{
    /**
     * @param  resource  $out  Stream the file is written to
     * @param  list<string>  $header  Column labels
     * @param  iterable<list<string|int|float|null>>  $rows  Row values in column order
     * @param  string  $title  Table name (the XLSX sheet); formats without sheets ignore it
     * @return int Number of data rows written
     */
    public function write($out, array $header, iterable $rows, string $title): int;
}
