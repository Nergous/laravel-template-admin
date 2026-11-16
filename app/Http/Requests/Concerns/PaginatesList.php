<?php

namespace App\Http\Requests\Concerns;

/**
 * Page size of a server-paginated list, from ?per_page.
 *
 * Only the listed sizes are accepted, so a client cannot request an unbounded
 * page. Any other value falls back to the default without a validation error,
 * so an old or hand-edited link still opens the list. A list with another
 * default overrides defaultPerPage().
 */
trait PaginatesList
{
    /** @var list<int> */
    public const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    public function perPage(): int
    {
        $requested = filter_var($this->query('per_page'), FILTER_VALIDATE_INT);

        return in_array($requested, self::PER_PAGE_OPTIONS, true) ? $requested : $this->defaultPerPage();
    }

    /**
     * Props for the page size picker.
     *
     * @return array{perPage: int, perPageOptions: list<int>}
     */
    public function perPageProps(): array
    {
        return [
            'perPage' => $this->perPage(),
            'perPageOptions' => self::PER_PAGE_OPTIONS,
        ];
    }

    protected function defaultPerPage(): int
    {
        return 10;
    }
}
