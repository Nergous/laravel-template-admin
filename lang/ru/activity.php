<?php

/**
 * Локализованные подписи журнала действий (ActivityLog).
 *
 * Ключи action соответствуют значениям колонки activity_log.action,
 * subjects — короткие ключи типов субъектов (см. ActivityLog::subjectTypeLabel).
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
