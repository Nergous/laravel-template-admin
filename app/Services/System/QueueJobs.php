<?php

namespace App\Services\System;

use App\Enums\QueueJobStatus;
use App\Support\JobPayload;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The single job list of the /admin/queue page: queued jobs first (in queue
 * order), then failed ones (newest first). Rows of both kinds are brought to
 * one shape.
 */
class QueueJobs
{
    public function __construct(
        private readonly QueueTable $table,
        private readonly QueueStats $stats,
        private readonly FailedJobProviderInterface $failer,
    ) {}

    /**
     * A list page. The status keeps one kind of jobs; null — all.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function page(?QueueJobStatus $status, int $page, int $perPage = 20): LengthAwarePaginator
    {
        $offset = ($page - 1) * $perPage;
        $rows = collect();
        $total = 0;

        if ($status !== QueueJobStatus::Failed && $this->table->isDatabase()) {
            $queued = $this->table->queued($status);
            $queuedTotal = (clone $queued)->count();
            $connection = $this->table->connection()['connection'];

            $rows = $queued
                ->select(['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'])
                ->orderBy('available_at')
                ->orderBy('id')
                ->offset($offset)
                ->limit($perPage)
                ->get()
                ->map(fn (object $job): array => $this->presentQueued($job, $connection));

            $total += $queuedTotal;
            // Failed jobs continue the list after the last queued job.
            $offset = max(0, $offset - $queuedTotal);
        }

        if ($status === null || $status === QueueJobStatus::Failed) {
            $total += $this->stats->failedCount();
            $limit = $perPage - $rows->count();

            if ($limit > 0) {
                $rows = $rows->concat($this->failedSlice($offset, $limit));
            }
        }

        return new LengthAwarePaginator($rows->values(), $total, $perPage, $page);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function failedSlice(int $offset, int $limit): Collection
    {
        if ($this->failer instanceof DatabaseUuidFailedJobProvider) {
            // getTable() returns a ready query on the failed jobs table and its connection.
            return $this->failer->getTable()
                ->orderByDesc('id')
                ->offset($offset)
                ->limit($limit)
                ->get()
                ->map(fn (object $job): array => $this->presentFailed($job));
        }

        // Other drivers (file, dynamodb, null) only support all().
        return collect($this->failer->all())
            ->slice($offset, $limit)
            ->map(fn (object $job): array => $this->presentFailed($job))
            ->values();
    }

    /** @return array<string, mixed> */
    private function presentQueued(object $job, string $connection): array
    {
        $status = match (true) {
            $job->reserved_at !== null => QueueJobStatus::Running,
            (int) $job->available_at > now()->getTimestamp() => QueueJobStatus::Delayed,
            default => QueueJobStatus::Pending,
        };

        return [
            'id' => 'job-'.$job->id,
            'uuid' => null,
            'status' => $status->value,
            'connection' => $connection,
            'queue' => (string) $job->queue,
            'name' => JobPayload::displayName($job),
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
            'status' => QueueJobStatus::Failed->value,
            'connection' => (string) ($job->connection ?? ''),
            'queue' => (string) ($job->queue ?? ''),
            'name' => JobPayload::displayName($job),
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
}
