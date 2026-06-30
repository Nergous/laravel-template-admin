<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\BotMessageRequest;
use App\Models\ActivityLog;
use App\Models\BotMessage;
use App\Support\BotMessageCatalog;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Editing bot texts. Codes come from the messages.json registry; only overrides
 * are stored in the DB (bot_messages).
 */
class AdminBotMessageController extends Controller
{
    /** List of codes from the registry with their current overrides. */
    public function index(): \Inertia\Response
    {
        $overrides = BotMessage::all()->keyBy('code');

        $messages = array_map(function (array $def) use ($overrides) {
            $override = $overrides->get($def['code']);

            return [
                'code' => $def['code'],
                'label' => $def['label'],
                'description' => $def['description'],
                'default' => $def['default'],
                'text' => $override?->text ?? $def['default'],
                'is_overridden' => $override !== null,
                'is_active' => $override?->is_active ?? true,
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

        $message = BotMessage::updateOrCreate(
            ['code' => $code],
            [
                'text' => $request->input('text'),
                'is_active' => $request->boolean('is_active', true),
                'updated_by' => auth()->id(),
            ],
        );

        ActivityLog::record($message, 'updated');

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
}
