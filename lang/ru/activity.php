<?php

/**
 * Localized labels for the activity log (ActivityLog).
 *
 * action keys correspond to the values of the activity_log.action column,
 * subjects — short keys for subject types (see ActivityLog::subjectTypeLabel).
 */
return [
    'actions' => [
        'created' => 'Создано',
        'updated' => 'Изменено',
        'deleted' => 'Удалено',
        'force_deleted' => 'Удалено навсегда',
        'restored' => 'Восстановлено',
        'duplicated' => 'Дублировано',
    ],

    'subjects' => [
        'user' => 'Пользователь',
        'media' => 'Медиа',
        'role' => 'Роль',
        'permission' => 'Разрешение',
    ],
];
