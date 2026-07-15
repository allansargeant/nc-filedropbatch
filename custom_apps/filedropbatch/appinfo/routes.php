<?php

declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'batch#process', 'url' => '/batch', 'verb' => 'POST'],
    ],
];
