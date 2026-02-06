<?php

return [
    'enabled' => env('WUZ_ENABLED', false),
    'api_url' => env('WUZ_API_URL', 'http://localhost:8080'),
    'admin_token' => env('WUZ_ADMIN_TOKEN'),
    'download_media' => env('WUZ_DOWNLOAD_MEDIA', false),

    'webhook' => [
        'path' => 'api/wuz/webhook/{token}',
        'middleware' => [],
    ],

    'phone' => [
        'default_country_code' => env('WUZ_DEFAULT_COUNTRY_CODE', '55'),
    ],

    'table_names' => [
        'devices' => 'wuz_devices',
        'device_messages' => 'wuz_device_messages',
        'callback_logs' => 'wuz_callback_logs',
        'device_webhooks' => 'wuz_device_webhooks',
        'phone_jids' => 'wuz_phone_jids',
    ],
];
