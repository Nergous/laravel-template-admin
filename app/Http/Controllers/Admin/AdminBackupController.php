<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\CreateBackup;
use App\Models\ActivityLog;
use App\Services\BackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
 */
class AdminBackupController extends Controller
{
    public function __construct(private readonly BackupService $backups) {}

    public function index(): Response
    {
        return Inertia::render('Backups/Index', [
            'backups' => $this->backups->list(),
            'driver' => config('database.connections.'.config('database.default').'.driver'),
            'encrypted' => $this->backups->encryptionEnabled(),
            'offsite' => $this->backups->offsiteDisk(),
            'keep' => [
                'scheduled' => $this->backups->keepFor(BackupService::KIND_SCHEDULED),
                'manual' => $this->backups->keepFor(BackupService::KIND_MANUAL),
            ],
            'pending' => Cache::has(CreateBackup::PENDING_KEY),
            'lastFailure' => $this->lastFailure(),
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
        Cache::put(CreateBackup::PENDING_KEY, $token, now()->addMinutes(15));
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

    /** @return array{message: string, at: string}|null The last failed manual dump, if newer than every dump. */
    private function lastFailure(): ?array
    {
        $failure = Cache::get(CreateBackup::FAILURE_KEY);
        if (! is_array($failure)) {
            return null;
        }

        $latest = $this->backups->latest();
        if ($latest !== null && $latest['created_at'] >= $failure['at']) {
            return null;
        }

        return ['message' => (string) $failure['message'], 'at' => (string) $failure['at']];
    }
}
