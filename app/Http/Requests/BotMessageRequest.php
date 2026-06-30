<?php

namespace App\Http\Requests;

use App\Support\BotMessageSanitizer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates editing the bot message text. The code is taken from the route
 * (checked in the controller against the registry); here — only the form payload.
 */
class BotMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bot-messages.edit') === true;
    }

    /**
     * The text comes from NRichText as HTML. MAX (format=html) understands only
     * inline markup — we keep it and strip the rest. Sanitization is done by a
     * parser (symfony/html-sanitizer): resilient to bypasses
     * (broken tags, stray attributes like style, dangerous schemes).
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'text' => trim(BotMessageSanitizer::sanitize((string) $this->input('text', ''))),
        ]);
    }

    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:4000'],
            'is_active' => ['boolean'],
            // Media attached from the library, in display order. Unknown/foreign ids
            // are rejected by exists; the count is capped by config('bot.max_attachments').
            'media_ids' => ['array', 'max:'.(int) config('bot.max_attachments', 10)],
            'media_ids.*' => ['integer', 'exists:media,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'text.required' => 'Текст сообщения обязателен',
            'text.max' => 'Текст слишком длинный (максимум 4000 символов)',
            'media_ids.max' => 'Слишком много вложений (максимум :max)',
            'media_ids.*.exists' => 'Выбранный файл не найден в медиатеке',
        ];
    }

    /**
     * Validated media ids in display order, normalized to a list of ints.
     *
     * @return list<int>
     */
    public function mediaIds(): array
    {
        return array_values(array_map('intval', $this->input('media_ids', []) ?? []));
    }
}
