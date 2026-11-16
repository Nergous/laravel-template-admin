<?php

namespace Tests\Feature;

use App\Jobs\CreateBackup;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\System\Backup\BackupConfig;
use App\Services\System\Backup\BackupFiles;
use App\Services\System\Backup\BackupManager;
use App\Services\System\Backup\BackupService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Every test writes into a throwaway directory (config backup.path) that is
 * removed in tearDown; storage/app/backups is never touched.
 *
 * DatabaseMigrations instead of RefreshDatabase: SQLite refuses VACUUM INTO
 * inside the transaction RefreshDatabase wraps each test in.
 */
class BackupTest extends TestCase
{
    use DatabaseMigrations;

    private string $dir;

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lta-backup-test-'.Str::random(10);
        $this->dir = $root.DIRECTORY_SEPARATOR.'backups';
        $this->scratch = $root;

        config([
            'backup.path' => $this->dir,
            'backup.disk' => null,
            'backup.keep.scheduled' => 7,
            'backup.keep.manual' => 5,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-09-24 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory($this->scratch);

        parent::tearDown();
    }

    public function test_command_creates_sqlite_dump_with_utc_name(): void
    {
        $user = User::factory()->create(['email' => 'backup-marker@example.test']);

        $this->artisan('app:db-backup')->assertSuccessful();

        $path = $this->dir.DIRECTORY_SEPARATOR.'db-20260924-120000.sqlite';
        $this->assertFileExists($path);
        $contents = (string) file_get_contents($path);
        $this->assertStringStartsWith("SQLite format 3\0", $contents);
        $this->assertStringContainsString($user->email, $contents);

        $latest = app(BackupFiles::class)->latest();
        $this->assertSame('db-20260924-120000.sqlite', $latest['name']);
        $this->assertSame('scheduled', $latest['kind']);
        $this->assertSame(filesize($path), $latest['size']);
        $this->assertSame('2026-09-24T12:00:00+00:00', $latest['created_at']);
    }

    public function test_same_second_dumps_get_a_collision_suffix(): void
    {
        $service = app(BackupService::class);

        $first = $service->create('manual');
        $second = $service->create('manual');

        $this->assertSame('db-20260924-120000-manual.sqlite', $first['name']);
        $this->assertSame('db-20260924-120000-manual-2.sqlite', $second['name']);
        $this->assertSame('manual', BackupFiles::kindOf($second['name']));
    }

    public function test_rotation_is_separate_per_kind(): void
    {
        config(['backup.keep.scheduled' => 2, 'backup.keep.manual' => 1]);
        $service = app(BackupService::class);

        foreach (range(0, 2) as $i) {
            Carbon::setTestNow(Carbon::parse('2026-09-24 12:00:00', 'UTC')->addMinutes($i));
            $service->create();
            $service->create('manual');
        }

        $names = array_column(app(BackupFiles::class)->list(), 'name');

        $this->assertSame([
            'db-20260924-120200.sqlite',
            'db-20260924-120200-manual.sqlite',
            'db-20260924-120100.sqlite',
        ], $names);
    }

    public function test_keep_option_overrides_config(): void
    {
        foreach (range(0, 2) as $i) {
            Carbon::setTestNow(Carbon::parse('2026-09-24 12:00:00', 'UTC')->addMinutes($i));
            $this->artisan('app:db-backup', ['--keep' => 1])->assertSuccessful();
        }

        $this->assertSame(['db-20260924-120200.sqlite'], array_column(app(BackupFiles::class)->list(), 'name'));
    }

    public function test_invalid_tag_is_rejected(): void
    {
        $this->artisan('app:db-backup', ['--tag' => '../x'])->assertFailed();

        $this->assertSame([], app(BackupFiles::class)->list());
    }

    public function test_offsite_copy_is_uploaded_and_rotated(): void
    {
        Storage::fake('offsite');
        config(['backup.disk' => 'offsite', 'backup.disk_path' => 'db', 'backup.keep.scheduled' => 1]);
        Storage::disk('offsite')->put('db/db-20260924-120000-manual.sqlite', 'manual copy');

        $this->artisan('app:db-backup')->assertSuccessful();
        Carbon::setTestNow(Carbon::parse('2026-09-24 13:00:00', 'UTC'));
        $this->artisan('app:db-backup')->assertSuccessful();

        $files = Storage::disk('offsite')->files('db');
        sort($files);
        $this->assertSame([
            'db/db-20260924-120000-manual.sqlite',
            'db/db-20260924-130000.sqlite',
        ], $files);
    }

    public function test_listing_a_missing_directory_has_no_side_effects(): void
    {
        $files = app(BackupFiles::class);

        $this->assertSame([], $files->list());
        $this->assertNull($files->latest());
        $this->assertDirectoryDoesNotExist($this->dir);
    }

    public function test_path_rejects_foreign_names(): void
    {
        app(BackupService::class)->create();
        $files = app(BackupFiles::class);
        File::put($this->scratch.DIRECTORY_SEPARATOR.'db-outside.sqlite', 'x');

        $this->assertNotNull($files->path('db-20260924-120000.sqlite'));
        foreach (['db-..', '../db-outside.sqlite', 'db-../../x', 'other.sqlite', '', 'db-a/b', 'db-a\\b'] as $name) {
            $this->assertNull($files->path($name), $name);
            $this->assertFalse($files->delete($name), $name);
        }
    }

    public function test_index_lists_backups_for_viewers_only(): void
    {
        app(BackupService::class)->create('manual');

        $this->actingAsUserWith([]);
        $this->get('/admin/backups')->assertForbidden();

        $this->actingAsUserWith(['backups.view']);
        $this->get('/admin/backups')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Backups/Index')
                ->where('backups.0.name', 'db-20260924-120000-manual.sqlite')
                ->where('backups.0.kind', 'manual')
                ->where('offsite', null)
                ->where('keep.manual', 5)
            );
    }

