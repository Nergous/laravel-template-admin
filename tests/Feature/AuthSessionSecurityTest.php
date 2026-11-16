<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Auth\AccountSessions;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Ending sessions from the profile, and the attempt limit on password checks. */
class AuthSessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_ending_other_sessions_keeps_the_current_remembered_device_signed_in(): void
    {
        $user = User::factory()->create(['remember_token' => 'old-token']);
        $oldHash = $user->password;
        $this->actingAs($user);
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');
        $recaller = $guard->getRecallerName();

        $response = $this
            ->withCookie($recaller, $user->id.'|old-token|'.$guard->hashPasswordForCookie($oldHash))
            ->post(route('admin.profile.sessions.logout-others'), ['password' => 'password'])
            ->assertSessionHasNoErrors();

        $fresh = $user->fresh();
        // Other devices' "remember me" cookies (old token, old password hash) stop working…
        $this->assertNotSame('old-token', $fresh->remember_token);
        $this->assertNotSame($oldHash, $fresh->password);
        $this->assertTrue(Hash::check('password', $fresh->password));
        // …while this device gets a fresh cookie that matches both.
        $this->assertSame(
            $user->id.'|'.$fresh->remember_token.'|'.$guard->hashPasswordForCookie($fresh->password),
            $response->getCookie($recaller)?->getValue(),
        );

        // The rehash is not a password change.
        $this->assertTrue(ActivityLog::where('action', 'sessions_ended')->where('user_id', $user->id)->exists());
        $this->assertFalse(ActivityLog::where('subject_type', User::class)->where('subject_id', $user->id)->where('action', 'updated')->exists());
    }

    public function test_ending_one_session_also_revokes_remember_me(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['remember_token' => 'old-token']);
        DB::table('sessions')->insert([
            'id' => 'other-device-session',
            'user_id' => $user->id,
            'ip_address' => '10.0.0.5',
            'user_agent' => 'Phone',
            'payload' => '',
            'last_activity' => now()->getTimestamp(),
        ]);

        $this->actingAs($user)
            ->delete(route('admin.profile.sessions.destroy', hash_hmac('sha256', 'other-device-session', (string) config('app.key'))))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('sessions', ['id' => 'other-device-session']);
        $this->assertNotSame('old-token', $user->fresh()->remember_token);
    }

    public function test_changing_the_password_keeps_the_current_remembered_device_signed_in(): void
    {
        $user = User::factory()->create(['remember_token' => 'token']);
        $this->actingAs($user);
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');
        $recaller = $guard->getRecallerName();

        $response = $this
            ->withCookie($recaller, $user->id.'|token|'.$guard->hashPasswordForCookie($user->password))
            ->put(route('admin.profile.password'), [
                'current_password' => 'password',
                'password' => 'Another-Secret-Passw0rd!',
                'password_confirmation' => 'Another-Secret-Passw0rd!',
            ])
            ->assertSessionHasNoErrors();

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('Another-Secret-Passw0rd!', $fresh->password));
        // The old cookie carries the old password hash and would no longer sign
        // this device back in; it gets one with the new hash.
        $this->assertSame(
            $user->id.'|token|'.$guard->hashPasswordForCookie($fresh->password),
            $response->getCookie($recaller)?->getValue(),
        );
    }

    public function test_the_current_session_cannot_be_ended_from_the_list(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create();
        DB::table('sessions')->insert([
            'id' => 'current-session',
            'user_id' => $user->id,
            'payload' => '',
            'last_activity' => now()->getTimestamp(),
        ]);
        $sessions = app(AccountSessions::class);

        try {
            $sessions->endByKey($user, hash_hmac('sha256', 'current-session', (string) config('app.key')), 'current-session');
            $this->fail('The current session was ended.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('session', $e->errors());
        }
        $this->assertDatabaseHas('sessions', ['id' => 'current-session']);

        $this->assertFalse($sessions->endByKey($user, 'unknown', 'current-session'));
        $this->actingAs($user)->delete(route('admin.profile.sessions.destroy', 'unknown'))->assertNotFound();
    }

    public function test_profile_lists_sessions_without_their_ids(): void
    {
        config(['session.driver' => 'database', 'session.lottery' => [100, 100]]);
        // StartSession, with session.lottery odds, deletes sessions older than the
        // lifetime from the table. Here the sweep runs on every request and the
        // clock sits right after last_activity: the session is alive and stays listed.
        $this->travelTo(Carbon::createFromTimestamp(1_700_000_000 + 60));
        $user = User::factory()->create();
        DB::table('sessions')->insert([
            'id' => 'other-device',
            'user_id' => $user->id,
            'ip_address' => '10.0.0.1',
            'user_agent' => str_repeat('a', 300),
            'payload' => '',
            'last_activity' => 1_700_000_000,
        ]);

        $this->actingAs($user)->get(route('admin.profile.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('sessions', 1)
                ->where('sessions.0.key', hash_hmac('sha256', 'other-device', (string) config('app.key')))
                ->where('sessions.0.ip', '10.0.0.1')
                ->where('sessions.0.agent', str_repeat('a', 160).'...')
                ->where('sessions.0.last_active_at', '2023-11-14T22:13:20+00:00')
                ->where('sessions.0.current', false)
                ->missing('sessions.0.id')
            );
    }

    public function test_password_checking_profile_actions_are_rate_limited(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (range(1, 6) as $ignored) {
            $this->post(route('admin.profile.sessions.logout-others'), ['password' => 'wrong'])
                ->assertSessionHasErrors('password');
        }

        $this->put(route('admin.profile.password'), [
            'current_password' => 'wrong',
            'password' => 'Another-Secret-Passw0rd!',
            'password_confirmation' => 'Another-Secret-Passw0rd!',
        ])->assertStatus(429);
    }
}
