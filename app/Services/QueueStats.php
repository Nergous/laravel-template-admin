<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Read-only queue state for the dashboard, /admin/queue and the /up health check.
 *
 * Pending jobs are only visible with the database queue driver (the jobs table);
 * for redis/sqs/sync they are reported as null ("not available"). Failed jobs go
 * through the configured failer, so any failed-job driver works.
 */
class QueueStats
{
    public function __construct(private readonly FailedJobProviderInterface $failer) {}

    /** @return array{pending: int|null, oldest_pending_at: string|null, failed: int} */
    public function summary(): array
    {
        $pending = null;
        $oldest = null;

        if ($this->isDatabaseQueue()) {
            $pending = $this->waitingJobs()->count();
            $oldest = $this->oldestPendingAt()?->toIso8601String();
        }

        return [
            'pending' => $pending,
            'oldest_pending_at' => $oldest,
            'failed' => $this->failedCount(),
        ];
    }

    /**
     * Since when the oldest runnable job has been waiting (delayed jobs count from
     * their available_at). Null when nothing waits or the driver is not database.
     */
    public function oldestPendingAt(): ?Carbon
    {
        if (! $this->isDatabaseQueue()) {
            return null;
        }

        $oldest = $this->waitingJobs()
            ->where('available_at', '<=', now()->getTimestamp())
            ->min('available_at');

        return $oldest === null ? null : Carbon::createFromTimestampUTC((int) $oldest);
    }

    public function failedCount(): int
    {
        return $this->failer instanceof CountableFailedJobProvider
            ? $this->failer->count()
            : count($this->failer->all());
    }

    /**
     * Failed jobs, newest first, shaped for the UI.
     *
     * @return LengthAwarePaginator<int, array{id: string, uuid: string|null, connection: string, queue: string, name: string, exception: string, failed_at: string|null}>
     */
    public function failedJobs(int $perPage = 20): LengthAwarePaginator
    {
        if ($this->failer instanceof DatabaseUuidFailedJobProvider) {
            return DB::connection(config('queue.failed.database'))
                ->table($this->failer->getTable())
                ->orderByDesc('id')
                ->paginate($perPage)
                ->through(fn (object $job): array => $this->present($job));
        }

        // Other drivers (file, dynamodb, null) only expose all().
        $all = collect($this->failer->all());
        $page = Paginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $all->forPage($page, $perPage)->map(fn (object $job): array => $this->present($job))->values(),
            $all->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );
    }

    /** Human-readable job name from a failed job record (the payload's displayName). */
    public static function jobName(object $job): string
    {
        $payload = json_decode((string) ($job->payload ?? ''), true);

        return is_array($payload) && is_string($payload['displayName'] ?? null)
            ? $payload['displayName']
            : 'Неизвестная задача';
    }

    /** @return array{id: string, uuid: string|null, connection: string, queue: string, name: string, exception: string, failed_at: string|null} */
    private function present(object $job): array
    {
        $uuid = isset($job->uuid) && Str::isUuid((string) $job->uuid) ? (string) $job->uuid : null;
        $failedAt = $job->failed_at ?? null;

        return [
            'id' => (string) ($uuid ?? $job->id ?? ''),
            'uuid' => $uuid,
            'connection' => (string) ($job->connection ?? ''),
            'queue' => (string) ($job->queue ?? ''),
            'name' => self::jobName($job),
            'exception' => Str::limit(trim(strtok((string) ($job->exception ?? ''), "\n") ?: ''), 300),
            'failed_at' => match (true) {
                $failedAt === null, $failedAt === '' => null,
                is_numeric($failedAt) => Carbon::createFromTimestampUTC((int) $failedAt)->toIso8601String(),
                default => Carbon::parse((string) $failedAt, 'UTC')->toIso8601String(),
            },
        ];
    }

    private function isDatabaseQueue(): bool
    {
        return config('queue.connections.'.config('queue.default').'.driver') === 'database';
    }

    /** Jobs not picked up by a worker yet (including delayed ones). */
    private function waitingJobs(): Builder
    {
        $config = config('queue.connections.'.config('queue.default'));

        return DB::connection($config['connection'] ?? null)
            ->table((string) ($config['table'] ?? 'jobs'))
            ->whereNull('reserved_at');
    }
}
