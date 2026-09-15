<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BotMessage;
use App\Models\Media;
use App\Models\User;
use App\Support\BotMessageCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Global admin panel search (Cmd+K).
 *
 * Searches users, media and optional bot messages, filtered by view permissions.
 * To add your own entity to the search, add a block below following the pattern.
 */
class AdminSearchController extends Controller
{
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

        if (config('bot.enabled') && $user?->can('bot-messages.view')) {
            // The registry owns the catalog; the database only contains overrides.
            $overrides = BotMessage::query()->get(['code', 'text'])->keyBy('code');

            $results = $results->concat(
                collect(BotMessageCatalog::all())
                    ->filter(function (array $def) use ($overrides, $q) {
                        $text = $overrides->get($def['code'])?->text ?? $def['default'];

                        return mb_stripos($def['label'], $q) !== false
                            || mb_stripos($def['code'], $q) !== false
                            || mb_stripos($text, $q) !== false;
                    })
                    ->take($limit)
                    ->map(fn (array $def) => [
                        'type' => 'bot-message',
                        'label' => $def['label'],
                        'meta' => 'Сообщение бота',
                        'url' => route('admin.bot-messages.index'),
                        'icon' => 'mail',
                    ])
                    ->values()
            );
        }

        return response()->json([
            'results' => $results->values(),
        ]);
    }
}
