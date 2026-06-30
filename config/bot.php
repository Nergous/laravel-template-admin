<?php

return [
    /*
     | Включен ли модуль бота. Включается ЛЮБОЙ из переменных:
     |  - BOT_ACTIVE=true                 (app-слой; локальный запуск без Docker)
     |  - COMPOSE_PROFILES содержит "bot" (Docker: та же переменная поднимает контейнер)
    */
    'enabled' => env('BOT_ACTIVE', false) || in_array('bot', array_filter(explode(',', (string) env('COMPOSE_PROFILES', '')))),

    // Путь к общему JSON-реестру сообщений (источник правды, читается и ботом).
    'registry' => base_path('modules/max-bot/messages.json'),
];
