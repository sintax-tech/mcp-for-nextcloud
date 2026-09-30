<?php
declare(strict_types=1);

return ['routes' => [
    ['name' => 'mcp#post', 'url' => '/', 'verb' => 'POST'],
    ['name' => 'mcp#get', 'url' => '/', 'verb' => 'GET'],
    ['name' => 'mcp#delete', 'url' => '/', 'verb' => 'DELETE'],
    ['name' => 'settings#global', 'url' => '/settings/global', 'verb' => 'POST'],
    ['name' => 'settings#user', 'url' => '/settings/user', 'verb' => 'POST'],
    ['name' => 'settings#personal', 'url' => '/settings/personal', 'verb' => 'POST'],
    ['name' => 'users#index', 'url' => '/api/users', 'verb' => 'GET'],
    ['name' => 'metadata#protectedResource', 'url' => '/.well-known/oauth-protected-resource', 'verb' => 'GET'],
    ['name' => 'metadata#authorizationServer', 'url' => '/.well-known/openid-configuration', 'verb' => 'GET'],
    ['name' => 'metadata#oauthServer', 'url' => '/.well-known/oauth-authorization-server', 'verb' => 'GET'],
    ['name' => 'metadata#jwks', 'url' => '/.well-known/jwks.json', 'verb' => 'GET'],
    ['name' => 'o_auth#authorize', 'url' => '/oauth/authorize', 'verb' => 'GET'],
    ['name' => 'o_auth#consent', 'url' => '/oauth/authorize', 'verb' => 'POST'],
    ['name' => 'o_auth#token', 'url' => '/oauth/token', 'verb' => 'POST'],
]];
