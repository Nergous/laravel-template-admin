<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Auth\AdminLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin panel session: the login form, sign-in and sign-out. Credential checks
 * and activity log entries live in AdminLogin; the controller manages only the
 * HTTP session.
 */
class AuthenticatedSessionController extends Controller
{
    public function __construct(private readonly AdminLogin $login) {}

    /** The login form. */
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    /**
     * Signs the user in, starts a new session and redirects to the page that
     * required sign-in.
     *
     * @throws ValidationException On invalid credentials or a blocked account
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        if (! $this->login->attempt($request->credentials(), $request->remember(), $request->ip(), $request->userAgent())) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'));
    }

    /**
     * Signs out, invalidates the session and issues a new CSRF token so the old
     * token cannot be reused after sign-out.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $this->login->logout($request->user(), $request->session());

        return redirect()->route('login');
    }
}
