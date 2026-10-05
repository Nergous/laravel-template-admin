<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Read-only queue state for the dashboard, /admin/queue and the /up health check.
 *
 * Queued jobs (pending, delayed, running) are only visible with the database
 * queue driver (the jobs table); for redis/sqs/sync they are reported as null
 * ("not available"). Failed jobs go through the configured failer, so any
 * failed-job driver works.
 */
class QueueStats
{
    /** Job statuses shown in the admin list, in display order. */
    public const STATUSES = ['running', 'pending', 'delayed', 'failed'];

    /** A runnable job waiting longer than this means a stuck or missing worker. */
    public const STALLED_AFTER_MINUTES = 15;

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

    /**
     * Whether a runnable job has waited longer than STALLED_AFTER_MINUTES
     * (database queue only). Used by /up?full=1 and the admin queue page.
     */
    public function isStalled(): bool
    {
        $oldest = $this->oldestPendingAt();

        return $oldest !== null && $oldest->lt(now()->subMinutes(self::STALLED_AFTER_MINUTES));
    }

    public function failedCount(): int
    {
        return $this->failer instanceof CountableFailedJobProvider
            ? $this->failer->count()
            : count($this->failer->all());
    }

    /**
     * Number of jobs per status. Queued statuses are null when the driver is
     * not database.
     *
     * @return array{running: int|null, pending: int|null, delayed: int|null, failed: int}
     */
    public function statusCounts(): array
    {
        $database = $this->isDatabaseQueue();
        $counts = [];

        foreach (['running', 'pending', 'delayed'] as $status) {
            $counts[$status] = $database ? $this->queuedJobs($status)->count() : null;
        }

        return $counts + ['failed' => $this->failedCount()];
    }

    /**
     * Queued jobs (in queue order) followed by failed jobs (newest first), shaped
     * for the UI. A status limits the list to one kind; null lists everything.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function jobs(?string $status = null, int $perPage = 20): LengthAwarePaginator
    {
        $page = Paginator::resolveCurrentPage();
        $offset = ($page - 1) * $perPage;
        $rows = collect();
        $total = 0;

        if ($status !== 'failed' && $this->isDatabaseQueue()) {
            $queued = $this->queuedJobs($status);
            $queuedTotal = (clone $queued)->count();

            $rows = $queued
                ->select(['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'])
                ->orderBy('available_at')
                ->orderBy('id')
                ->offset($offset)
                ->limit($perPage)
                ->get()
                ->map(fn (object $job): array => $this->presentQueued($job));

            $total += $queuedTotal;
            // Failed jobs continue the list after the last queued one.
            $offset = max(0, $offset - $queuedTotal);
        }

        if ($status === null || $status === 'failed') {
            $total += $this->failedCount();
            $limit = $perPage - $rows->count();

            if ($limit > 0) {
                $rows = $rows->concat($this->failedSlice($offset, $limit));
            }
        }

        return new LengthAwarePaginator(
            $rows->values(),
            $total,
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );
    }

    /** Human-readable job name from a job record (the payload's displayName). */
    public static function jobName(object $job): string
    {
        $payload = json_decode((string) ($job->payload ?? ''), true);

        return is_array($payload) && is_string($payload['displayName'] ?? null)
            ? $payload['displayName']
            : 'Неизвестная задача';
    }

    /** @return Collection<int, array<string, mixed>> */
    private function failedSlice(int $offset, int $limit): Collection
    {
        if ($this->failer instanceof DatabaseUuidFailedJobProvider) {
            return DB::connection(config('queue.failed.database'))
                ->table($this->failer->getTable())
                ->orderByDesc('id')
                ->offset($offset)
                ->limit($limit)
                ->get()
                ->map(fn (object $job): array => $this->presentFailed($job));
        }

        // Other drivers (file, dynamodb, null) only expose all().
        return collect($this->failer->all())
            ->slice($offset, $limit)
            ->map(fn (object $job): array => $this->presentFailed($job))
            ->values();
    }

    /** @return array<string, mixed> */
    private function presentQueued(object $job): array
    {
        $status = match (true) {
            $job->reserved_at !== null => 'running',
            (int) $job->available_at > now()->getTimestamp() => 'delayed',
            default => 'pending',
        };

        return [
            'id' => 'job-'.$job->id,
            'uuid' => null,
            'status' => $status,
            'connection' => (string) config('queue.default'),
            'queue' => (string) $job->queue,
            'name' => self::jobName($job),
            'attempts' => (int) $job->attempts,
            'exception' => null,
            'created_at' => self::timestamp($job->created_at),
            'available_at' => self::timestamp($job->available_at),
            'reserved_at' => self::timestamp($job->reserved_at),
            'failed_at' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function presentFailed(object $job): array
    {
        $uuid = isset($job->uuid) && Str::isUuid((string) $job->uuid) ? (string) $job->uuid : null;
        $failedAt = $job->failed_at ?? null;

        return [
            'id' => (string) ($uuid ?? $job->id ?? ''),
            'uuid' => $uuid,
            'status' => 'failed',
            'connection' => (string) ($job->connection ?? ''),
            'queue' => (string) ($job->queue ?? ''),
            'name' => self::jobName($job),
            'attempts' => null,
            'exception' => Str::limit(trim(strtok((string) ($job->exception ?? ''), "\n") ?: ''), 300),
            'created_at' => null,
            'available_at' => null,
            'reserved_at' => null,
            'failed_at' => match (true) {
                $failedAt === null, $failedAt === '' => null,
                is_numeric($failedAt) => Carbon::createFromTimestampUTC((int) $failedAt)->toIso8601String(),
                default => Carbon::parse((string) $failedAt, 'UTC')->toIso8601String(),
            },
        ];
    }

    private static function timestamp(mixed $value): ?string
    {
        return $value === null ? null : Carbon::createFromTimestampUTC((int) $value)->toIso8601String();
    }

    private function isDatabaseQueue(): bool
    {
        return config('queue.connections.'.config('queue.default').'.driver') === 'database';
    }

    /** Jobs not picked up by a worker yet (including delayed ones). */
    private function waitingJobs(): Builder
    {
        return $this->jobsTable()->whereNull('reserved_at');
    }

    /** Jobs in the queue table with the given status (null: all of them). */
    private function queuedJobs(?string $status): Builder
    {
        $now = now()->getTimestamp();

        return match ($status) {
            'running' => $this->jobsTable()->whereNotNull('reserved_at'),
            'pending' => $this->waitingJobs()->where('available_at', '<=', $now),
            'delayed' => $this->waitingJobs()->where('available_at', '>', $now),
            default => $this->jobsTable(),
        };
    }

    private function jobsTable(): Builder
    {
        $config = config('queue.connections.'.config('queue.default'));

        return DB::connection($config['connection'] ?? null)
            ->table((string) ($config['table'] ?? 'jobs'));
    }
}
