<?php

namespace App\Http\Requests;

use App\Models\Setting;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request for saving settings.
 *
 * Validation rules are built dynamically from Setting::SCHEMA: the type of each
 * key (bool/int/text/…) determines its own set of rules.
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
                    'int' => ['required', 'integer', 'min:1', 'max:100000'],
                    'text' => ['nullable', 'string', 'max:1000'],
                    default => ['nullable', 'string', 'max:255'],
                };
            }
        }

        // A session shorter than a minute would sign everyone out immediately;
        // the upper bound is 30 days.
        $rules['settings.security.session_lifetime'] = ['required', 'integer', 'min:1', 'max:43200'];
        $rules['settings.security.login_throttle'] = ['required', 'integer', 'min:1', 'max:1000'];

        foreach (['settings.general.favicon', 'settings.seo.og_image'] as $assetKey) {
            $rules[$assetKey] = ['nullable', 'string', 'max:255', $this->safeAssetUrl(...)];
        }

        $rules['settings.seo.canonical_domain'] = ['nullable', 'string', 'max:255', 'regex:~^https?://[^/\s?#]+$~i'];
        $rules['settings.general.timezone'] = ['required', 'string', 'timezone:all'];

        return $rules;
    }

    public function messages(): array
    {
        return [
            'settings.security.session_lifetime.min' => 'Минимум 1 минута',
            'settings.security.session_lifetime.max' => 'Максимум 43200 минут (30 дней)',
            'settings.security.login_throttle.min' => 'Минимум 1 попытка',
            'settings.seo.canonical_domain.regex' => 'Укажите домен вида https://example.com без пути и слэша в конце',
        ];
    }

    /**
     * Rule: empty, or a root-relative path (/storage/...), or an
     * absolute http(s) URL. Everything else (javascript:, data:, //host, other
     * schemes) is rejected.
     */
    protected function safeAssetUrl(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        // Root-relative path, but not protocol-relative (//host).
        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return;
        }

        // Absolute http(s) URL.
        if (preg_match('#^https?://#i', $value) && filter_var($value, FILTER_VALIDATE_URL) !== false) {
            return;
        }

        $fail('Значение должно быть относительным путём (/…) или http(s)-ссылкой.');
    }
}
