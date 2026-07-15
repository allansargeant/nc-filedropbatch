<?php

declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'batch#process', 'url' => '/batch', 'verb' => 'POST'],
        ['name' => 'admin_settings#save', 'url' => '/admin/settings', 'verb' => 'POST'],
        ['name' => 'admin_settings#syncNow', 'url' => '/admin/sync-now', 'verb' => 'POST'],
        ['name' => 'session#index', 'url' => '/sessions', 'verb' => 'GET'],
        ['name' => 'session#create', 'url' => '/sessions', 'verb' => 'POST'],
        ['name' => 'session#update', 'url' => '/sessions/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
        ['name' => 'session#close', 'url' => '/sessions/{id}/close', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        ['name' => 'session#destroy', 'url' => '/sessions/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
    ],
];