    public function test_store_creates_a_manual_backup_and_logs_it(): void
    {
        $this->actingAsUserWith(['backups.view']);
        $this->post('/admin/backups')->assertForbidden();

        $this->actingAsUserWith(['backups.view', 'backups.create']);
        $this->post('/admin/backups')->assertRedirect()->assertSessionHas('success');

        $this->assertFileExists($this->dir.DIRECTORY_SEPARATOR.'db-20260924-120000-manual.sqlite');
        $this->assertSame(
            'db-20260924-120000-manual.sqlite',
            ActivityLog::where('action', 'backup_created')->value('subject_label'),
        );
    }

    public function test_download_serves_existing_backups_and_logs_it(): void
    {
        $name = app(BackupService::class)->create()['name'];
        $this->actingAsUserWith(['backups.view']);
        $this->get('/admin/backups/'.$name)->assertForbidden();

        $this->actingAsUserWith(['backups.view', 'backups.download']);
        $this->get('/admin/backups/'.$name)->assertOk()->assertDownload($name);
        $this->get('/admin/backups/db-20000101-000000.sqlite')->assertNotFound();

        $this->assertSame(1, ActivityLog::where('action', 'backup_downloaded')->count());
    }

    public function test_destroy_requires_permission_and_logs_deletion(): void
    {
        $name = app(BackupService::class)->create()['name'];

        $this->actingAsUserWith(['backups.view']);
        $this->delete('/admin/backups/'.$name)->assertForbidden();
        $this->assertFileExists($this->dir.DIRECTORY_SEPARATOR.$name);

        $this->actingAsUserWith(['backups.view', 'backups.delete']);
        $this->delete('/admin/backups/'.$name)->assertRedirect()->assertSessionHas('success');
        $this->assertFileDoesNotExist($this->dir.DIRECTORY_SEPARATOR.$name);
        $this->assertSame($name, ActivityLog::where('action', 'backup_deleted')->value('subject_label'));

        $this->delete('/admin/backups/'.$name)->assertNotFound();
    }

