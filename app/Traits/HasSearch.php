<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

/**
 * Simple substring search across several columns via LIKE.
 *
 * The semantics are "the column contains the substring". Case sensitivity is
 * determined by the DBMS collation (MySQL/SQLite are usually case-insensitive
 * for ASCII, Postgres is case-sensitive). Wildcards in the query are escaped, so
 * "50%" is searched literally, not as a pattern.
 *
 * Attached to models via use HasSearch; the model declares the columns in its
 * scopeSearch(). Used in the models: User, Media.
 *
 * Scale: LIKE '%...%' does not use a B-tree index. If search becomes a
 * bottleneck — on PostgreSQL enable the pg_trgm extension + a GIN index
 * (gin_trgm_ops) for ILIKE; on MySQL — a FULLTEXT index (MATCH … AGAINST).
 *
 * WARNING: this is not a drop-in replacement. Only pg_trgm preserves the
 * "contains substring" semantics. FULLTEXT/MATCH AGAINST searches by word tokens,
 * not by substring: it doesn't find the middle of a string, has a minimum token
 * length (InnoDB innodb_ft_min_token_size = 3, i.e. 2-character queries return
 * nothing) and breaks email search (@ and . are token separators). SQLite
 * supports neither — there search must stay on LIKE. Enable only for a specific
 * single driver and with a rethink of the search UX.
 */
trait HasSearch
{
    /**
     * Filters records by a substring in any of the given columns.
     *
     * @param  string|null  $search  Search string (empty — query unchanged)
     * @param  string[]  $columns  Columns to search
     */
    public function scopeSearchLike(Builder $query, ?string $search, array $columns): Builder
    {
        if (blank($search)) {
            return $query;
        }

        // Escape LIKE special characters so that "%" and "_" are searched literally.
        $term = '%'.addcslashes(trim($search), '%_\\').'%';

        return $query->where(function (Builder $q) use ($term, $columns) {
            foreach ($columns as $column) {
                $q->orWhere($column, 'LIKE', $term);
            }
        });
    }
}
