<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\CreateBackup;
use App\Models\ActivityLog;
use App\Models\Media;
use App\Services\BackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Database backups (App\Services\BackupService, config/backup.php).
 *
 * The list is gated by backups.view, downloads by backups.download, creating a
 * dump by backups.create, deleting by backups.delete. Every action is written to the
 * activity log: a dump contains every account and setting, so access to it must
 * be traceable.
 *
 * A manual dump is created by the CreateBackup job; the page polls the pending
 * flag and shows the last failure (a sanitized message) from the cache.
 *
 * The nightly dump runs in the scheduler, where nobody sees its output, so the
 * page also warns when the last scheduled run failed or the newest scheduled dump
 * is older than SCHEDULED_MAX_AGE_HOURS (the scheduler is not running).
 */
class AdminBackupController extends Controller
{
    /** A daily dump plus slack for a slow run or a restart around midnight. */
    public const SCHEDULED_MAX_AGE_HOURS = 26;

    public function __construct(private readonly BackupService $backups) {}

    public function index(): Response
    {
        $backups = $this->backups->list();

        return Inertia::render('Backups/Index', [
            'backups' => $backups,
            'driver' => config('database.connections.'.config('database.default').'.driver'),
            'encrypted' => $this->backups->encryptionEnabled(),
            'offsite' => $this->backups->offsiteDisk(),
            'keep' => [
                'scheduled' => $this->backups->keepFor(BackupService::KIND_SCHEDULED),
                'manual' => $this->backups->keepFor(BackupService::KIND_MANUAL),
            ],
            'pending' => Cache::has(CreateBackup::PENDING_KEY),
            'lastFailure' => $this->lastFailure($backups[0] ?? null),
            'scheduleWarning' => $this->scheduleWarning($backups),
            // Dumps hold the database only: the media library files need their own copy.
            'media' => [
                'count' => Media::count(),
                'bytes' => (int) Media::sum('size'),
                'disk' => Media::diskName(),
                'path' => Media::diskName() === 'public' ? 'storage/app/public/media' : 'media/',
            ],
        ]);
    }

    /**
     * Queues a manual dump; rotation touches manual dumps only. With
     * QUEUE_CONNECTION=sync the job has already finished here, so the result
     * is reported right away.
     */
    public function store(Request $request): RedirectResponse
    {
        if (Cache::has(CreateBackup::PENDING_KEY)) {
            return back()->with('info', 'Резервная копия уже создаётся');
        }

        $token = (string) Str::uuid();
        // Outlives the job timeout, so a second dump cannot start while one still runs.
        Cache::put(CreateBackup::PENDING_KEY, $token, now()->addSeconds(CreateBackup::timeoutSeconds() + 120));
        CreateBackup::dispatch($token, $request->user()?->getKey());

        if (Cache::get(CreateBackup::PENDING_KEY) === $token) {
            return back()->with('info', 'Резервная копия создаётся в фоне — список обновится автоматически');
        }

        $failure = Cache::get(CreateBackup::FAILURE_KEY);
        if (is_array($failure) && ($failure['token'] ?? null) === $token) {
            return back()->with('error', 'Не удалось создать резервную копию: '.$failure['message']);
        }

        return back()->with('success', 'Резервная копия создана');
    }

    public function download(string $file): BinaryFileResponse
    {
        $path = $this->backups->path($file);
        abort_if($path === null, 404);

        ActivityLog::record(null, 'backup_downloaded', null, $file);

        return response()->download($path);
    }

    public function destroy(string $file): RedirectResponse
    {
        abort_unless($this->backups->delete($file), 404);

        ActivityLog::record(null, 'backup_deleted', null, $file);

        return back()->with('success', 'Резервная копия удалена');
    }

    /**
     * @param  array{created_at: string}|null  $latest  The newest dump of any kind
     * @return array{message: string, at: string}|null The last failed manual dump, if newer than every dump.
     */
    private function lastFailure(?array $latest): ?array
    {
        $failure = Cache::get(CreateBackup::FAILURE_KEY);
        if (! is_array($failure)) {
            return null;
        }

        // Compared as instants: dump names carry UTC, the failure time the app timezone.
        if ($latest !== null && Carbon::parse($latest['created_at'])->gte(Carbon::parse($failure['at']))) {
            return null;
        }

        return ['message' => (string) $failure['message'], 'at' => (string) $failure['at']];
    }

    /**
     * Problem with the nightly dump: the last scheduled run failed (and no
     * scheduled dump was made after it), or the newest scheduled dump is too old.
     *
     * @param  list<array{name: string, created_at: string, kind: string}>  $backups
     * @return array{message: string, at: string|null}|null
     */
    private function scheduleWarning(array $backups): ?array
    {
        $latest = collect($backups)->firstWhere('kind', BackupService::KIND_SCHEDULED);
        $failure = Cache::get(BackupService::SCHEDULED_FAILURE_KEY);

        if (is_array($failure) && ($latest === null || Carbon::parse($latest['created_at'])->lt(Carbon::parse($failure['at'])))) {
            return [
                'message' => 'Последний плановый запуск резервного копирования завершился ошибкой. Подробности — в журнале сервера.',
                'at' => (string) $failure['at'],
            ];
        }

        if ($latest === null) {
            return [
                'message' => 'Плановых копий нет. Проверьте, что запущен планировщик (php artisan schedule:work или сервис scheduler).',
                'at' => null,
            ];
        }

        if (Carbon::parse($latest['created_at'])->lt(now()->subHours(self::SCHEDULED_MAX_AGE_HOURS))) {
            return [
                'message' => 'Последней плановой копии больше '.self::SCHEDULED_MAX_AGE_HOURS.' часов. Проверьте, что запущен планировщик (php artisan schedule:work или сервис scheduler).',
                'at' => $latest['created_at'],
            ];
        }

        return null;
    }
}
