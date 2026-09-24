<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Database backups made by app:db-backup (storage/app/backups).
 *
 * The list and downloads are gated by backups.view, creating a dump by
 * backups.create. Creating and downloading are written to the activity log:
 * a dump contains every account and setting, so access to it must be traceable.
 */
class AdminBackupController extends Controller
{
    public function index(): Response
    {
        $files = collect(glob($this->directory().DIRECTORY_SEPARATOR.'db-*') ?: [])
            ->filter(fn (string $path) => is_file($path))
            ->sortByDesc(fn (string $path) => filemtime($path))
            ->map(fn (string $path) => [
                'name' => basename($path),
                'size' => filesize($path) ?: 0,
                'created_at' => Carbon::createFromTimestamp((int) filemtime($path))->toIso8601String(),
            ])
            ->values();

        return Inertia::render('Backups/Index', [
            'backups' => $files,
            'driver' => config('database.connections.'.config('database.default').'.driver'),
        ]);
    }

    /** Runs app:db-backup synchronously; rotation keeps the command's default count. */
    public function store(): RedirectResponse
    {
        $before = $this->names();
        $exitCode = Artisan::call('app:db-backup');

        if ($exitCode !== 0) {
            return back()->with('error', 'Не удалось создать резервную копию: '.trim(Artisan::output()));
        }

        $created = array_values(array_diff($this->names(), $before))[0] ?? null;
        ActivityLog::record(null, 'backup_created', null, $created);

        return back()->with('success', 'Резервная копия создана');
    }

    public function download(string $file): BinaryFileResponse
    {
        $path = $this->directory().DIRECTORY_SEPARATOR.basename($file);
        abort_unless(is_file($path), 404);

        ActivityLog::record(null, 'backup_downloaded', null, basename($file));

        return response()->download($path);
    }

    private function directory(): string
    {
        return storage_path('app/backups');
    }

    /** @return list<string> */
    private function names(): array
    {
        return array_map('basename', glob($this->directory().DIRECTORY_SEPARATOR.'db-*') ?: []);
    }
}
