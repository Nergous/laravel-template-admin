<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_inertia_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('stats')
                ->has('roleDistribution')
                ->has('recentActivity')
            );
    }

    public function test_dashboard_hides_recent_activity_without_permission(): void
    {
        $this->actingAsUserWith([]);

        ActivityLog::create([
            'action' => 'created',
            'subject_type' => User::class,
            'subject_id' => 1,
            'subject_label' => 'Аудируемый',
            'created_at' => now(),
        ]);

        $this->get('/admin')->assertInertia(fn (Assert $page) => $page
            ->has('recentActivity', 0)
        );
    }

    public function test_dashboard_shows_recent_activity_with_permission(): void
    {
        $this->actingAsUserWith(['activity-log.view']);

        ActivityLog::query()->delete();
        ActivityLog::create([
            'action' => 'created',
            'subject_type' => User::class,
            'subject_id' => 1,
            'subject_label' => 'Аудируемый',
            'created_at' => now(),
        ]);

        $this->get('/admin')->assertInertia(fn (Assert $page) => $page
            ->has('recentActivity', 1)
        );
    }

    public function test_sidebar_counts_are_shared_and_cached(): void
    {
        $this->actingAsAdmin();
        User::factory()->count(2)->create();
        Media::create(['filename' => 'media/x.webp']);

        $this->get('/admin')->assertInertia(fn (Assert $page) => $page
            ->has('counts', fn (Assert $c) => $c
                ->where('users', User::count())
                ->where('roles', Role::count())
                ->where('permissions', Permission::count())
                ->where('media', Media::count())
                ->has('recentActivity')
            )
        );

        $this->assertTrue(Cache::has('admin.sidebar-counts'));
    }

    public function test_kpis_include_blocked_users_and_logins(): void
    {
        $this->actingAsUserWith(['users.view', 'activity-log.view']);
        User::factory()->create(['is_active' => false]);
        ActivityLog::query()->delete();
        ActivityLog::create(['action' => 'login', 'created_at' => now()->subHour()]);
        ActivityLog::create(['action' => 'login', 'created_at' => now()->subDays(2)]);
        ActivityLog::create(['action' => 'login_failed', 'created_at' => now()->subHour()]);

        $this->get('/admin')->assertInertia(fn (Assert $page) => $page
            ->where('stats.users.sub', '1 заблокировано')
            ->where('stats.logins.value', 1)
            ->where('stats.logins.sub', '1 неудачных за 24 ч')
            ->where('stats.logins.spark', [0, 0, 0, 0, 1, 0, 1])
        );
    }

    public function test_system_health_is_gated_by_permissions(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dash-backups-'.uniqid();
        config(['backup.path' => $dir]);

        try {
            $this->actingAsUserWith([]);
            $this->get('/admin')->assertInertia(fn (Assert $page) => $page
                ->where('system.queue', null)
                ->where('system.backup', null)
            );

            $this->actingAsUserWith(['queue.view', 'backups.view']);
            $this->get('/admin')->assertInertia(fn (Assert $page) => $page
                ->where('system.queue.failed', 0)
                ->where('system.backup.latest', null)
                ->where('system.backup.stale', true)
            );
        } finally {
            @rmdir($dir);
        }
    }
}
