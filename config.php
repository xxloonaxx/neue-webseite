<?php

declare(strict_types=1);

return [
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => getenv('DB_PORT') ?: '3306',
    'database' => getenv('DB_NAME') ?: 'budget_planner',
    'username' => getenv('DB_USER') ?: 'budget_user',
    'password' => getenv('DB_PASSWORD') ?: 'budget_password',
    'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
];
