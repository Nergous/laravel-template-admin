<?php

namespace App\Http\Pagination;

use Illuminate\Http\Request;

/**
 * Page size for server-paginated lists.
 *
 * Reads ?per_page from the request and accepts only whitelisted values, so a
 * client cannot request an unbounded page. Injected into controller actions by
 * type-hint, like the Sort strategies:
 *
 *   public function index(Request $request, UserSort $sort, PerPage $perPage)
 *   {
 *       $query->paginate($perPage->get());
 *       return Inertia::render('Users/Index', [...$perPage->toArray()]);
 *   }
 */
class PerPage
{
    /** @var array<int, int> */
    public const OPTIONS = [10, 25, 50, 100];

    public const DEFAULT = 10;

    private int $perPage;

    public function __construct(Request $request)
    {
        $requested = filter_var($request->query('per_page'), FILTER_VALIDATE_INT);

        $this->perPage = in_array($requested, static::OPTIONS, true)
            ? $requested
            : static::DEFAULT;
    }

    public function get(): int
    {
        return $this->perPage;
    }

    /**
     * Props for the page-size selector.
     *
     * @return array{perPage: int, perPageOptions: array<int, int>}
     */
    public function toArray(): array
    {
        return [
            'perPage' => $this->perPage,
            'perPageOptions' => static::OPTIONS,
        ];
    }
}
