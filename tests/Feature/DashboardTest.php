<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
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
                ->missing('roleDistribution')
                ->missing('content')
                ->has('recentActivity')
                ->missing('system')
            );
    }

    public function test_stats_are_gated_by_section_permissions(): void
    {
        $this->actingAsUserWith([]);

        $this->get('/admin')->assertInertia(fn (Assert $page) => $page
            ->where('stats', [])
        );

        $this->actingAsUserWith(['media.view']);

        $this->get('/admin')->assertInertia(fn (Assert $page) => $page
            ->has('stats.media')
            ->missing('stats.users')
            ->missing('stats.logins')
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
            )
        );

        $this->assertTrue(Cache::has('admin.sidebar-counts.v3'));
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

    public function test_attention_is_empty_without_permissions(): void
    {
        $this->actingAsUserWith([]);
        $this->failedJob();

        $this->get('/admin')->assertInertia(fn (Assert $page) => $page
            ->where('attention', [])
        );
    }

    public function test_attention_lists_failed_jobs_and_missing_backup(): void
    {
        config(['backup.path' => $this->emptyBackupDir()]);
        $this->actingAsUserWith(['queue.view', 'backups.view']);
        $this->failedJob();

        $this->get('/admin')->assertInertia(fn (Assert $page) => $page
            ->has('attention', 2)
            ->where('attention.0.key', 'failed_jobs')
            ->where('attention.0.value', '1')
            ->where('attention.0.url', route('admin.queue.index', ['status' => 'failed']))
            ->where('attention.1.key', 'backup')
            ->where('attention.1.value', 'нет ни одной')
        );
    }

    public function test_attention_skips_a_fresh_backup(): void
    {
        $dir = $this->emptyBackupDir();
        File::put($dir.'/db-'.now()->format('Ymd-His').'.sql', '-- dump');
        config(['backup.path' => $dir]);
        $this->actingAsUserWith(['backups.view']);

        $this->get('/admin')->assertInertia(fn (Assert $page) => $page
            ->where('attention', [])
        );
    }

    public function test_media_card_reports_images_without_alt(): void
    {
        $this->actingAsUserWith(['media.view']);
        Media::create(['filename' => 'media/a.webp', 'type' => 'image', 'alt' => '']);
        Media::create(['filename' => 'media/b.webp', 'type' => 'image', 'alt' => 'Описание']);

        $this->get('/admin')->assertInertia(fn (Assert $page) => $page
            ->where('stats.media.no_alt', 1)
        );
    }

    private function failedJob(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\UploadMedia']),
            'exception' => 'RuntimeException: boom',
            'failed_at' => now(),
        ]);
    }

    private function emptyBackupDir(): string
    {
        $dir = storage_path('framework/testing/dashboard-backups-'.uniqid());
        File::ensureDirectoryExists($dir);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($dir));

        return $dir;
    }
}
