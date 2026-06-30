<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Authentication controller.
 *
 * Handles user login and logout for the admin panel.
 * Uses Laravel's standard Auth mechanism.
 */
class LoginController extends Controller
{
    /**
     * Admin panel login form.
     */
    public function show(): Response
    {
        return Inertia::render('Auth/Login');
    }

    /**
     * Handle a login attempt.
     *
     * On successful authentication, regenerates the session
     * and redirects to the requested URL or to the home page.
     *
     * On invalid credentials, throws a ValidationException
     * with an error on the email field.
     *
     * @throws ValidationException If the email or password is incorrect
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
     * Log out of the admin panel.
     *
     * Invalidates the current session and regenerates the CSRF token
     * to protect against post-logout attacks.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
