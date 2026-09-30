<?php
declare(strict_types=1);

return ['routes' => [
    ['name' => 'mcp#post', 'url' => '/', 'verb' => 'POST'],
    ['name' => 'mcp#get', 'url' => '/', 'verb' => 'GET'],
    ['name' => 'mcp#delete', 'url' => '/', 'verb' => 'DELETE'],
    ['name' => 'settings#global', 'url' => '/settings/global', 'verb' => 'POST'],
    ['name' => 'settings#user', 'url' => '/settings/user', 'verb' => 'POST'],
    ['name' => 'settings#personal', 'url' => '/settings/personal', 'verb' => 'POST'],
]];
