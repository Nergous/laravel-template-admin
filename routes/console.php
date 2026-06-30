<?php

use App\Models\ActivityLog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Периодические задачи планировщика. Их гоняет сервис `scheduler`
// (docker-compose.yml, `php artisan schedule:work`). Добавляйте новые через
// Schedule::command(...)->daily() и т.п. прямо здесь.

// Ежедневная чистка журнала действий: удаляет записи старше
// config('audit.retention_days') (см. config/audit.php). Явный --model вместо
// авто-дискавери. MassPrunable → один DELETE без событий модели.
Schedule::command('model:prune', ['--model' => [ActivityLog::class]])->daily();

// Ежедневный дамп БД в storage/app/backups (с ротацией, см. app:db-backup).
// storage в prod-стеке — это том, поэтому дампы переживают пересборку образа.
// Снимать их с хоста/отгружать в S3 — на усмотрение эксплуатации.
Schedule::command('app:db-backup')->daily();
