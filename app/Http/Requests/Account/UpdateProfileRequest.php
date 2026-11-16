<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Name and email of the signed-in user. Changing the email requires the current
 * password: whoever owns the email owns the account, so a stolen session alone
 * is not enough to take it over.
 */
class UpdateProfileRequest extends FormRequest
{
    public function rules(): array
    {
        $user = $this->user();

        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users')->ignore($user->id)->whereNull('deleted_at'),
            ],
            'current_password' => [
                Rule::requiredIf(fn () => $this->input('email') !== $user->email),
                'nullable', 'string', 'current_password:web',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Пользователь с таким email уже существует',
            'current_password.required' => 'Введите текущий пароль, чтобы сменить email',
            'current_password.current_password' => 'Неверный текущий пароль',
        ];
    }
}
