<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SoftDeleteAndAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_can_be_reused_after_soft_delete(): void
    {
        $this->actingAsAdmin();
        $existing = User::factory()->create(['email' => 'reuse@example.com']);
        $existing->delete(); // to trash

        $this->post(route('admin.users.store'), [
            'name' => 'Reuser',
            'email' => 'reuse@example.com',
            'password' => 'Password1',
            'roles' => [],
        ])->assertRedirect(route('admin.users.index'));

        $this->assertSame(1, User::where('email', 'reuse@example.com')->count());
        $this->assertSame(2, User::withTrashed()->where('email', 'reuse@example.com')->count());
    }

    public function test_user_seeder_restores_trashed_admin_without_crashing(): void
    {
        $this->seed(UserSeeder::class);
        User::where('email', 'admin@example.com')->firstOrFail()->delete();
        $this->seed(UserSeeder::class);

        $this->assertSame(1, User::where('email', 'admin@example.com')->count());
        $this->assertNull(User::where('email', 'admin@example.com')->first()->deleted_at);
    }

    public function test_actor_label_snapshot_survives_actor_force_delete(): void
    {
        $admin = $this->actingAsAdmin();

        $subject = User::factory()->create();
        $log = ActivityLog::where('subject_type', User::class)
            ->where('subject_id', $subject->id)
            ->where('action', 'created')
            ->firstOrFail();
        $this->assertSame($admin->name, $log->actor_label);

        $admin->forceDelete();
        $log->refresh();

        $this->assertNull($log->user_id);
        $this->assertSame($admin->name, $log->actor_label);
    }
}
