<?php

declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'batch#process', 'url' => '/batch', 'verb' => 'POST'],
        ['name' => 'admin_settings#save', 'url' => '/admin/settings', 'verb' => 'POST'],
        ['name' => 'admin_settings#syncNow', 'url' => '/admin/sync-now', 'verb' => 'POST'],
    ],
];
