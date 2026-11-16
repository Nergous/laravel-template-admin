<?php

use App\Models\Media;
use App\Models\User;
use Spatie\Permission\Models\Role;

/*
| Activity log (the activity_log table, model App\Models\ActivityLog).
*/

return [
    /*
    | Retention in days. The daily model:prune task (routes/console.php) deletes
    | older entries. A value <= 0 disables pruning and the log grows unbounded.
    */

    'retention_days' => (int) env('ACTIVITY_LOG_RETENTION_DAYS', 180),

    /*
    | Log subject types: "model class → translation key" from lang/<locale>/activity.php
    | (the subjects section). App\Services\System\ActivityLogLabels labels entries and
    | builds the type filter from this map. To show a new entity in the log with a
    | label, add it here and add the translation.
    |
    | subject_type is stored as the full class name (getMorphClass without a morph map),
    | so the keys are full class names. Roles are logged under the spatie class:
    | App\Models\Role returns it from getMorphClass() so old entries keep their type.
    */

    'subjects' => [
        User::class => 'user',
        Media::class => 'media',
        Role::class => 'role',
    ],
];
