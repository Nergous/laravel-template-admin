<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/** Changing one's own password: the current one plus a new one under the shared password policy. */
class UpdatePasswordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::defaults()],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.current_password' => 'Неверный текущий пароль',
            'password.different' => 'Новый пароль должен отличаться от текущего',
            'password.confirmed' => 'Пароли не совпадают',
        ];
    }
}
