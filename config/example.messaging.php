<?php

declare(strict_types=1);

return [
    'transport' => [
        'driver' => 'postgres',
        'dsn' => '${env:PG_MESSAGING_DSN}',
        'user' => '${env:PG_MESSAGING_USER}',
        'password' => '${env:PG_MESSAGING_PASS}',
        'channel' => 'blackcat_messages',
    ],
    'scheduler' => [
        'driver' => 'postgres',
        'dsn' => '${env:PG_MESSAGING_DSN}',
        'user' => '${env:PG_MESSAGING_USER}',
        'password' => '${env:PG_MESSAGING_PASS}',
    ],
    'storage_dir' => __DIR__ . '/../var',
];
