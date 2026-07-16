<?php

declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'batch#process', 'url' => '/batch', 'verb' => 'POST'],
        ['name' => 'batch#processManual', 'url' => '/batch/manual', 'verb' => 'POST'],
        ['name' => 'admin_settings#save', 'url' => '/admin/settings', 'verb' => 'POST'],
        ['name' => 'admin_settings#syncNow', 'url' => '/admin/sync-now', 'verb' => 'POST'],
        ['name' => 'admin_settings#saveGoogle', 'url' => '/admin/google-settings', 'verb' => 'POST'],
        ['name' => 'google_auth#connect', 'url' => '/google/connect', 'verb' => 'GET'],
        ['name' => 'google_auth#callback', 'url' => '/google/callback', 'verb' => 'GET'],
        ['name' => 'google_auth#disconnect', 'url' => '/google/disconnect', 'verb' => 'POST'],
        ['name' => 'session#index', 'url' => '/sessions', 'verb' => 'GET'],
        ['name' => 'session#create', 'url' => '/sessions', 'verb' => 'POST'],
        ['name' => 'session#update', 'url' => '/sessions/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
        ['name' => 'session#close', 'url' => '/sessions/{id}/close', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        ['name' => 'session#destroy', 'url' => '/sessions/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
        ['name' => 'sheet#index', 'url' => '/sheets', 'verb' => 'GET'],
        ['name' => 'sheet#create', 'url' => '/sheets', 'verb' => 'POST'],
        ['name' => 'sheet#update', 'url' => '/sheets/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
        ['name' => 'sheet#destroy', 'url' => '/sheets/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
        ['name' => 'sheet#syncNow', 'url' => '/sheets/{id}/sync-now', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
    ],
];
