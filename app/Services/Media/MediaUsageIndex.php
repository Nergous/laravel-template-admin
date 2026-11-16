<?php

namespace App\Services\Media;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;

/**
 * Index of media links in text fields (MediaReferenceRegistry::TEXT_FIELDS):
 * filename => records whose text links to the file.
 *
 * Building it reads every registered text column, so the result is cached.
 * MediaTextObserver clears the cache when a registered model is saved or
 * deleted (and once more after the commit); MediaReferenceUpdater does so
 * after rewriting rows directly. Writes bypassing both clear it explicitly
 * (call forget()); app:db-restore clears the whole default cache store
 * (where the index lives). The TTL is the last safety net.
 * Decisions that must not rely on a stale index (the deletion guard) use
 * fresh().
 *
 * Scoped: one in-memory copy per request or job.
 */
#[Scoped]
class MediaUsageIndex
{
    private const CACHE_KEY = 'media.usage-index';

    /** Seconds the cached index lives without an invalidating write. */
    private const TTL = 3600;

    /** @var array<string, list<array{0: string, 1: int, 2: string}>>|null */
    private ?array $map = null;

    public function __construct(
        private readonly Cache $cache,
        private readonly MediaUrls $urls,
    ) {}

    /**
     * The index from the cache.
     *
     * @return array<string, list<array{0: string, 1: int, 2: string}>> filename => [owner, owner id, label]
     */
    public function map(): array
    {
        return $this->map ??= $this->cache->remember(self::CACHE_KEY, self::TTL, fn () => $this->build());
    }

    /**
     * The index built from the database right now. Outside a transaction the
     * result replaces the cached one; inside one, uncommitted rows never reach the cache.
     *
     * @return array<string, list<array{0: string, 1: int, 2: string}>>
     */
    public function fresh(): array
    {
        $this->map = $this->build();

        if (DB::transactionLevel() === 0) {
            $this->cache->put(self::CACHE_KEY, $this->map, self::TTL);
        }

        return $this->map;
    }

    /** Clears the cached index; the next map() rebuilds it. */
    public function forget(): void
    {
        $this->map = null;
        $this->cache->forget(self::CACHE_KEY);
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
                    foreach ($this->urls->filenames($row->{$column}) as $filename) {
                        $found[$filename][$field['owner'].':'.$ownerId.':'.$field['label']] = [$field['owner'], $ownerId, $field['label']];
                    }
                }
            }
        }

        return array_map('array_values', $found);
    }
}
