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
        ];
    }

    public function messages(): array
    {
        return [
            'text.required' => 'Текст сообщения обязателен',
            'text.max' => 'Текст слишком длинный (максимум 4000 символов)',
        ];
    }
}
