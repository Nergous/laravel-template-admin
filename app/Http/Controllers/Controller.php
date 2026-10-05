<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Base application controller — common ancestor of all controllers.
 *
 * Authorization convention of the admin panel (each layer has one job):
 *  - route middleware (`permission:x` in routes/web.php) decides whether the
 *    user may perform the action at all; resource routes attach the action's
 *    permission with middlewareFor();
 *  - a FormRequest's authorize() re-checks the same concrete permission
 *    (defence in depth, never just "is signed in") plus record-level rules
 *    (RbacGuard: may the actor touch this user/role);
 *  - controllers do not repeat permission checks. They only enforce
 *    record-level rules for actions without a FormRequest (GET edit pages) and
 *    per-record rules of bulk actions, which are reported as skipped;
 *  - services keep the domain invariants (anti-escalation, protected roles)
 *    whoever calls them.
 */
abstract class Controller
{
    /**
     * Returns to the list the action was started from, keeping its filters,
     * sorting and page. Any other origin (a detail page of a deleted record)
     * goes to the plain list.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function redirectToList(string $route, array $parameters = []): RedirectResponse
    {
        $list = route($route, $parameters);
        $previous = url()->previous();

        $sameList = parse_url($previous, PHP_URL_HOST) === parse_url($list, PHP_URL_HOST)
            && rtrim((string) parse_url($previous, PHP_URL_PATH), '/') === rtrim((string) parse_url($list, PHP_URL_PATH), '/');

        return redirect()->to($sameList ? $previous : $list);
    }

    /**
     * A redirect to the last page when the requested page is past the end, which
     * happens after the last rows of a page are deleted or filtered out.
     */
    protected function redirectPastLastPage(LengthAwarePaginator $paginator, Request $request): ?RedirectResponse
    {
        if ($paginator->currentPage() <= 1 || $paginator->currentPage() <= $paginator->lastPage()) {
            return null;
        }

        return redirect()->to($request->fullUrlWithQuery(['page' => $paginator->lastPage()]));
    }

    /** Flash text for a bulk action: "Удалено: 5. Пропущено: 2 (причина)". */
    protected function bulkSummary(string $label, int $processed, int $skipped, string $reason): string
    {
        return $skipped > 0 ? "{$label}: {$processed}. Пропущено: {$skipped} ({$reason})" : "{$label}: {$processed}";
    }
}
