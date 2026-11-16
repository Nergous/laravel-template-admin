<?php

namespace App\Http\Requests\Concerns;

use App\Enums\ExportFormat;
use App\Support\FilterValues;
use Illuminate\Validation\Rule;

/**
 * Format and columns of a list export: ?format=csv|xlsx (CSV by default) and
 * ?columns=key1,key2 (empty — all; TableExport ignores unknown keys).
 * The request adds exportRules() to its rules().
 */
trait ChoosesExport
{
    /** @return array<string, list<mixed>> */
    protected function exportRules(): array
    {
        return [
            'format' => ['nullable', Rule::enum(ExportFormat::class)],
            'columns' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function exportFormat(): ExportFormat
    {
        return ExportFormat::tryFrom((string) $this->validated('format')) ?? ExportFormat::Csv;
    }

    /** @return list<string> Selected column keys; empty — all columns */
    public function exportColumns(): array
    {
        return FilterValues::strings($this->validated('columns'));
    }
}
