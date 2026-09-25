<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * A manual backup requested from /admin/backups. Runs in the queue so a large
 * database does not hold the HTTP request.
 *
 * While it waits or runs, the PENDING_KEY cache entry holds its token (the page
 * polls it). A failure is stored under FAILURE_KEY with a message that is safe to
 * show in the UI; the raw error (it may contain hosts, users or paths from the
 * dump tool) only goes to the application log.
 */
class CreateBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const PENDING_KEY = 'backups.pending';

    public const FAILURE_KEY = 'backups.last_failure';

    /** Must stay below the queue retry_after (config/queue.php). */
    public int $timeout = 300;

    /** A dump is not retried automatically: the admin sees the error and decides. */
    public int $tries = 1;

    public function __construct(
        public readonly string $token,
        public readonly ?int $userId = null,
    ) {}

    public function handle(BackupService $backups): void
    {
        $user = $this->userId ? User::withTrashed()->find($this->userId) : null;

        try {
            ActivityLog::actingAs($user, function () use ($backups) {
                $result = $backups->create(BackupService::KIND_MANUAL);

                ActivityLog::record(null, 'backup_created', $result['offsite_error'] !== null
                    ? ['error' => [null, 'Копия не выгружена на внешний диск']]
                    : null, $result['name']);
            });
        } catch (\Throwable $e) {
            report($e);
            $message = self::publicMessage($e);

            Cache::put(self::FAILURE_KEY, ['token' => $this->token, 'message' => $message, 'at' => now()->toIso8601String()], now()->addDay());
            ActivityLog::actingAs($user, fn () => ActivityLog::record(null, 'backup_failed', ['error' => [null, $message]]));
        } finally {
            if (Cache::get(self::PENDING_KEY) === $this->token) {
                Cache::forget(self::PENDING_KEY);
            }
        }
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
}
