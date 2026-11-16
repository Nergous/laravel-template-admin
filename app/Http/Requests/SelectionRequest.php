<?php

namespace App\Http\Requests;

use App\Support\Selection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Records selected for a bulk list action: either ids[] or all=1 with the list
 * filters under the same names as in the list request — then every matching
 * record is selected. Filters are validated strictly so a typo cannot widen
 * an "all matching" selection. The list service applies per-record rules and
 * reports the skipped ones.
 */
abstract class SelectionRequest extends FormRequest
{
    /** Maximum number of ids in one request. */
    public const MAX_IDS = 500;

    /**
     * Permissions the action requires — the same as its route's.
     *
     * @return list<string>
     */
    abstract protected function permissions(): array;

    /**
     * Rules of the list filters accepted by an all=1 selection, by filter name.
     *
     * @return array<string, list<mixed>>
     */
    abstract protected function filterRules(): array;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && array_all($this->permissions(), fn (string $permission) => $user->can($permission));
    }

    public function rules(): array
    {
        return [
            'all' => ['sometimes', 'boolean'],
            'ids' => [Rule::requiredIf(fn () => ! $this->boolean('all')), 'array', 'min:1', 'max:'.self::MAX_IDS],
            'ids.*' => ['integer'],
            ...$this->filterRules(),
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => $this->emptySelectionMessage(),
            'ids.min' => $this->emptySelectionMessage(),
        ];
    }

    public function selection(): Selection
    {
        return $this->boolean('all')
            ? Selection::matching($this->selectedFilters())
            : Selection::ids($this->validated('ids', []));
    }

    /**
     * Filters of an all=1 selection that have a value.
     *
     * @return array<string, mixed>
     */
    protected function selectedFilters(): array
    {
        return array_filter(
            $this->safe()->only(array_keys($this->filterRules())),
            fn (mixed $value) => $value !== null && $value !== '',
        );
    }

    protected function emptySelectionMessage(): string
    {
        return 'Выберите хотя бы одну запись';
    }
}
