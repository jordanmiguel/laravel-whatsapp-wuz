<?php

return [
    'enabled' => env('WUZ_ENABLED', false),
    'api_url' => env('WUZ_API_URL', 'http://localhost:8080'),
    'admin_token' => env('WUZ_ADMIN_TOKEN'),

    'webhook' => [
        'path' => 'api/wuz/webhook/{token}',
        'middleware' => [],
    ],

    'logging' => [
        'event_types' => \JordanMiguel\Wuz\Enums\WuzEventType::defaultLoggingTypes(),
    ],

    'webhook_event' => [
        'event_types' => \JordanMiguel\Wuz\Enums\WuzEventType::defaultDispatchTypes(),
    ],

    'debug' => [
        'enabled' => env('WUZ_DEBUG', false),
        'to' => env('WUZ_DEBUG_TO'),
    ],

    'phone' => [
        'default_country_code' => env('WUZ_DEFAULT_COUNTRY_CODE', '55'),

        // How long a "not on WhatsApp" result stays cached before it is re-checked.
        // Prevents repeated /user/lid 404 lookups for the same unregistered number.
        'unregistered_ttl_days' => env('WUZ_UNREGISTERED_TTL_DAYS', 14),
    ],

    'table_names' => [
        'devices' => 'wuz_devices',
        'callback_logs' => 'wuz_callback_logs',
        'device_webhooks' => 'wuz_device_webhooks',
        'phone_jids' => 'wuz_phone_jids',
    ],

    'proxy' => [
        // When setting a per-device proxy fails during reconnect, connect directly
        // (no proxy) instead of staying disconnected. Off by default because a direct
        // connect exposes the server IP and can get the WhatsApp account banned.
        'connect_directly_on_failure' => env('WUZ_PROXY_CONNECT_DIRECTLY_ON_FAILURE', false),
    ],
];
