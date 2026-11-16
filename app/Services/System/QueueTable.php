<?php

namespace App\Services\System;

use App\Enums\QueueJobStatus;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use LogicException;

/**
 * The jobs table of the database queue driver and the default queue connection.
 * Settings are read on every call: the queue connection may change without a
 * restart (tests, commands).
 */
class QueueTable
{
    public function __construct(
        private readonly Config $config,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * The default queue connection and its driver.
     *
     * @return array{connection: string, driver: string|null}
     */
    public function connection(): array
    {
        $connection = (string) $this->config->get('queue.default');

        return [
            'connection' => $connection,
            'driver' => $this->config->get("queue.connections.{$connection}.driver"),
        ];
    }

    /** Whether queued jobs are visible: only the database driver has a jobs table. */
    public function isDatabase(): bool
    {
        return $this->connection()['driver'] === 'database';
    }

    /** Jobs no worker has taken yet (including delayed ones). */
    public function waiting(): Builder
    {
        return $this->jobs()->whereNull('reserved_at');
    }

    /** Jobs in the queue table with the given status; null — all. */
    public function queued(?QueueJobStatus $status): Builder
    {
        $now = now()->getTimestamp();

        return match ($status) {
            null => $this->jobs(),
            QueueJobStatus::Running => $this->jobs()->whereNotNull('reserved_at'),
            QueueJobStatus::Pending => $this->waiting()->where('available_at', '<=', $now),
            QueueJobStatus::Delayed => $this->waiting()->where('available_at', '>', $now),
            QueueJobStatus::Failed => throw new LogicException('Упавшие задачи лежат в таблице упавших, а не в очереди.'),
        };
    }

    private function jobs(): Builder
    {
        $config = $this->config->get('queue.connections.'.$this->config->get('queue.default'));

        return $this->db->connection($config['connection'] ?? null)
            ->table((string) ($config['table'] ?? 'jobs'));
    }
}
