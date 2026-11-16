<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\System\ActivityLogResource;
use App\Models\ActivityLog;
use App\Services\System\ActivityLogLabels;
use App\Services\System\Dashboard\DashboardSummary;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin dashboard. The blocks and their permission checks come from
 * DashboardSummary.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardSummary $summary, ActivityLogLabels $labels): Response
    {
        $blocks = $summary->for($request->user());

        return Inertia::render('Dashboard', [
            ...$blocks,
            'recentActivity' => $blocks['recentActivity']
                ->map(fn (ActivityLog $entry) => (new ActivityLogResource($entry, $labels))->resolve($request))
                ->values()
                ->all(),
        ]);
    }
}
