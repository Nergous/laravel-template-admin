<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Block reasons, granular permissions (export, impersonation) and the per-IP
 * login limit.
 */
class AccountAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_block_reason_is_saved_shown_and_cleared(): void
    {
        $this->actingAsAdmin();
        $target = User::factory()->create();

        $this->patch('/admin/users/bulk-status', ['ids' => [$target->id], 'active' => false, 'reason' => '  Увольнение '])
            ->assertRedirect();
        $this->assertSame('Увольнение', $target->fresh()->blocked_reason);

        $this->patch('/admin/users/bulk-status', ['ids' => [$target->id], 'active' => true])->assertRedirect();
        $this->assertNull($target->fresh()->blocked_reason);
        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_a_blocked_session_is_signed_out_with_the_reason(): void
    {
        $user = $this->actingAsUserWith([]);
        $user->forceFill(['is_active' => false, 'blocked_reason' => 'Проверка безопасности'])->saveQuietly();

        $this->get('/admin')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Учётная запись заблокирована: Проверка безопасности']);
        $this->assertGuest();
    }

    public function test_export_needs_its_own_permission(): void
    {
        $this->actingAsUserWith(['users.view']);
        $this->get(route('admin.users.export'))->assertForbidden();

        $this->actingAsUserWith(['users.view', 'users.export']);
        $this->get(route('admin.users.export'))->assertOk()->streamedContent();
        $this->assertTrue(ActivityLog::where('action', 'users_exported')->exists());
    }

    public function test_impersonation_needs_its_own_permission(): void
    {
        $target = User::factory()->create();

        $this->actingAsUserWith(['users.view', 'users.edit']);
        $this->post(route('admin.users.impersonate', $target))->assertForbidden();
    }

    public function test_one_ip_cannot_cycle_through_many_emails(): void
    {
        Setting::set('security', 'login_throttle', 1);

        foreach (range(1, 4) as $i) {
            $this->post(route('admin.login'), ['email' => "user{$i}@example.test", 'password' => 'wrong-password'])
                ->assertRedirect();
        }

        $this->post(route('admin.login'), ['email' => 'user5@example.test', 'password' => 'wrong-password'])
            ->assertStatus(429);
    }
}
