<?php

namespace Tests\Feature;

use App\Jobs\CreateBackup;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\BackupCipher;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
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
            'backup.encryption_key' => null,
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

    /** Encryption needs libsodium; local PHP builds without it skip these tests. */
    private function requireSodium(): void
    {
        if (! function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')) {
            $this->markTestSkipped('The sodium extension is unavailable');
        }
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

        $latest = app(BackupService::class)->latest();
        $this->assertSame('db-20260924-120000.sqlite', $latest['name']);
        $this->assertSame('scheduled', $latest['kind']);
        $this->assertFalse($latest['encrypted']);
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
        $this->assertSame('manual', BackupService::kindOf($second['name']));
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

        $names = array_column($service->list(), 'name');

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

        $this->assertSame(['db-20260924-120200.sqlite'], array_column(app(BackupService::class)->list(), 'name'));
    }

    public function test_invalid_tag_is_rejected(): void
    {
        $this->artisan('app:db-backup', ['--tag' => '../x'])->assertFailed();

        $this->assertSame([], app(BackupService::class)->list());
    }

    public function test_encrypted_dump_round_trips_through_decrypt_command(): void
    {
        $this->requireSodium();
        config(['backup.encryption_key' => BackupCipher::generateKey()]);
        User::factory()->create(['email' => 'secret-marker@example.test']);

        $this->artisan('app:db-backup', ['--tag' => 'manual'])->assertSuccessful();

        $name = 'db-20260924-120000-manual.sqlite.enc';
        $encrypted = $this->dir.DIRECTORY_SEPARATOR.$name;
        $this->assertFileExists($encrypted);
        $this->assertStringNotContainsString('secret-marker@example.test', (string) file_get_contents($encrypted));
        // Only the encrypted file remains: no plaintext work file is left behind.
        $this->assertSame([$name], array_values(array_diff(scandir($this->dir), ['.', '..'])));

        $latest = app(BackupService::class)->latest();
        $this->assertTrue($latest['encrypted']);
        $this->assertSame('manual', $latest['kind']);

        $output = $this->scratch.DIRECTORY_SEPARATOR.'restored.sqlite';
        $this->artisan('app:db-backup-decrypt', ['file' => $name, '--output' => $output])->assertSuccessful();

        $plain = (string) file_get_contents($output);
        $this->assertStringStartsWith("SQLite format 3\0", $plain);
        $this->assertStringContainsString('secret-marker@example.test', $plain);
    }

    public function test_decrypt_fails_with_wrong_key_or_truncated_file(): void
    {
        $this->requireSodium();
        config(['backup.encryption_key' => BackupCipher::generateKey()]);
        $name = app(BackupService::class)->create()['name'];
        $path = $this->dir.DIRECTORY_SEPARATOR.$name;

        $truncated = $this->scratch.DIRECTORY_SEPARATOR.'truncated.enc';
        file_put_contents($truncated, substr((string) file_get_contents($path), 0, -20));
        $out = $this->scratch.DIRECTORY_SEPARATOR.'out-truncated.sqlite';
        $this->artisan('app:db-backup-decrypt', ['file' => $truncated, '--output' => $out])->assertFailed();
        $this->assertFileDoesNotExist($out);

        config(['backup.encryption_key' => BackupCipher::generateKey()]);
        $out = $this->scratch.DIRECTORY_SEPARATOR.'out-wrong-key.sqlite';
        $this->artisan('app:db-backup-decrypt', ['file' => $path, '--output' => $out])->assertFailed();
        $this->assertFileDoesNotExist($out);
    }

    public function test_invalid_key_fails_before_writing_anything(): void
    {
        config(['backup.encryption_key' => 'base64:'.base64_encode('short')]);

        $this->artisan('app:db-backup')->assertFailed();

        $this->assertDirectoryDoesNotExist($this->dir);
    }

    public function test_backup_key_command_prints_a_usable_key(): void
    {
        $this->requireSodium();
        Artisan::call('app:backup-key');
        $key = strtok(trim(Artisan::output()), "\r\n");

        $this->assertMatchesRegularExpression('/^base64:[A-Za-z0-9+\/=]+$/', (string) $key);
        $this->assertSame(32, strlen(BackupCipher::decodeKey((string) $key)));
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
        $service = app(BackupService::class);

        $this->assertSame([], $service->list());
        $this->assertNull($service->latest());
        $this->assertDirectoryDoesNotExist($this->dir);
    }

    public function test_path_rejects_foreign_names(): void
    {
        $service = app(BackupService::class);
        $service->create();
        File::put($this->scratch.DIRECTORY_SEPARATOR.'db-outside.sqlite', 'x');

        $this->assertNotNull($service->path('db-20260924-120000.sqlite'));
        foreach (['db-..', '../db-outside.sqlite', 'db-../../x', 'other.sqlite', '', 'db-a/b', 'db-a\\b'] as $name) {
            $this->assertNull($service->path($name), $name);
            $this->assertFalse($service->delete($name), $name);
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
                ->where('backups.0.encrypted', false)
                ->where('encrypted', false)
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
        $this->assertFalse(Cache::has(CreateBackup::PENDING_KEY));

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
            $safety = $service->restore($name);

            $this->assertSame('before', DB::connection('restore_test')->table('notes')->value('body'));
            $this->assertSame('db-20260924-120500-prerestore.sqlite', $safety);
            $this->assertSame('prerestore', BackupService::kindOf((string) $safety));
        } finally {
            DB::purge('restore_test');
            config(['database.default' => $previous]);
        }
    }
}
