<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Simple substring search over several columns via LIKE.
 *
 * The model lists the columns in protected array $searchable and scopeSearch()
 * searches them. When the search needs its own logic (e.g. "#12" looking up
 * by id), the model overrides scopeSearch() and calls searchLike().
 *
 * The meaning is "the column contains the substring". Case sensitivity follows
 * the DBMS collation (MySQL and SQLite are usually case-insensitive for ASCII,
 * Postgres is case-sensitive). Special characters in the query are escaped, so
 * "50%" is searched literally, not as a pattern.
 *
 * Scale: LIKE '%...%' cannot use a B-tree index. If search becomes a
 * bottleneck, on PostgreSQL enable the pg_trgm extension and a GIN index
 * (gin_trgm_ops) for ILIKE, on MySQL a FULLTEXT index (MATCH … AGAINST).
 *
 * Caution: these are not equivalent replacements. Only pg_trgm keeps the
 * "contains a substring" meaning. FULLTEXT/MATCH AGAINST searches words, not
 * substrings: it does not find the middle of a string, has a minimum word
 * length (InnoDB innodb_ft_min_token_size = 3, so 2-character queries find
 * nothing) and breaks email search (@ and . are word separators). SQLite
 * supports neither, so search stays on LIKE there. Enable them only for a
 * specific DBMS and after revisiting the search UI.
 */
trait HasSearch
{
    /**
     * Substring search over the model's $searchable columns.
     *
     * @param  string|null  $search  Search string (empty leaves the query unchanged)
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (! property_exists($this, 'searchable')) {
            throw new \LogicException(static::class.' использует HasSearch, но не задаёт $searchable.');
        }

        return $this->scopeSearchLike($query, $search, $this->searchable);
    }

    /**
     * Selects records where any of the columns contains the substring.
     *
     * @param  string|null  $search  Search string (empty leaves the query unchanged)
     * @param  string[]  $columns  Columns to search
     */
    public function scopeSearchLike(Builder $query, ?string $search, array $columns): Builder
    {
        if (blank($search)) {
            return $query;
        }

        // LIKE special characters are escaped so that "%" and "_" match literally.
        // An explicit ESCAPE is required (SQLite has no default escape
        // character); "!" needs no escaping in any SQL dialect, unlike the
        // backslash.
        $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($search)).'%';

        $grammar = $query->getQuery()->getGrammar();

        return $query->where(function (Builder $q) use ($term, $columns, $grammar) {
            foreach ($columns as $column) {
                $q->orWhereRaw($grammar->wrap($column)." LIKE ? ESCAPE '!'", [$term]);
            }
        });
    }
}
