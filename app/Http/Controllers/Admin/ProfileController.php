<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in user's own account: name/email, password, and active sessions.
 *
 * Available to every authenticated user without extra permissions. A user with
 * users.must_change_password is kept here by RequirePasswordChange until they
 * set a new password.
 *
 * The session list and per-session sign-out need SESSION_DRIVER=database; with
 * other drivers only "sign out on other devices" is available.
 */
class ProfileController extends Controller
{
    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Profile/Index', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'must_change_password' => $user->must_change_password,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ],
            'sessions' => $this->sessions($request),
            'sessionsSupported' => $this->usesDatabaseSessions(),
        ]);
    }

    /**
     * Updates the name and email. Changing the email needs the current
     * password: whoever controls the email controls the account, so a stolen
     * session alone must not be enough to take it over.
     */
    public function update(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users')->ignore($user->id)->whereNull('deleted_at'),
            ],
            'current_password' => [
                Rule::requiredIf(fn () => $request->input('email') !== $user->email),
                'nullable', 'string', 'current_password:web',
            ],
        ], [
            'email.unique' => 'Пользователь с таким email уже существует',
            'current_password.required' => 'Введите текущий пароль, чтобы сменить email',
            'current_password.current_password' => 'Неверный текущий пароль',
        ]);

        $user->update(['name' => $data['name'], 'email' => $data['email']]);

        return back()->with('success', 'Профиль обновлён');
    }

    /**
     * Changes the own password. AuthenticateSession ends the other sessions on
     * their next request; the current one keeps working.
     */
    public function password(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::defaults()],
        ], [
            'current_password.current_password' => 'Неверный текущий пароль',
            'password.different' => 'Новый пароль должен отличаться от текущего',
            'password.confirmed' => 'Пароли не совпадают',
        ]);

        $user->update([
            'password' => Hash::make($request->input('password')),
            'must_change_password' => false,
        ]);

        return redirect()
            ->route('admin.profile.show')
            ->with('success', 'Пароль изменён');
    }

    /** Signs out every other session of the user (password confirmation required). */
    public function logoutOthers(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'current_password:web'],
        ], [
            'password.current_password' => 'Неверный пароль',
        ]);

        /** @var User $user */
        $user = $request->user();

        if ($this->usesDatabaseSessions()) {
            $ended = $this->sessionQuery($user)
                ->where('id', '!=', $request->session()->getId())
                ->delete();

            // "Remember me" cookies on other devices would sign them back in.
            $user->updateSilently(['remember_token' => Str::random(60)]);
        } else {
            $ended = null;
            Auth::logoutOtherDevices($request->input('password'));
        }

        ActivityLog::record($user, 'sessions_ended', $ended !== null ? ['sessions' => [null, $ended]] : null);

        return back()->with('success', 'Сеансы на других устройствах завершены');
    }

    /** Ends one session by its public key (a hash of the session id). */
    public function destroySession(Request $request, string $key): RedirectResponse
    {
        abort_unless($this->usesDatabaseSessions(), 404);

        /** @var User $user */
        $user = $request->user();

        $id = $this->sessionQuery($user)
            ->pluck('id')
            ->first(fn (string $id) => hash_equals($this->publicKey($id), $key));

        abort_if($id === null, 404);

        if ($id === $request->session()->getId()) {
            return back()->withErrors(['session' => 'Текущий сеанс завершается кнопкой «Выйти»']);
        }

        $session = $this->sessionQuery($user)->where('id', $id)->first(['ip_address', 'user_agent']);
        $this->sessionQuery($user)->where('id', $id)->delete();

        ActivityLog::record($user, 'session_ended', [
            'ip' => [$session?->ip_address, null],
            'agent' => [$session?->user_agent ? Str::limit($session->user_agent, 120) : null, null],
        ]);

        return back()->with('success', 'Сеанс завершён');
    }

    /**
     * @return list<array{key: string, ip: string|null, agent: string|null, last_active_at: string, current: bool}>
     */
    private function sessions(Request $request): array
    {
        if (! $this->usesDatabaseSessions()) {
            return [];
        }

        $currentId = $request->session()->getId();

        return $this->sessionQuery($request->user())
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $session) => [
                'key' => $this->publicKey($session->id),
                'ip' => $session->ip_address,
                'agent' => $session->user_agent ? Str::limit($session->user_agent, 160) : null,
                'last_active_at' => Carbon::createFromTimestamp($session->last_activity)->toIso8601String(),
                'current' => $session->id === $currentId,
            ])
            ->values()
            ->all();
    }

    /** Session ids are credentials, so the browser only sees their hash. */
    private function publicKey(string $sessionId): string
    {
        return hash_hmac('sha256', $sessionId, (string) config('app.key'));
    }

    private function sessionQuery(User $user): Builder
    {
        return DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey());
    }

    private function usesDatabaseSessions(): bool
    {
        return config('session.driver') === 'database';
    }
}
