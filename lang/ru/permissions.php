<?php

/**
 * Локализованные подписи матрицы разрешений (AdminPermissionController).
 *
 * resources — префикс имени права до точки (users.view → users),
 * actions — суффикс после точки (users.view → view).
 */
return [
    'resources' => [
        'users' => 'Пользователи',
        'roles' => 'Роли',
        'permissions' => 'Разрешения',
        'media' => 'Медиатека',
        'activity-log' => 'Журнал действий',
        'settings' => 'Настройки',
        'bot-messages' => 'Сообщения бота',
        'other' => 'Прочее',
    ],

    'actions' => [
        'view' => 'Просмотр',
        'create' => 'Создание',
        'edit' => 'Изменение',
        'update' => 'Изменение',
        'delete' => 'Удаление',
        'upload' => 'Загрузка',
    ],
];
