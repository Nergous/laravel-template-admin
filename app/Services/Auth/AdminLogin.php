<?php

namespace App\Services\Auth;

use App\Events\ActivityOccurred;
use App\Models\User;
use Illuminate\Container\Attributes\Auth;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * Admin sign-in and sign-out with activity log entries: successful and failed
 * sign-ins (IP, browser and rejection reason) and sign-outs.
 */
class AdminLogin
{
    public function __construct(#[Auth('web')] private readonly StatefulGuard $guard) {}

    /**
     * Checks the credentials and signs in. The credential check itself rejects
     * blocked accounts, so a correct password does not reveal whether the
     * account exists.
     *
     * @param  array{email: string, password: string}  $credentials
     * @param  string|null  $ip  Client IP (honouring trusted proxies)
     */
    public function attempt(array $credentials, bool $remember, ?string $ip, ?string $agent): bool
    {
        $client = $this->clientDetails($ip, $agent);

        if (! $this->guard->attempt([...$credentials, 'is_active' => true], $remember)) {
            $this->logFailure($credentials['email'], $client);

            return false;
        }

        /** @var User $user */
        $user = $this->guard->user();
        $user->updateSilently(['last_login_at' => now()]);
        ActivityOccurred::by($user, $user, 'login', $client);

        return true;
    }

    /** Signs out, logs it and ends the session. */
    public function logout(User $user, Session $session): void
    {
        ActivityOccurred::by($user, $user, 'logout');

        $this->endSession($session);
    }

    /**
     * Signs out without a log entry, resets the session and issues a new CSRF
     * token so neither can be reused. Called separately when a blocked account
     * is thrown out: the block is already logged.
     */
    public function endSession(Session $session): void
    {
        $this->guard->logout();
        $session->invalidate();
        $session->regenerateToken();
    }

    /**
     * Logs a failed attempt (an unknown email becomes the entry label).
     *
     * @param  array<string, array{0: null, 1: string|null}>  $client
     */
    private function logFailure(string $email, array $client): void
    {
        $user = User::where('email', $email)->first();

        $reason = match (true) {
            $user === null => __('auth.unknown_email'),
            ! $user->is_active => __('auth.blocked'),
            default => __('auth.wrong_password'),
        };

        // The sign-in failed: there is no author, the entry shows a guest with the client IP.
        ActivityOccurred::by(null, $user, 'login_failed', [
            ...$client,
            'reason' => [null, $reason],
        ], $user ? null : $email);
    }

    /**
     * Where the request came from, in the log format (field → [before, after]).
     *
     * @return array<string, array{0: null, 1: string|null}>
     */
    private function clientDetails(?string $ip, ?string $agent): array
    {
        return [
            'ip' => [null, $ip],
            'agent' => [null, $agent ? Str::limit($agent, 255) : null],
        ];
    }
}
