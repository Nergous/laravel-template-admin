<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\System\GlobalSearchRequest;
use App\Services\System\GlobalSearch;
use Illuminate\Http\JsonResponse;

/**
 * Global admin search (Cmd+K); GlobalSearch performs the search itself.
 */
class SearchController extends Controller
{
    public function __invoke(GlobalSearchRequest $request, GlobalSearch $search): JsonResponse
    {
        return response()->json([
            'results' => $search->find($request->user(), $request->term()),
        ]);
    }
}
