<?php

return [
    'email_enabled' => env('NOTIFICATIONS_EMAIL_ENABLED', false),
    'connection' => env('NOTIFICATIONS_QUEUE_CONNECTION', 'database'),
    'queue' => 'notifications',
    'lease_seconds' => 120,
    'backoff' => [60, 300, 900, 3600],
];
