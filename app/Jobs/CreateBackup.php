<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Cache;

/**
 * A manual backup requested from /admin/backups. Runs in the queue so a large
 * database does not hold the HTTP request.
 *
 * While it waits or runs, the PENDING_KEY cache entry holds its token (the page
 * polls it). A failure is stored under FAILURE_KEY with a message that is safe to
 * show in the UI; the raw error (it may contain hosts, users or paths from the
 * dump tool) only goes to the application log.
 *
 * The job timeout is the dump tool timeout plus a margin (timeoutSeconds()), so
 * the tool normally stops first and handle() reports it. If the worker still
 * kills the job, failed() records the failure and clears the pending flag; the
 * half-written work file is removed by BackupService on its next run.
 */
class CreateBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const PENDING_KEY = 'backups.pending';

    public const FAILURE_KEY = 'backups.last_failure';

    /** Set from config in the constructor; must stay below the queue retry_after (config/queue.php). */
    public int $timeout;

    /** A dump is not retried automatically: the admin sees the error and decides. */
    public int $tries = 1;

    public function __construct(
        public readonly string $token,
        public readonly ?int $userId = null,
    ) {
        $this->timeout = self::timeoutSeconds();
    }

    /** Job timeout: the dump tool limit (config backup.timeout) plus a margin for encryption and upload. */
    public static function timeoutSeconds(): int
    {
        return BackupService::dumpTimeout() + 60;
    }

    public function handle(BackupService $backups): void
    {
        $user = $this->user();

        try {
            ActivityLog::actingAs($user, function () use ($backups) {
                $result = $backups->create(BackupService::KIND_MANUAL);

                ActivityLog::record(null, 'backup_created', $result['offsite_error'] !== null
                    ? ['error' => [null, 'Копия не выгружена на внешний диск']]
                    : null, $result['name']);
            });
        } catch (\Throwable $e) {
            report($e);
            $this->recordFailure(self::publicMessage($e));
        } finally {
            $this->releasePending();
        }
    }

    /**
     * Called by the worker when the job dies outside handle() — killed on the
     * timeout or lost and retried past $tries — so the page stops waiting and
     * shows why.
     */
    public function failed(?\Throwable $e): void
    {
        $this->recordFailure($e instanceof TimeoutExceededException
            ? 'Создание копии прервано: превышено время ожидания. Увеличьте BACKUP_TIMEOUT или создайте копию командой php artisan app:db-backup.'
            : 'Ошибка при создании дампа. Подробности — в журнале сервера.');
        $this->releasePending();
    }

    /**
     * Our own validation errors are written for people; anything wrapping a
     * process or driver error is replaced with a generic message.
     */
    public static function publicMessage(\Throwable $e): string
    {
        $own = in_array($e::class, [\RuntimeException::class, \InvalidArgumentException::class], true)
            && $e->getPrevious() === null;

        return $own
            ? mb_strimwidth($e->getMessage(), 0, 240, '…')
            : 'Ошибка при создании дампа. Подробности — в журнале сервера.';
    }

    private function recordFailure(string $message): void
    {
        Cache::put(self::FAILURE_KEY, ['token' => $this->token, 'message' => $message, 'at' => now()->toIso8601String()], now()->addDay());
        ActivityLog::actingAs($this->user(), fn () => ActivityLog::record(null, 'backup_failed', ['error' => [null, $message]]));
    }

    private function releasePending(): void
    {
        if (Cache::get(self::PENDING_KEY) === $this->token) {
            Cache::forget(self::PENDING_KEY);
        }
    }

    private function user(): ?User
    {
        return $this->userId ? User::withTrashed()->find($this->userId) : null;
    }
}
