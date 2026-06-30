<?php

namespace App\Http\Requests;

use App\Support\BotMessageSanitizer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация правки текста сообщения бота. Код берётся из роута (проверяется в
 * контроллере по реестру); здесь — только полезная нагрузка формы.
 */
class BotMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bot-messages.edit') === true;
    }

    /**
     * Текст приходит из NRichText как HTML. MAX (format=html) понимает только
     * инлайн-разметку — оставляем её, остальное вырезаем. Санитизация делается
     * парсером (symfony/html-sanitizer): устойчиво к обходам
     * (разорванные теги, лишние атрибуты вроде style, опасные схемы).
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
