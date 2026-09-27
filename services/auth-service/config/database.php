<?php

return [
    'default' => env('DB_CONNECTION', 'pgsql'),
    'connections' => ['pgsql' => [
        'driver' => 'pgsql', 'host' => env('DB_HOST', 'auth-db'), 'port' => env('DB_PORT', '5432'),
        'database' => env('DB_DATABASE', 'auth_db'), 'username' => env('DB_USERNAME', 'auth_app'),
        'password' => env('DB_PASSWORD'), 'charset' => 'utf8', 'prefix' => '', 'prefix_indexes' => true,
        'search_path' => 'public', 'sslmode' => env('DB_SSLMODE', 'prefer'),
    ]],
    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
];
