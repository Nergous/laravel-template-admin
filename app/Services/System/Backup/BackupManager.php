<?php

namespace App\Services\System\Backup;

use App\Enums\BackupKind;
use App\Enums\BackupRequestStatus;
use App\Events\ActivityOccurred;
use App\Exceptions\BackupFailedException;
use App\Jobs\CreateBackup;
use App\Models\Media;
use App\Models\User;
use App\Services\Media\MediaLibrary;
use Exception;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * The backups page on top of BackupService: an overview with warnings, manual
 * dumps through the CreateBackup job, download and deletion. Every user action
 * is logged: a dump holds every account and setting, so access to it must be
 * traceable.
 *
 * While a manual dump is waiting or running, its token is cached under
 * PENDING_KEY (the page polls this flag). A failure is stored under FAILURE_KEY
 * with a message safe to show in the UI (BackupFailedException); the original
 * error (which may contain hosts, users and paths from the dump tool) goes only
 * to the application log.
 *
 * The nightly dump is run by the scheduler and nobody sees its output, so the
 * overview warns when the last scheduled run failed or the newest scheduled copy
 * is older than SCHEDULED_MAX_AGE_HOURS (the scheduler is not running).
 */
class BackupManager
{
    /** The daily dump plus a margin for a slow run or a restart around midnight. */
    public const SCHEDULED_MAX_AGE_HOURS = 26;

    /** Token of the manual dump that is queued or running. */
    public const PENDING_KEY = 'backups.pending';

    /** The last manual dump failure. */
    private const FAILURE_KEY = 'backups.last_failure';

    private const GENERIC_ERROR = 'Ошибка при создании дампа. Подробности — в журнале сервера.';

    public function __construct(
        private readonly BackupService $backups,
        private readonly BackupFiles $files,
        private readonly OffsiteBackups $offsite,
        private readonly BackupConfig $config,
        private readonly MediaLibrary $media,
        private readonly Cache $cache,
    ) {}

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $backups = $this->files->list();

