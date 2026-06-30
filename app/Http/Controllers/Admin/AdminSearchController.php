<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Глобальный поиск админ-панели (Cmd+K).
 *
 * По умолчанию ищет по пользователям (для админов) и фото.
 * Чтобы добавить свою сущность в поиск — добавьте блок ниже по образцу.
 */
class AdminSearchController extends Controller
{
    /**
     * Возвращает результаты поиска (JSON) по пользователям и медиа с учётом прав.
     * Запросы короче 2 символов игнорируются; на каждую сущность — до 5 совпадений.
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