    public function test_store_queues_the_dump_and_reports_a_sanitized_failure(): void
    {
        // A file where the directory should be: mkdir fails with a raw PHP error.
        File::ensureDirectoryExists($this->scratch);
        File::put($this->scratch.DIRECTORY_SEPARATOR.'blocker', 'x');
        config(['backup.path' => $this->scratch.DIRECTORY_SEPARATOR.'blocker'.DIRECTORY_SEPARATOR.'backups']);
        $this->actingAsUserWith(['backups.view', 'backups.create']);

        $this->post('/admin/backups')
            ->assertRedirect()
            ->assertSessionHas('error', 'Не удалось создать резервную копию: Ошибка при создании дампа. Подробности — в журнале сервера.');

        $log = ActivityLog::where('action', 'backup_failed')->sole();
        $this->assertStringNotContainsString('blocker', json_encode($log->changes));
        $this->assertFalse(Cache::has(BackupManager::PENDING_KEY));

        $this->get('/admin/backups')->assertInertia(fn (Assert $page) => $page
            ->where('pending', false)
            ->where('lastFailure.message', 'Ошибка при создании дампа. Подробности — в журнале сервера.'));
    }

    public function test_store_does_not_queue_a_second_dump_while_one_is_pending(): void
    {
        Queue::fake();
        $this->actingAsUserWith(['backups.view', 'backups.create']);

        $this->post('/admin/backups')->assertSessionHas('info');
        $this->post('/admin/backups')->assertSessionHas('info', 'Резервная копия уже создаётся');

        Queue::assertPushed(CreateBackup::class, 1);
        $this->get('/admin/backups')->assertInertia(fn (Assert $page) => $page->where('pending', true));
    }

    public function test_missing_dump_tool_reports_how_to_fix_it(): void
    {
        // A MariaDB connection that is never opened: the dump fails on the tool lookup.
        config([
            'database.connections.dumpcheck' => ['driver' => 'mariadb', 'host' => '127.0.0.1', 'database' => 'app'],
            'backup.binary_path' => $this->scratch,
        ]);
        $defaultConnection = config('database.default');
        $path = getenv('PATH');

        config(['database.default' => 'dumpcheck']);
        putenv('PATH=');

        try {
            app(BackupService::class)->create();
            $this->fail('The dump must fail without mariadb-dump and mysqldump.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('mariadb-dump или mysqldump', $e->getMessage());
            $this->assertStringContainsString('BACKUP_BINARY_PATH', $e->getMessage());
            $this->assertSame($e->getMessage(), app(BackupManager::class)->publicMessage($e));
        } finally {
            putenv('PATH='.$path);
            config(['database.default' => $defaultConnection]);
        }

        $this->assertSame([], File::glob($this->dir.DIRECTORY_SEPARATOR.'*'));
        $this->assertSame([], File::glob($this->dir.DIRECTORY_SEPARATOR.'.partial-*'));
    }

    public function test_restore_refuses_an_in_memory_database(): void
    {
        $name = app(BackupService::class)->create()['name'];

        $this->artisan('app:db-restore', ['file' => $name, '--force' => true])->assertFailed();
        $this->artisan('app:db-restore', ['file' => 'db-missing.sqlite', '--force' => true])->assertFailed();
    }

    public function test_restore_replaces_an_on_disk_sqlite_database(): void
    {
        File::ensureDirectoryExists($this->scratch);
        $database = $this->scratch.DIRECTORY_SEPARATOR.'restore.sqlite';
        touch($database);
        $previous = config('database.default');
        config([
            'database.connections.restore_test' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true],
            'database.default' => 'restore_test',
        ]);

        try {
            $db = DB::connection('restore_test');
            $db->statement('CREATE TABLE notes (body TEXT)');
            $db->table('notes')->insert(['body' => 'before']);

            $service = app(BackupService::class);
            $name = $service->create()['name'];
            $db->table('notes')->update(['body' => 'after']);

            Carbon::setTestNow(Carbon::parse('2026-09-24 12:05:00', 'UTC'));
            $safety = $service->safetyDump();
            $service->restore($name);

            $this->assertSame('before', DB::connection('restore_test')->table('notes')->value('body'));
            $this->assertSame('db-20260924-120500-prerestore.sqlite', $safety);
            $this->assertSame('prerestore', BackupFiles::kindOf($safety));
        } finally {
            DB::purge('restore_test');
            config(['database.default' => $previous]);
        }
    }

