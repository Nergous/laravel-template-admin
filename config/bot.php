<?php

return [
    /*
     | Whether the bot module is enabled. Enabled by ANY of these variables:
     |  - BOT_ACTIVE=true                  (app layer; local run without Docker)
     |  - COMPOSE_PROFILES contains "bot"  (Docker: the same variable brings up the container)
    */
    'enabled' => env('BOT_ACTIVE', false) || in_array('bot', array_filter(explode(',', (string) env('COMPOSE_PROFILES', '')))),

    // Path to the shared JSON message registry (the source of truth, also read by the bot).
    'registry' => base_path('modules/max-bot/messages.json'),
];
