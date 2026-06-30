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
