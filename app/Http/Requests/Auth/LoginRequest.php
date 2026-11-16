<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The login form. Checks only the input format; the credentials themselves are checked by
 * App\Services\Auth\AdminLogin.
 */
class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Email обязателен для заполнения',
            'email.email' => 'Введите корректный email',
            'password.required' => 'Пароль обязателен для заполнения',
        ];
    }

    /** @return array{email: string, password: string} */
    public function credentials(): array
    {
        return $this->safe()->only(['email', 'password']);
    }

    /** "Remember me": the user stays signed in after closing the browser. */
    public function remember(): bool
    {
        return $this->boolean('remember');
    }
}