    public function test_restore_command_clears_the_cache_and_brings_the_app_back_up(): void
    {
        $this->isolateStoragePath();

        $this->withOnDiskSqlite(function ($db) {
            $name = app(BackupService::class)->create()['name'];
            $db->table('notes')->update(['body' => 'after']);
            Cache::put('settings.grouped', ['stale' => true]);
            Cache::put('media.usage-index', ['stale.jpg' => []]);

            $this->artisan('app:db-restore', ['file' => $name, '--force' => true, '--no-safety-backup' => true])
                ->expectsOutputToContain('остановите планировщик')
                ->assertSuccessful();

            $this->assertSame('before', DB::connection('restore_test')->table('notes')->value('body'));
            $this->assertFalse(Cache::has('settings.grouped'));
            $this->assertFalse(Cache::has('media.usage-index'));
            $this->assertFalse($this->app->maintenanceMode()->active());
        });
    }

    public function test_restore_command_keeps_maintenance_mode_it_did_not_enable(): void
    {
        $this->isolateStoragePath();
        $this->app->maintenanceMode()->activate([]);

        $this->withOnDiskSqlite(function () {
            $name = app(BackupService::class)->create()['name'];

            $this->artisan('app:db-restore', ['file' => $name, '--force' => true, '--no-safety-backup' => true])
                ->assertSuccessful();
        });

        $this->assertTrue($this->app->maintenanceMode()->active());
        $this->app->maintenanceMode()->deactivate();
    }

    public function test_stale_work_files_are_removed_and_fresh_ones_kept(): void
    {
        File::ensureDirectoryExists($this->dir);
        $stale = [];
        foreach (['.partial-db-20260924-080000.sql', '.partial-db-20260924-090000.sqlite'] as $file) {
            $stale[] = $path = $this->dir.DIRECTORY_SEPARATOR.$file;
            File::put($path, 'plain dump');
            touch($path, now()->subHours(3)->getTimestamp());
        }
        // A dump that is still being written by another process.
        $running = $this->dir.DIRECTORY_SEPARATOR.'.partial-db-20260924-115500.sql';
        File::put($running, 'in progress');
        touch($running, now()->subMinutes(5)->getTimestamp());

        // Reading the list leaves work files alone: preparing a new dump removes them.
        app(BackupFiles::class)->list();
        $this->assertFileExists($stale[0]);

        app(BackupFiles::class)->deleteStaleWorkFiles();

        foreach ($stale as $path) {
            $this->assertFileDoesNotExist($path);
        }
        $this->assertFileExists($running);
    }

    public function test_backup_job_outlives_the_dump_tool_and_stays_below_retry_after(): void
    {
        $config = app(BackupConfig::class);

        $this->assertGreaterThan($config->dumpTimeout(), $config->jobTimeout());
        $this->assertGreaterThan($config->jobTimeout(), config('queue.connections.database.retry_after'));
    }

