<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_redirects_to_admin(): void
    {
        $this->get('/')->assertRedirect('/admin');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
    }

    public function test_login_page_renders(): void
    {
        $this->get(route('login'))->assertOk();
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        // Пароль фабрики по умолчанию — 'password'.
        $user = User::factory()->create();

        $response = $this->post(route('admin.login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $user = User::factory()->create();

        $response = $this->from(route('login'))->post(route('admin.login'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_is_rate_limited_after_five_attempts(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $ignored) {
            $this->post(route('admin.login'), [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);
        }

        $this->post(route('admin.login'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_login_throttle_is_not_bypassed_by_spoofed_forwarded_for(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $i) {
            $this->post(route('admin.login'), [
                'email' => $user->email,
                'password' => 'wrong-password',
            ], ['X-Forwarded-For' => '203.0.113.'.$i]);
        }

        $this->post(route('admin.login'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ], ['X-Forwarded-For' => '203.0.113.250'])->assertStatus(429);
    }

    public function test_session_cookie_defaults_to_secure_in_production(): void
    {
        config(['session.secure' => null]);
        app()->detectEnvironment(fn () => 'production');

        (new \App\Providers\AppServiceProvider(app()))->boot();

        $this->assertTrue(config('session.secure'));
    }

    public function test_explicit_session_secure_choice_is_respected_in_production(): void
    {
        config(['session.secure' => false]);
        app()->detectEnvironment(fn () => 'production');

        (new \App\Providers\AppServiceProvider(app()))->boot();

        $this->assertFalse(config('session.secure'));
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
