<?php

namespace App\Services\System\Dashboard;

use App\Models\User;
use App\Services\System\Backup\BackupFiles;
use App\Services\System\QueueStats;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Support\Carbon;

/**
 * Dashboard issues the user can act on: failed or stuck queue jobs, a missing
 * or old database backup. Each item is returned only with the permission of
 * the page it leads to; when everything is fine the list is empty and the
 * dashboard hides the card.
 */
class DashboardAttention
{
    /** A newest backup older than this needs attention. */
    private const BACKUP_STALE_HOURS = 48;

    public function __construct(
        private readonly QueueStats $queue,
        private readonly BackupFiles $backups,
        private readonly UrlGenerator $url,
    ) {}

    /** @return list<array{key: string, tone: string, label: string, value: string, url: string}> */
    public function for(User $user): array
    {
        return [
            ...($user->can('queue.view') ? $this->queue() : []),
            ...($user->can('backups.view') ? $this->backup() : []),
        ];
    }

    /** @return list<array{key: string, tone: string, label: string, value: string, url: string}> */
    private function queue(): array
    {
        $items = [];

        $failed = $this->queue->failedCount();
        if ($failed > 0) {
            $items[] = [
                'key' => 'failed_jobs',
                'tone' => 'danger',
                'label' => 'Упавшие задачи в очереди',
                'value' => (string) $failed,
                'url' => $this->url->route('admin.queue.index', ['status' => 'failed']),
            ];
        }

        if ($this->queue->isStalled()) {
            $items[] = [
                'key' => 'stalled_queue',
                'tone' => 'warn',
                'label' => 'Очередь не разбирается',
                'value' => 'дольше '.QueueStats::STALLED_AFTER_MINUTES.' минут',
                'url' => $this->url->route('admin.queue.index'),
            ];
        }

        return $items;
    }

    /** @return list<array{key: string, tone: string, label: string, value: string, url: string}> */
    private function backup(): array
    {
        $latest = $this->backups->latest();
        $at = $latest ? Carbon::parse($latest['created_at']) : null;

        if ($at !== null && $at->gte(now()->subHours(self::BACKUP_STALE_HOURS))) {
            return [];
        }

        return [[
            'key' => 'backup',
            'tone' => 'warn',
            'label' => 'Резервная копия базы',
            'value' => $at ? 'последняя '.$at->diffForHumans() : 'нет ни одной',
            'url' => $this->url->route('admin.backups.index'),
        ]];
    }
}
