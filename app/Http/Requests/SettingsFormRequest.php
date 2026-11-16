<?php

namespace App\Http\Requests;

use App\Enums\SettingsPage;
use App\Support\Settings\SettingsRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Saves the fields of one settings page (SettingsPage) under the "settings"
 * key: group => key => value.
 *
 * Rules come from SettingsRules::for() for the page fields only, so
 * validated() drops everything else.
 */
abstract class SettingsFormRequest extends FormRequest
{
    /**
     * The page this request saves.
     */
    abstract public function page(): SettingsPage;

    /** Permission required to save the page. */
    abstract protected function permission(): string;

    public function authorize(): bool
    {
        return $this->user()?->can($this->permission()) === true;
    }

    public function rules(): array
    {
        $rules = ['settings' => ['required', 'array']];

        foreach (SettingsRules::for($this->page()) as $field => $fieldRules) {
            $rules["settings.{$field}"] = $fieldRules;
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'settings.security.session_lifetime.min' => 'Минимум 1 минута',
            'settings.security.session_lifetime.max' => 'Максимум 43200 минут (30 дней)',
            'settings.security.login_throttle.min' => 'Минимум 1 попытка',
            'settings.seo.canonical_domain.regex' => 'Укажите домен вида https://example.com без пути и слэша в конце',
            'settings.appearance.accent.enum' => 'Выберите цвет из списка',
        ];
    }
}
