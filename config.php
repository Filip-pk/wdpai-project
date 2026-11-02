<?php

declare(strict_types=1);

return [
    'host' => getenv('POSTGRES_HOST'),
    'port' => getenv('POSTGRES_PORT'),
    'database' => getenv('POSTGRES_DB'),
    'username' => getenv('POSTGRES_USER'),
    'password' => getenv('POSTGRES_PASSWORD'),
    'mail' => [
        'host' => getenv('MAIL_HOST') ?: 'mailpit',
        'port' => (int) (getenv('MAIL_PORT') ?: 1025),
        'from' => getenv('MAIL_FROM') ?: 'noreply@anivibe.local',
        'fromName' => getenv('MAIL_FROM_NAME') ?: 'AniVibe',
        'timeout' => 5,
    ],
];
