<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\BotMessageRequest;
use App\Models\ActivityLog;
use App\Models\BotMessage;
use App\Models\BotMessageMedia;
use App\Models\Media;
use App\Support\BotMessageCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Editing bot texts. Codes come from the messages.json registry; only overrides
 * are stored in the DB (bot_messages).
 */
class AdminBotMessageController extends Controller
{
    /** List of codes from the registry with their current overrides and attachments. */
    public function index(): Response
    {
        $overrides = BotMessage::all()->keyBy('code');

        // Attachments grouped by code (ordered), with the media payload the picker
        // and the previews need (url/thumb/type/name).
        $attachments = BotMessageMedia::with('media')
            ->orderBy('position')
            ->get()
            ->groupBy('code')
            ->map(fn ($rows) => $rows
                ->filter(fn (BotMessageMedia $r) => $r->media !== null)
                ->map(fn (BotMessageMedia $r) => $this->mediaPayload($r->media))
                ->values()
            );

        $messages = array_map(function (array $def) use ($overrides, $attachments) {
            $override = $overrides->get($def['code']);

            return [
                'code' => $def['code'],
                'label' => $def['label'],
                'description' => $def['description'],
                'default' => $def['default'],
                'text' => $override?->text ?? $def['default'],
                'is_overridden' => $override !== null,
                'is_active' => $override?->is_active ?? true,
                'attachments' => $attachments->get($def['code'], collect())->all(),
            ];
        }, BotMessageCatalog::all());

        return Inertia::render('BotMessages/Index', [
            'messages' => $messages,
        ]);
    }

    /**
     * Creates/updates a text override (upsert by code).
     *
     * @param  string  $code  Message code from the messages.json registry
     */
    public function update(BotMessageRequest $request, string $code): RedirectResponse
    {
        abort_unless(BotMessageCatalog::find($code) !== null, 404);

        DB::transaction(function () use ($request, $code) {
            $message = BotMessage::updateOrCreate(
                ['code' => $code],
                [
                    'text' => $request->input('text'),
                    'is_active' => $request->boolean('is_active', true),
                    'updated_by' => auth()->id(),
                ],
            );

            $this->syncAttachments($code, $request->mediaIds());

            ActivityLog::record($message, 'updated');
        });

        return back()->with('success', 'Сообщение обновлено');
    }

    /**
     * Resets the text to the registry default (deletes the override).
     *
     * @param  string  $code  Message code from the messages.json registry
     */
    public function destroy(string $code): RedirectResponse
    {
        abort_unless(BotMessageCatalog::find($code) !== null, 404);

        $message = BotMessage::where('code', $code)->first();
        if ($message) {
            ActivityLog::record($message, 'reset');
            $message->delete();
        }

        return back()->with('success', 'Текст сброшен к значению по умолчанию');
    }

    /**
     * Replaces the attachment set of a message code with the given media ids,
     * preserving their order. Ids the user can't see (missing media) are skipped
     * by the FormRequest's exists rule before we get here.
     *
     * @param  list<int>  $mediaIds  Media ids in display order
     */
    private function syncAttachments(string $code, array $mediaIds): void
    {
        BotMessageMedia::where('code', $code)
            ->whereNotIn('media_id', $mediaIds ?: [0])
            ->delete();

        foreach (array_values($mediaIds) as $position => $mediaId) {
            BotMessageMedia::updateOrCreate(
                ['code' => $code, 'media_id' => $mediaId],
                ['position' => $position],
            );
        }
    }

    /**
     * Shapes a Media row for the frontend (picker + drawer previews).
     *
     * @return array{id:int,url:string,thumb_url:string,type:?string,original_name:?string}
     */
    private function mediaPayload(Media $media): array
    {
        return [
            'id' => $media->id,
            'url' => $media->url(),
            'thumb_url' => $media->thumbUrl(),
            'type' => $media->type,
            'original_name' => $media->original_name,
        ];
    }
}
