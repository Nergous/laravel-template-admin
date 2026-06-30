<?php

namespace App\Http\Sorts;

use Illuminate\Http\Request;

/**
 * Base sort class for lists.
 *
 * Reads the sort and direction parameters from the GET request and validates them
 * against the list of allowed fields. If the passed value is invalid —
 * the default value is used.
 *
 * Subclasses override $allowedSorts and, if needed, $defaultSort.
 *
 * Usage in a controller (Inertia):
 *   public function index(Request $request, UserSort $sort)
 *   {
 *       $query->orderBy($sort->getSort(), $sort->getDirection());
 *       return Inertia::render('Users/Index', [...$sort->toArray()]);
 *   }
 *
 * Parameters arrive in the query string:
 *   ?sort=title&direction=asc
 */
class Sort
{
    protected array $allowedSorts = [];

    protected string $defaultSort = 'id';

    /**
     * Default direction (asc|desc) — the single source of truth.
     * Subclasses override it when needed.
     */
    protected string $defaultDirection = 'desc';

    protected string $sort;

    protected string $direction;

    public function __construct(Request $request)
    {
        $this->sort = in_array($request->sort, $this->allowedSorts)
            ? $request->sort
            : $this->defaultSort;

        $this->direction = in_array($request->direction, ['asc', 'desc'], true)
            ? $request->direction
            : $this->defaultDirection;
    }

    /**
     * The current sort field.
     */
    public function getSort(): string
    {
        return $this->sort;
    }

    /**
     * The current sort direction (asc|desc).
     */
    public function getDirection(): string
    {
        return $this->direction;
    }

    /**
     * Returns the current sort parameters to pass into the Inertia response (props).
     *
     * @return array{currentSort: string, currentDirection: string}
     */
    public function toArray(): array
    {
        return [
            'currentSort' => $this->sort,
            'currentDirection' => $this->direction,
        ];
    }
}
