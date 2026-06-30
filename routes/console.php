<?php

use App\Models\ActivityLog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Periodic scheduler tasks. They are run by the `scheduler` service
// (docker-compose.yml, `php artisan schedule:work`). Add new ones via
// Schedule::command(...)->daily() and so on right here.

// Daily activity log cleanup: deletes records older than
// config('audit.retention_days') (see config/audit.php). An explicit --model instead
// of auto-discovery. MassPrunable → a single DELETE without model events.
Schedule::command('model:prune', ['--model' => [ActivityLog::class]])->daily();

// Daily DB dump into storage/app/backups (with rotation, see app:db-backup).
// In the prod stack, storage is a volume, so the dumps survive image rebuilds.
// Pulling them off the host / shipping to S3 is left to operations' discretion.
Schedule::command('app:db-backup')->daily();
