<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Links from activity log entries to the admin pages of the entities they
 * describe; shared by the log page and the notification bell.
 */
trait LinksActivitySubjects
{
    /**
     * Entities that no longer exist (deleted users, removed roles) get no link.
     *
     * @param  Collection<int, ActivityLog>  $logs
     * @return array<int, string> log id → URL
     */
    private function subjectUrls(Collection $logs): array
    {
        $idsOf = fn (string $type) => $logs->where('subject_type', $type)->pluck('subject_id')->filter()->unique()->all();

        $users = User::whereIn('id', $idsOf(User::class))->pluck('id')->flip();
        $roles = Role::whereIn('id', $idsOf(SpatieRole::class))->pluck('id')->flip();
        $media = Media::whereIn('id', $idsOf(Media::class))->pluck('id')->flip();

        $urls = [];
        foreach ($logs as $log) {
            $url = match (true) {
                $log->subject_type === User::class && $users->has($log->subject_id) => route('admin.users.show', $log->subject_id),
                $log->subject_type === SpatieRole::class && $roles->has($log->subject_id) => route('admin.roles.show', $log->subject_id),
                $log->subject_type === Media::class && $media->has($log->subject_id) => route('admin.media.index', ['search' => $log->subject_label]),
                $log->subject_type === Permission::class => route('admin.permissions.index'),
                default => null,
            };

            if ($url !== null) {
                $urls[$log->id] = $url;
            }
        }

        return $urls;
    }
}
