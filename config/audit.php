<?php

use App\Models\Media;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return [
    /*
    |--------------------------------------------------------------------------
    | Activity log retention (activity_log)
    |--------------------------------------------------------------------------
    |
    | Records older than the given number of days are deleted by the daily
    | model:prune task (see routes/console.php). A value <= 0 disables the
    | cleanup — the log grows without bound (a deliberate opt-out).
    |
    */
    'retention_days' => (int) env('ACTIVITY_LOG_RETENTION_DAYS', 180),

    /*
    |--------------------------------------------------------------------------
    | Log subject type labels (finding A4)
    |--------------------------------------------------------------------------
    |
    | A map of "model class → short translation key" (the strings live in
    | lang/<locale>/activity.php, the subjects section). A data-driven registry
    | instead of a hardcoded match in the model: to add your own entity to the log,
    | register it here and add a translation — no need to touch the ActivityLog model.
    |
    | subject_type is stored in the DB as an FQCN (getMorphClass without a morph-map),
    | so the map keys are fully qualified class names.
    |
    */
    'subjects' => [
        User::class => 'user',
        Media::class => 'media',
        Role::class => 'role',
        Permission::class => 'permission',
    ],
];
