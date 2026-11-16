<?php

namespace App\Services\System;

use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Links from activity log entries to the admin pages that describe their
 * subjects, for one viewer.
 *
 * An entry gets a link only if the subject still exists (deleted users and
 * roles get none) and the viewer may open the page. A subject type can also
 * link to a list (model null), e.g. for records without their own page.
 */
class ActivitySubjectLinks
{
    public function __construct(private readonly AdminLinks $links) {}

    /**
     * @param  Collection<int, ActivityLog>  $logs
     * @return array<int, string> entry id → URL
     */
    public function for(Collection $logs, User $viewer): array
    {
        $types = $this->types();
        $existing = $this->existingIds($logs, $types);

        $urls = [];
        foreach ($logs as $log) {
            $type = $types[$log->subject_type ?? ''] ?? null;

            if ($type === null) {
                continue;
            }

            if ($type['model'] !== null && ! $existing[$log->subject_type]->has($log->subject_id)) {
                continue;
            }

            $url = $type['url']($log, $viewer);

            if ($url !== null) {
                $urls[$log->id] = $url;
            }
        }

        return $urls;
    }

    /**
     * Subject types with links: the model whose row must exist (null when the
     * link leads to a list) and the URL for the viewer (null without access).
     * Roles are logged under the spatie class, see App\Models\Role::getMorphClass().
     *
     * @return array<string, array{model: class-string<Model>|null, url: Closure(ActivityLog, User): ?string}>
     */
    private function types(): array
    {
        return [
            User::class => [
                'model' => User::class,
                'url' => fn (ActivityLog $log, User $viewer) => $viewer->can('users.view')
                    ? $this->links->route('admin.users.show', $log->subject_id)
                    : null,
            ],
            SpatieRole::class => [
                'model' => Role::class,
                'url' => fn (ActivityLog $log, User $viewer) => $viewer->can('roles.view')
                    ? $this->links->route('admin.roles.show', $log->subject_id)
                    : null,
            ],
            Media::class => [
                'model' => Media::class,
                'url' => fn (ActivityLog $log, User $viewer) => $viewer->can('media.view')
                    ? $this->links->route('admin.media.index', ['search' => $log->subject_label])
                    : null,
            ],
        ];
    }

    /**
     * Ids of subjects that still exist: one query per type found in $logs.
     *
     * @param  Collection<int, ActivityLog>  $logs
     * @param  array<string, array{model: class-string<Model>|null, url: Closure}>  $types
     * @return array<string, Collection<int, int>> type → existing ids as keys
     */
    private function existingIds(Collection $logs, array $types): array
    {
        $existing = [];
        foreach ($types as $type => ['model' => $model]) {
            if ($model === null) {
                continue;
            }

            $ids = $logs->where('subject_type', $type)->pluck('subject_id')->filter()->unique();

            $existing[$type] = $ids->isEmpty()
                ? collect()
                : $model::query()->whereIn('id', $ids)->pluck('id')->flip();
        }

        return $existing;
    }
}
