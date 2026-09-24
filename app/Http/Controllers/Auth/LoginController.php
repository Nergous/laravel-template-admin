<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\ActivityLog;
use App\Models\User;
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
     * On successful authentication, regenerates the session, stores the login
     * time, writes a "login" entry to the activity log, and redirects to the
     * requested URL (or to the profile when a password change is required).
     *
     * On invalid credentials or a blocked account, throws a ValidationException
     * with an error on the email field; failed attempts are logged as well.
     *
     * @throws ValidationException If the email or password is incorrect
     */
    public function login(LoginRequest $request): RedirectResponse
    {
        $credentials = $request->validated();

        // Blocked accounts are rejected by the credentials check itself, so a
        // correct password does not reveal whether the account exists.
        $attempt = [...$credentials, 'is_active' => true];

        if (! Auth::attempt($attempt, $request->boolean('remember'))) {
            $this->logFailedAttempt($credentials['email']);

            throw ValidationException::withMessages([
                'email' => 'Неверный email или пароль, либо учётная запись заблокирована',
            ]);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::user();
        $user->updateSilently(['last_login_at' => now()]);
        ActivityLog::record($user, 'login');

        if ($user->must_change_password) {
            return redirect()
                ->route('admin.profile.show')
                ->with('warning', 'Администратор попросил сменить пароль перед началом работы');
        }

        return redirect()->intended(route('admin.dashboard'));
    }

    /** Writes a failed login attempt to the activity log (unknown emails are logged by label). */
    private function logFailedAttempt(string $email): void
    {
        $user = User::where('email', $email)->first();

        ActivityLog::record($user, 'login_failed', null, $user ? null : $email);
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
