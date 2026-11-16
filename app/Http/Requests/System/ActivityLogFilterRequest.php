<?php

namespace App\Http\Requests\System;

use App\Rules\FilterList;
use App\Support\FilterValues;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Filters of the activity log list and its export. In the URL, multi-value
 * filters are comma-separated: filters() parses them into lists, formFilters()
 * returns the same values as strings for the page form. They are checked for
 * shape only (FilterList::any()): an unknown value finds nothing, so an old
 * link still opens the log.
 */
class ActivityLogFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('activity-log.view') === true;
    }

    public function rules(): array
    {
        return [
            'action' => ['nullable', FilterList::any()],
            'subject_type' => ['nullable', FilterList::any()],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'user_id' => ['nullable', FilterList::any()],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * Parsed filters for the log queries. An empty list means no filter.
     *
     * @return array{action: list<string>, subject_type: list<string>, subject_id: ?int, user_id: list<int>, date_from: ?string, date_to: ?string}
     */
    public function filters(): array
    {
        $data = $this->validated();
        $subjectTypes = FilterValues::strings($data['subject_type'] ?? null);

        return [
            'action' => FilterValues::strings($data['action'] ?? null),
            'subject_type' => $subjectTypes,
            // A subject id makes sense only together with exactly one type.
            'subject_id' => isset($data['subject_id']) && count($subjectTypes) === 1 ? (int) $data['subject_id'] : null,
            'user_id' => FilterValues::ids($data['user_id'] ?? null),
            'date_from' => $data['date_from'] ?? null,
            'date_to' => $data['date_to'] ?? null,
        ];
    }

    /**
     * The same filters as strings, as they appear in the URL and the page form:
     * several values comma-separated, an empty filter is null.
     *
     * @return array{action: ?string, subject_type: ?string, subject_id: ?string, user_id: ?string, date_from: ?string, date_to: ?string}
     */
    public function formFilters(): array
    {
        $filters = $this->filters();
        $list = fn (array $values) => $values === [] ? null : implode(',', $values);

        return [
            'action' => $list($filters['action']),
            'subject_type' => $list($filters['subject_type']),
            'subject_id' => $filters['subject_id'] === null ? null : (string) $filters['subject_id'],
            'user_id' => $list($filters['user_id']),
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
        ];
    }
}