    public function test_a_killed_backup_job_reports_the_failure_and_releases_the_page(): void
    {
        Cache::put(BackupManager::PENDING_KEY, 'token', 600);

        (new CreateBackup('token', null, 660))->failed(new TimeoutExceededException('timed out'));

        $this->assertFalse(Cache::has(BackupManager::PENDING_KEY));
        $this->assertSame(1, ActivityLog::where('action', 'backup_failed')->count());

        $this->actingAsUserWith(['backups.view']);
        $this->get('/admin/backups')->assertInertia(fn (Assert $page) => $page
            ->where('pending', false)
            ->where('lastFailure.message', fn (string $message) => str_contains($message, 'BACKUP_TIMEOUT')));
    }

    public function test_failed_scheduled_backup_is_flagged_until_the_next_successful_run(): void
    {
        $event = $this->scheduledBackupEvent();
        app(BackupService::class)->create();
        $this->actingAsUserWith(['backups.view']);
        $this->get('/admin/backups')->assertInertia(fn (Assert $page) => $page->where('scheduleWarning', null));

        Carbon::setTestNow(Carbon::parse('2026-09-25 03:00:00', 'UTC'));
        $event->finish($this->app, 1);

        $this->assertTrue(Cache::has(BackupService::SCHEDULED_FAILURE_KEY));
        $this->assertSame(1, ActivityLog::where('action', 'backup_failed')->count());
        $this->get('/admin/backups')->assertInertia(fn (Assert $page) => $page
            ->where('scheduleWarning.message', fn (string $message) => str_contains($message, 'завершился ошибкой')));

        $event->finish($this->app, 0);
        $this->assertFalse(Cache::has(BackupService::SCHEDULED_FAILURE_KEY));
    }

    public function test_backups_page_warns_when_the_newest_scheduled_dump_is_too_old(): void
    {
        $this->actingAsUserWith(['backups.view']);
        $this->get('/admin/backups')->assertInertia(fn (Assert $page) => $page
            ->where('scheduleWarning.message', fn (string $message) => str_contains($message, 'Плановых копий нет')));

        app(BackupService::class)->create();
        app(BackupService::class)->create('manual');
        Carbon::setTestNow(Carbon::parse('2026-09-25 14:30:00', 'UTC'));
        // A fresh manual dump does not hide a stalled scheduler.
        app(BackupService::class)->create('manual');

        $this->get('/admin/backups')->assertInertia(fn (Assert $page) => $page
            ->where('scheduleWarning.at', '2026-09-24T12:00:00+00:00')
            ->where('scheduleWarning.message', fn (string $message) => str_contains($message, 'больше 26 часов')));
    }

    /** The app:db-backup event registered in routes/console.php. */
    private function scheduledBackupEvent(): Event
    {
        $this->app->make(ConsoleKernel::class)->all();

        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'app:db-backup'));
        $this->assertNotNull($event, 'app:db-backup is not scheduled');

        return $event;
    }

    /**
     * The down/up commands write storage/framework/maintenance.php: point
     * storage_path() at the scratch directory so the real storage is untouched.
     */
    private function isolateStoragePath(): void
    {
        $storage = $this->scratch.DIRECTORY_SEPARATOR.'storage';
        File::ensureDirectoryExists($storage.DIRECTORY_SEPARATOR.'framework');
        $this->app->useStoragePath($storage);
    }

    /** Runs $callback with the default connection on a SQLite file holding notes(body = 'before'). */
    private function withOnDiskSqlite(callable $callback): void
    {
        File::ensureDirectoryExists($this->scratch);
        $database = $this->scratch.DIRECTORY_SEPARATOR.'restore.sqlite';
        touch($database);
        $previous = config('database.default');
        config([
            'database.connections.restore_test' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true],
            'database.default' => 'restore_test',
        ]);

        try {
            $db = DB::connection('restore_test');
            $db->statement('CREATE TABLE notes (body TEXT)');
            $db->table('notes')->insert(['body' => 'before']);

            $callback($db);
        } finally {
            DB::purge('restore_test');
            config(['database.default' => $previous]);
        }
    }
}
