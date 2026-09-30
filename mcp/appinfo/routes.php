<?php
declare(strict_types=1);

return ['routes' => [
    ['name' => 'mcp#post', 'url' => '/', 'verb' => 'POST'],
    ['name' => 'mcp#get', 'url' => '/', 'verb' => 'GET'],
    ['name' => 'mcp#delete', 'url' => '/', 'verb' => 'DELETE'],
    ['name' => 'settings#personal', 'url' => '/settings/personal', 'verb' => 'POST'],
    ['name' => 'grants#index', 'url' => '/api/grants', 'verb' => 'GET'],
    // POST keeps "bulk" from colliding with a user whose uid is "bulk" on PUT /api/grants/{uid}.
    ['name' => 'grants#bulk', 'url' => '/api/grants/bulk', 'verb' => 'POST'],
    ['name' => 'grants#update', 'url' => '/api/grants/{uid}', 'verb' => 'PUT'],
    ['name' => 'grants#service', 'url' => '/api/service', 'verb' => 'PUT'],
]];
