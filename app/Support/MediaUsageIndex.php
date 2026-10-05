<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Index of media links inside text fields (MediaReferenceRegistry::TEXT_FIELDS):
 * filename => the records whose text links the file.
 *
 * Building it reads every registered text column once, so the result is
 * cached. MediaServiceProvider drops the cache when a registered model is
 * saved or deleted (again after commit); MediaReferenceUpdater drops it after
 * rewriting rows directly. Writes that bypass both drop it explicitly: the
 * content importer calls forget() after a run, and app:db-restore clears the
 * whole default cache store (where this index lives). The TTL is the last
 * resort. Decisions that must not act on a stale index — the deletion guard —
 * use fresh().
 *
 * Registered as a scoped instance: one in-memory copy per request or job.
 */
class MediaUsageIndex
{
    private const CACHE_KEY = 'media.usage-index';

    /** Seconds a cached index lives without an invalidating write. */
    private const TTL = 3600;

    /** @var array<string, list<array{0: string, 1: int, 2: string}>>|null */
    private ?array $map = null;

    /**
     * The cached index.
     *
     * @return array<string, list<array{0: string, 1: int, 2: string}>> filename => [owner, owner id, label]
     */
    public function map(): array
    {
        return $this->map ??= Cache::remember(self::CACHE_KEY, self::TTL, fn () => $this->build());
    }

    /**
     * The index rebuilt from the database right now. Outside a transaction the
     * result replaces the cached one; inside, uncommitted rows stay out of the cache.
     *
     * @return array<string, list<array{0: string, 1: int, 2: string}>>
     */
    public function fresh(): array
    {
        $this->map = $this->build();

        if (DB::transactionLevel() === 0) {
            Cache::put(self::CACHE_KEY, $this->map, self::TTL);
        }

        return $this->map;
    }

    /** Drops the cached index; the next map() rebuilds it. */
    public function forget(): void
    {
        $this->map = null;
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, list<array{0: string, 1: int, 2: string}>> */
    private function build(): array
    {
        $found = [];

        foreach (MediaReferenceRegistry::TEXT_FIELDS as $field) {
            $columns = $field['columns'];
            $rows = DB::table($field['table'])
                ->select(array_values(array_unique([$field['owner_key'], ...$columns])))
                ->where(function ($query) use ($columns) {
                    foreach ($columns as $column) {
                        $query->orWhere($column, 'like', '%/%');
                    }
                })
                ->cursor();

            foreach ($rows as $row) {
                $ownerId = (int) $row->{$field['owner_key']};
                foreach ($columns as $column) {
                    foreach (MediaReferenceRegistry::filenames($row->{$column}) as $filename) {
                        $found[$filename][$field['owner'].':'.$ownerId.':'.$field['label']] = [$field['owner'], $ownerId, $field['label']];
                    }
                }
            }
        }

        return array_map('array_values', $found);
    }
}
