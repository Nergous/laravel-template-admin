<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

/** Signing out other sessions: confirmed with the current password. */
class LogoutOtherSessionsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'current_password:web'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.current_password' => 'Неверный пароль',
        ];
    }
}
