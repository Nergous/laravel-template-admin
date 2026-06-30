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
 * Редактирование текстов бота. Коды — из реестра messages.json; в БД хранятся
 * только переопределения (bot_messages).
 */
class AdminBotMessageController extends Controller
{
    /** Список кодов из реестра с текущими переопределениями. */
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
     * Создаёт/обновляет переопределение текста (upsert по code).
     *
     * @param  string  $code  Код сообщения из реестра messages.json
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
     * Сбрасывает текст к дефолту из реестра (удаляет переопределение).
     *
     * @param  string  $code  Код сообщения из реестра messages.json
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
