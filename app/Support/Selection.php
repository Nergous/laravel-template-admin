<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Records a bulk action works on: the listed ids or every record matching the
 * list filters (all=1). Created in App\Http\Requests\SelectionRequest; the list
 * service turns it into a query with its own filter.
 */
final class Selection
{
    /** How many records each() loads at a time. */
    private const CHUNK = 200;

    /**
     * @param  list<int>|null  $ids  null — every record matching $filters
     * @param  array<string, mixed>  $filters  List filters in the form the list service reads them
     */
    private function __construct(
        public readonly ?array $ids,
        public readonly array $filters,
    ) {}

    /** @param  array<int|string>  $ids */
    public static function ids(array $ids): self
    {
        return new self(array_values(array_unique(array_map('intval', $ids))), []);
    }

    /** @param  array<string, mixed>  $filters */
    public static function matching(array $filters): self
    {
        return new self(null, $filters);
    }

    /**
     * The selected records of $query: the listed ids or the records $filter
     * keeps by the selection filters.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  Closure(Builder<TModel>, array<string, mixed>): Builder<TModel>  $filter
     * @return Builder<TModel>
     */
    public function query(Builder $query, Closure $filter): Builder
    {
        return $this->ids === null ? $filter($query, $this->filters) : $query->whereKey($this->ids);
    }

    /**
     * Runs $step for each selected record one model at a time (events and the
     * log fire for each) in one transaction. Chunks go by id, so records that
     * drop out of the filter during the action do not shift pages. $step
     * returns true — processed, false — skipped, null — nothing to do (not
     * counted).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  The query with the relations and counts $step needs
     * @param  Closure(Builder<TModel>, array<string, mixed>): Builder<TModel>  $filter
     * @param  Closure(TModel): ?bool  $step
     * @return array{processed: int, skipped: int}
     */
    public function each(Builder $query, Closure $filter, Closure $step): array
    {
        $result = ['processed' => 0, 'skipped' => 0];
        $selected = $this->query($query, $filter);

        $selected->getConnection()->transaction(function () use ($selected, $step, &$result) {
            $selected->chunkById(self::CHUNK, function ($models) use ($step, &$result) {
                foreach ($models as $model) {
                    match ($step($model)) {
                        true => $result['processed']++,
                        false => $result['skipped']++,
                        null => null,
                    };
                }
            });
        });

        return $result;
    }
}
