<?php

namespace App\Providers;

use App\Support\MediaReferenceRegistry;
use App\Support\MediaUsageIndex;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

/**
 * Media library wiring: the text usage index (one instance per request or
 * job) and its invalidation when a model with media-bearing text changes.
 */
class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(MediaUsageIndex::class);
    }

    public function boot(): void
    {
        // Dropped right away and again after the commit: a request that
        // rebuilds the index mid-transaction would otherwise cache old rows.
        $forget = function (): void {
            $index = $this->app->make(MediaUsageIndex::class);
            $index->forget();
            DB::afterCommit(fn () => $index->forget());
        };

        /** @var list<class-string<Model>> $models */
        $models = array_values(array_unique(array_column(MediaReferenceRegistry::TEXT_FIELDS, 'model')));

        foreach ($models as $model) {
            $model::saved($forget);
            $model::deleted($forget);
        }
    }
}
