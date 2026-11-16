<?php

use App\Events\ActivityOccurred;
use App\Models\ActivityLog;
use App\Services\System\Backup\BackupService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Periodic scheduler tasks. They are run by the `scheduler` service
// (docker-compose.yml, `php artisan schedule:work`). Add new ones via
// Schedule::command(...)->daily() and so on right here.

// Daily activity log pruning: entries older than config('activity.retention_days')
// (see config/activity.php). The model is named explicitly, no auto-discovery.
// ActivityLog is MassPrunable, so pruning is a single DELETE without model events.
Schedule::command('model:prune', ['--model' => [ActivityLog::class]])->daily();

// Daily DB dump into config('backup.path') (storage/app/backups by default) with
// rotation of scheduled dumps; an optional offsite copy to BACKUP_DISK is
// configured in config/backup.php.
// Nobody watches the scheduler output, so a failed run (non-zero exit: no dump, or
// no offsite copy) is logged, written to the activity log and flagged in the cache
// for the backups page; the next successful run clears the flag. The command
// itself reports the underlying exception to the application log.
Schedule::command('app:db-backup')
    ->daily()
    ->onSuccess(fn () => Cache::forget(BackupService::SCHEDULED_FAILURE_KEY))
    ->onFailure(function () {
        Log::error('Scheduled database backup (app:db-backup) failed; see the previous log entries for the cause.');
        Cache::forever(BackupService::SCHEDULED_FAILURE_KEY, ['at' => now()->toIso8601String()]);
        ActivityOccurred::by(null, null, 'backup_failed', ['error' => [null, 'Плановая резервная копия не создана или не выгружена']]);
    });

// Daily cleanup of files no media row references (stale temp uploads, leftovers
// of failed jobs). Files younger than 24 hours are kept for jobs still queued.
Schedule::command('media:prune-orphans')->daily();
