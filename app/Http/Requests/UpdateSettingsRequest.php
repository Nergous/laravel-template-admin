<?php

namespace App\Http\Requests;

use App\Models\Setting;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request для сохранения настроек.
 *
 * Правила валидации строятся динамически из Setting::SCHEMA: тип каждого
 * ключа (bool/int/text/…) определяет свой набор правил.
 */
class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.edit') === true;
    }

    public function rules(): array
    {
        $rules = ['settings' => ['required', 'array']];

        foreach (Setting::SCHEMA as $group => $keys) {
            foreach ($keys as $key => [$type]) {
                $rules["settings.{$group}.{$key}"] = match ($type) {
                    'bool' => ['required', 'boolean'],
                    'int' => ['required', 'integer', 'min:0', 'max:100000'],
                    'text' => ['nullable', 'string', 'max:1000'],
                    default => ['nullable', 'string', 'max:255'],
                };
            }
        }

        foreach (['settings.general.favicon', 'settings.seo.og_image'] as $assetKey) {
            $rules[$assetKey] = ['nullable', 'string', 'max:255', $this->safeAssetUrl(...)];
        }

        $rules['settings.general.timezone'] = ['required', 'string', 'timezone:all'];

        return $rules;
    }

    /**
     * Правило: пусто, либо относительный путь от корня (/storage/...), либо
     * абсолютный http(s)-URL. Всё остальное (javascript:, data:, //host, прочие
     * схемы) отклоняется.
     */
    protected function safeAssetUrl(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        // Относительный путь от корня, но не protocol-relative (//host).
        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return;
        }

        // Абсолютный http(s)-URL.
        if (preg_match('#^https?://#i', $value) && filter_var($value, FILTER_VALIDATE_URL) !== false) {
            return;
        }

        $fail('Значение должно быть относительным путём (/…) или http(s)-ссылкой.');
    }
}
