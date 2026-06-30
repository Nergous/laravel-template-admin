<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Контроллер аутентификации.
 *
 * Обрабатывает вход и выход пользователей из административной панели.
 * Использует стандартный механизм Auth Laravel.
 */
class LoginController extends Controller
{
    /**
     * Форма входа в административную панель.
     */
    public function show(): \Inertia\Response
    {
        return Inertia::render('Auth/Login');
    }

    /**
     * Обработка попытки входа.
     *
     * При успешной аутентификации регенерирует сессию
     * и перенаправляет на запрошенный URL или на главную.
     *
     * При неверных данных выбрасывает ValidationException
     * с ошибкой на поле email.
     *
     * @throws ValidationException Если email или пароль неверны
     */
    public function login(LoginRequest $request): RedirectResponse
    {
        $credentials = $request->validated();

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'Неверный email или пароль',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'));
    }

    /**
     * Выход из административной панели.
     *
     * Инвалидирует текущую сессию и регенерирует CSRF-токен
     * для защиты от атак после выхода.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
