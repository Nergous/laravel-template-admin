<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_redirects_to_admin(): void
    {
        $this->get('/')->assertRedirect('/admin');
    }

    public function test_unknown_public_page_redirects_to_admin_but_admin_and_json_get_404(): void
    {
        $this->get('/no-such-page')->assertRedirect('/admin');
        $this->getJson('/no-such-page')->assertNotFound();

        $this->actingAs(User::factory()->create());
        $this->get('/admin/no-such-page')->assertNotFound();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
    }

    public function test_login_page_renders(): void
    {
        $this->get(route('login'))->assertOk();
    }

    public function test_signed_in_user_on_login_page_goes_to_admin_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('login'))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        // The factory's default password is 'password'.
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
        $response->assertSessionHasErrors(['email' => 'Неверный email или пароль, либо учётная запись заблокирована']);
        $this->assertGuest();
    }

    public function test_remember_me_sets_the_recaller_cookie(): void
    {
        $user = User::factory()->create();

        $this->post(route('admin.login'), [
            'email' => $user->email,
            'password' => 'password',
            'remember' => '1',
        ])->assertCookie(Auth::guard('web')->getRecallerName());
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
        // A client connecting directly (not through the trusted local proxy).
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7']);

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

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
