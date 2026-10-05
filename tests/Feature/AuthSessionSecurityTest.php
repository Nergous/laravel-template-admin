<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
