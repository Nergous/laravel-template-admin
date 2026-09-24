<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Global admin panel search (Cmd+K).
 *
 * Searches users, roles, and media, filtered by view permissions.
 * To add your own entity to the search, add a block below following the pattern.
 */
class AdminSearchController extends Controller
{
    /** Media type labels for the result hint. */
    private const MEDIA_TYPES = [
        'image' => 'Фото',
        'video' => 'Видео',
        'audio' => 'Аудио',
        'document' => 'Документ',
    ];

    /**
     * Returns search results (JSON) across enabled entities, respecting permissions.
     * Queries shorter than 2 characters are ignored; up to 5 matches per entity.
     */
    public function index(Request $request): JsonResponse
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
                        'url' => route('admin.users.show', $u->id),
                        'icon' => 'user',
                    ])
            );
        }

        if ($user?->can('roles.view')) {
            $results = $results->concat(
                Role::search($q)
                    ->orderBy('name')
                    ->limit($limit)
                    ->get(['id', 'name', 'description'])
                    ->map(fn (Role $r) => [
                        'type' => 'role',
                        'label' => $r->name,
                        'meta' => $r->description ?: 'Роль',
                        'url' => route('admin.roles.show', $r->id),
                        'icon' => 'shield',
                    ])
            );
        }

        if ($user?->can('media.view')) {
            $results = $results->concat(
                Media::search($q)
                    ->latest('id')
                    ->limit($limit)
                    ->get(['id', 'filename', 'original_name', 'type'])
                    ->map(fn (Media $m) => [
                        'type' => 'media',
                        'label' => $m->original_name ?: basename($m->filename),
                        'meta' => self::MEDIA_TYPES[$m->type] ?? 'Файл',
                        'url' => route('admin.media.index', ['search' => $m->original_name ?: basename($m->filename)]),
                        'icon' => 'asset',
                    ])
            );
        }

        return response()->json([
            'results' => $results->values(),
        ]);
    }
}
