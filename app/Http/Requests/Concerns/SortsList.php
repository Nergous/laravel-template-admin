<?php

namespace App\Http\Requests\Concerns;

/**
 * List sorting from ?sort=column&direction=asc|desc.
 *
 * Only columns from sortable() are accepted, so no arbitrary column name
 * reaches orderBy. Any other value falls back to the default without a
 * validation error, as in PaginatesList: an old or hand-edited link still
 * opens the list. A list declares its columns in sortable() and overrides
 * defaultSort() and defaultDirection() when needed.
 */
trait SortsList
{
    /** @return list<string> Sortable columns */
    abstract protected function sortable(): array;

    public function sortColumn(): string
    {
        $column = $this->query('sort');

        return in_array($column, $this->sortable(), true) ? $column : $this->defaultSort();
    }

    /** @return 'asc'|'desc' */
    public function sortDirection(): string
    {
        $direction = $this->query('direction');

        return in_array($direction, ['asc', 'desc'], true) ? $direction : $this->defaultDirection();
    }

    /**
     * Props of the current sort: what is actually applied, not what came in the link.
     *
     * @return array{currentSort: string, currentDirection: string}
     */
    public function sortProps(): array
    {
        return [
            'currentSort' => $this->sortColumn(),
            'currentDirection' => $this->sortDirection(),
        ];
    }

    protected function defaultSort(): string
    {
        return 'id';
    }

    /** @return 'asc'|'desc' */
    protected function defaultDirection(): string
    {
        return 'desc';
    }
}