        return [
            'backups' => $backups,
            'driver' => $this->backups->driver(),
            'offsite' => $this->offsite->disk(),
            'keep' => [
                'scheduled' => $this->config->keepFor(BackupKind::Scheduled->value),
                'manual' => $this->config->keepFor(BackupKind::Manual->value),
            ],
            'pending' => $this->cache->has(self::PENDING_KEY),
            'lastFailure' => $this->lastFailure($backups[0] ?? null),
            'scheduleWarning' => $this->scheduleWarning($backups),
            // Dumps hold the database only: media library files need their own copy.
            'media' => [
                ...$this->media->totals(),
                'disk' => Media::diskName(),
                'path' => Media::diskName() === 'public' ? 'storage/app/public/media' : 'media/',
            ],
        ];
    }

    /**
     * Dispatches a manual dump; rotation affects manual dumps only.
     * With QUEUE_CONNECTION=sync the job has already run on return, so the
     * result is known at once.
     */
    public function requestManual(?User $actor): ManualBackupResult
    {
        if ($this->cache->has(self::PENDING_KEY)) {
            return new ManualBackupResult(BackupRequestStatus::Busy);
        }

        $token = (string) Str::uuid();
        $timeout = $this->config->jobTimeout();
        // Outlives the job timeout so a second dump cannot start while the first runs.
        $this->cache->put(self::PENDING_KEY, $token, now()->addSeconds($timeout + 120));
        CreateBackup::dispatch($token, $actor?->getKey(), $timeout);

        if ($this->cache->get(self::PENDING_KEY) === $token) {
            return new ManualBackupResult(BackupRequestStatus::Queued);
        }

        $failure = $this->cache->get(self::FAILURE_KEY);
        if (is_array($failure) && ($failure['token'] ?? null) === $token) {
            return new ManualBackupResult(BackupRequestStatus::Failed, (string) $failure['message']);
        }

        return new ManualBackupResult(BackupRequestStatus::Created);
    }

    /**
     * Runs a manual dump from the CreateBackup job and clears the pending flag.
     * A failure is stored with a safe message. Error-level throwables do not get
     * here: the job fails and abortManual() records it from failed().
     */
    public function runManual(string $token, ?User $actor): void
    {
        try {
            $result = $this->backups->create(BackupKind::Manual->tag());

            ActivityOccurred::by($actor, null, 'backup_created', $result['offsite_error'] !== null
                ? ['error' => [null, 'Копия не выгружена на внешний диск']]
                : null, $result['name']);
        } catch (Exception $e) {
            report($e);
            $this->recordFailure($token, $actor, $this->publicMessage($e));
        } finally {
            $this->releasePending($token);
        }
    }

    /**
     * The manual dump job died outside handle() (killed on timeout or lost):
     * records the failure and clears the pending flag so the page stops waiting.
     */
    public function abortManual(string $token, ?User $actor, ?Throwable $e): void
    {
        $this->recordFailure($token, $actor, $e instanceof TimeoutExceededException
            ? 'Создание копии прервано: превышено время ожидания. Увеличьте BACKUP_TIMEOUT или создайте копию командой php artisan app:db-backup.'
            : self::GENERIC_ERROR);
        $this->releasePending($token);
    }

    /**
     * Failure message for the UI: the BackupFailedException text is written for
     * people, anything else is replaced with a generic message.
     */
    public function publicMessage(Throwable $e): string
    {
        return $e instanceof BackupFailedException
            ? mb_strimwidth($e->getMessage(), 0, 240, '…')
            : self::GENERIC_ERROR;
    }

    /** Logs a dump download and returns its absolute path; null — no such dump. */
    public function prepareDownload(string $file, User $actor): ?string
    {
        $path = $this->files->path($file);

        if ($path !== null) {
            ActivityOccurred::by($actor, null, 'backup_downloaded', null, $file);
        }

        return $path;
    }

    /** Deletes a dump and logs it; false — no such dump. */
    public function delete(string $file, User $actor): bool
    {
        if (! $this->files->delete($file)) {
            return false;
        }

        ActivityOccurred::by($actor, null, 'backup_deleted', null, $file);

        return true;
    }

    private function recordFailure(string $token, ?User $actor, string $message): void
    {
        $this->cache->put(self::FAILURE_KEY, ['token' => $token, 'message' => $message, 'at' => now()->toIso8601String()], now()->addDay());

        ActivityOccurred::by($actor, null, 'backup_failed', ['error' => [null, $message]]);
    }

    private function releasePending(string $token): void
    {
        if ($this->cache->get(self::PENDING_KEY) === $token) {
            $this->cache->forget(self::PENDING_KEY);
        }
    }

    /**
     * @param  array{created_at: string}|null  $latest  The newest dump of any kind
     * @return array{message: string, at: string}|null The last manual dump failure, if newer than every dump
     */
    private function lastFailure(?array $latest): ?array
    {
        $failure = $this->cache->get(self::FAILURE_KEY);
        if (! is_array($failure)) {
            return null;
        }

        // Instants are compared: dump names are in UTC, the failure time in the app time zone.
        if ($latest !== null && Carbon::parse($latest['created_at'])->gte(Carbon::parse($failure['at']))) {
            return null;
        }

        return ['message' => (string) $failure['message'], 'at' => (string) $failure['at']];
    }

    /**
     * A nightly dump problem: the last scheduled run failed (and no scheduled copy
     * followed it) or the newest scheduled copy is too old.
     *
     * @param  list<array{name: string, created_at: string, kind: string}>  $backups
     * @return array{message: string, at: string|null}|null
     */
    private function scheduleWarning(array $backups): ?array
    {
        $latest = collect($backups)->firstWhere('kind', BackupKind::Scheduled->value);
        $failure = $this->cache->get(BackupService::SCHEDULED_FAILURE_KEY);

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
