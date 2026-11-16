<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\System\Backup\BackupManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A manual backup requested from the /admin/backups page. With the sync queue
 * (the default) it runs inside the request, with a queue worker in the background.
 * The work itself and the page state (pending flag, last failure) live in BackupManager.
 *
 * The job timeout is the dump tool timeout plus a margin (BackupConfig::jobTimeout()),
 * so usually the tool stops first and handle() deals with the failure. If the
 * worker still kills the job, failed() records the failure and clears the
 * pending flag; an unfinished work file is removed before the next dump.
 */
class CreateBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** The dump is not retried automatically: the administrator sees the error and decides. */
    public int $tries = 1;

    /**
     * @param  int|null  $userId  Who requested the backup; the id is stored, not the model: the job
     *                            must report a failure even if the user is gone
     * @param  int  $timeout  Job timeout in seconds; must stay below the queue's retry_after (config/queue.php)
     */
    public function __construct(
        public readonly string $token,
        public readonly ?int $userId,
        public int $timeout,
    ) {}

    public function handle(BackupManager $backups): void
    {
        $backups->runManual($this->token, $this->actor());
    }

    /**
     * Called by the queue when the job died outside handle() — killed on timeout
     * or lost and out of $tries — so the page stops waiting and shows the
     * reason.
     */
    public function failed(?\Throwable $e): void
    {
        // The queue calls failed() without dependency injection.
        app(BackupManager::class)->abortManual($this->token, $this->actor(), $e);
    }

    /** The requester, including one moved to the trash; null — the system or the user is gone. */
    private function actor(): ?User
    {
        return $this->userId ? User::withTrashed()->find($this->userId) : null;
    }
}
