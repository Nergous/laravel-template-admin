<?php

namespace App\Http\Controllers\Admin\System;

use App\Enums\BackupRequestStatus;
use App\Http\Controllers\Controller;
use App\Services\System\Backup\BackupManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Database backups (BackupManager, config/backup.php).
 *
 * The list requires backups.view, download — backups.download, creating a
 * dump — backups.create, deletion — backups.delete. A manual dump dispatches the
 * CreateBackup job; the page polls the pending flag and shows the last failure
 * (a sanitized message).
 */
class BackupController extends Controller
{
    public function __construct(private readonly BackupManager $backups) {}

    public function index(): Response
    {
        return Inertia::render('Backups/Index', $this->backups->overview());
    }

    public function store(Request $request): RedirectResponse
    {
        $result = $this->backups->requestManual($request->user());

        return match ($result->status) {
            BackupRequestStatus::Busy => back()->with('info', 'Резервная копия уже создаётся'),
            BackupRequestStatus::Queued => back()->with('info', 'Резервная копия создаётся в фоне — список обновится автоматически'),
            BackupRequestStatus::Failed => back()->with('error', 'Не удалось создать резервную копию: '.$result->message),
            BackupRequestStatus::Created => back()->with('success', 'Резервная копия создана'),
        };
    }

    public function download(Request $request, string $file): BinaryFileResponse
    {
        $path = $this->backups->prepareDownload($file, $request->user());
        abort_if($path === null, 404);

        return response()->download($path);
    }

    public function destroy(Request $request, string $file): RedirectResponse
    {
        abort_unless($this->backups->delete($file, $request->user()), 404);

        return back()->with('success', 'Резервная копия удалена');
    }
}
