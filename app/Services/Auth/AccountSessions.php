<?php

namespace App\Services\Auth;

use App\Events\ActivityOccurred;
use App\Models\User;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Active sessions of one's own account: listing and ending them.
 *
 * Listing and ending a single session need SESSION_DRIVER=database; with other
 * drivers only "sign out other devices" works — via a new password hash.
 * Ending sessions also rotates the remember token, so "remember me" cookies on
 * other devices stop working; RememberedDevice issues a new cookie to the
 * current device.
 */
class AccountSessions
{
    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly Hasher $hasher,
        #[Config('session.driver')] private readonly ?string $driver,
        #[Config('session.connection')] private readonly ?string $connection,
        #[Config('session.table', 'sessions')] private readonly string $table,
        #[Config('app.key')] private readonly ?string $appKey,
    ) {}

    public function supported(): bool
    {
        return $this->driver === 'database';
    }

    /**
     * The user's sessions, most recently active first. key is the session's
     * public key for the browser (the session id itself is a credential). Empty
     * when sessions are not stored in the database.
     *
     * @return Collection<int, object{id: string, key: string, ip_address: string|null, user_agent: string|null, last_activity: int}>
     */
    public function list(User $user): Collection
    {
        if (! $this->supported()) {
            return collect();
        }

        return $this->query($user)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->each(fn (object $session) => $session->key = $this->publicKey($session->id));
    }

    /**
     * Ends all the user's sessions except $currentId.
     *
     * @param  string  $password  The confirmed current password (re-hashed when there is no sessions table)
     */
    public function endOthers(User $user, string $currentId, string $password): void
    {
        if ($this->supported()) {
            $ended = $this->query($user)->where('id', '!=', $currentId)->delete();
        } else {
            $ended = null;

            // Without a sessions table AuthenticateSession ends the other sessions:
            // a new hash of the same password no longer matches the one stored in them.
            // Saved silently — this is not a password change and must not look
            // like one in the activity log.
            $user->updateSilently(['password' => $this->hasher->make($password)]);
        }

        $this->cycleRememberToken($user);

        ActivityOccurred::by($user, $user, 'sessions_ended', $ended !== null ? ['sessions' => [null, $ended]] : null);
    }

    /**
     * Ends one session by its public key from the list. Returns false when the
     * key matches none of the user's sessions (or sessions are not stored in
     * the database).
     *
     * @throws ValidationException For the current session: it is ended with the "Sign out" button.
     */
    public function endByKey(User $user, string $key, string $currentId): bool
    {
        $id = $this->idByKey($user, $key);

        if ($id === null) {
            return false;
        }

        if ($id === $currentId) {
            throw ValidationException::withMessages([
                'session' => 'Текущий сеанс завершается кнопкой «Выйти»',
            ]);
        }

        $this->end($user, $id);

        return true;
    }

    /** Session id behind a public key from the list, or null. */
    private function idByKey(User $user, string $key): ?string
    {
        if (! $this->supported()) {
            return null;
        }

        return $this->query($user)
            ->pluck('id')
            ->first(fn (string $id) => hash_equals($this->publicKey($id), $key));
    }

    private function end(User $user, string $id): void
    {
        $session = $this->query($user)->where('id', $id)->first(['ip_address', 'user_agent']);
        $this->query($user)->where('id', $id)->delete();

        // The ended device's "remember me" cookie would sign it straight back in.
        $this->cycleRememberToken($user);

        ActivityOccurred::by($user, $user, 'session_ended', [
            'ip' => [$session?->ip_address, null],
            'agent' => [$session?->user_agent ? Str::limit($session->user_agent, 120) : null, null],
        ]);
    }

    private function cycleRememberToken(User $user): void
    {
        $user->updateSilently(['remember_token' => Str::random(60)]);
    }

    /** Session ids are credentials, so the browser sees only their hash. */
    private function publicKey(string $sessionId): string
    {
        return hash_hmac('sha256', $sessionId, (string) $this->appKey);
    }

    private function query(User $user): Builder
    {
        return $this->db->connection($this->connection)
            ->table($this->table)
            ->where('user_id', $user->getKey());
    }
}
