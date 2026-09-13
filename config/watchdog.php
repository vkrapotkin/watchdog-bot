<?php

return [
    'id' => env('WATCHDOG_SITE_ID', 'site'),
    'name' => env('WATCHDOG_SITE_NAME', env('APP_NAME', 'Website')),
    'url' => env('WATCHDOG_URL', env('APP_URL')),
    'expected_text' => env('WATCHDOG_EXPECTED_TEXT', ''),
    'retry_seconds' => (int) env('WATCHDOG_RETRY_SECONDS', 30),
    'timeout_seconds' => (int) env('WATCHDOG_TIMEOUT_SECONDS', 15),
    'token' => env('WATCHDOG_TELEGRAM_BOT_TOKEN', ''),
    'telegram_proxy' => env('WATCHDOG_TELEGRAM_PROXY', ''),
    'chat_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('WATCHDOG_TELEGRAM_CHAT_IDS', ''))))),
];
