<?php

/**
 * Localized labels for the permissions matrix (AdminPermissionController).
 *
 * resources — the permission-name prefix before the dot (users.view → users),
 * actions — the suffix after the dot (users.view → view).
 */
return [
    'resources' => [
        'users' => 'Пользователи',
        'roles' => 'Роли',
        'permissions' => 'Разрешения',
        'media' => 'Медиатека',
        'activity-log' => 'Журнал действий',
        'settings' => 'Настройки',
        'backups' => 'Резервные копии',
        'queue' => 'Очередь задач',
        'other' => 'Прочее',
    ],

    'actions' => [
        'view' => 'Просмотр',
        'create' => 'Создание',
        'edit' => 'Изменение',
        'update' => 'Изменение',
        'delete' => 'Удаление',
        'upload' => 'Загрузка',
        'manage' => 'Управление',
        'download' => 'Скачивание',
        'export' => 'Экспорт',
        'impersonate' => 'Вход от имени',
    ],
];
