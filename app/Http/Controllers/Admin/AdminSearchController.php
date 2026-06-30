<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Global admin panel search (Cmd+K).
 *
 * By default searches across users (for admins) and photos.
 * To add your own entity to the search, add a block below following the pattern.
 */
class AdminSearchController extends Controller
{
    /**
     * Returns search results (JSON) across users and media, respecting permissions.
     * Queries shorter than 2 characters are ignored; up to 5 matches per entity.
     */
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        $limit = 5;
        $user = $request->user();

        $results = collect();

        if ($user?->can('users.view')) {
            $results = $results->concat(
                User::search($q)
                    ->limit($limit)
                    ->get(['id', 'name', 'email'])
                    ->map(fn ($u) => [
                        'type' => 'user',
                        'label' => $u->name,
                        'meta' => $u->email,
                        'url' => route('admin.users.edit', $u->id),
                        'icon' => 'user',
                    ])
            );
        }

        if ($user?->can('media.view')) {
            $results = $results->concat(
                Media::search($q)
                    ->limit($limit)
                    ->get(['id', 'filename'])
                    ->map(fn ($p) => [
                        'type' => 'media',
                        'label' => basename($p->filename),
                        'meta' => 'Фото',
                        'url' => route('admin.media.index'),
                        'icon' => 'image',
                    ])
            );
        }

        return response()->json([
            'results' => $results->values(),
        ]);
    }
}
