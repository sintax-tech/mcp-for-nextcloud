<?php
declare(strict_types=1);

return ['routes' => [
    ['name' => 'mcp#post', 'url' => '/', 'verb' => 'POST'],
    ['name' => 'mcp#get', 'url' => '/', 'verb' => 'GET'],
    ['name' => 'mcp#delete', 'url' => '/', 'verb' => 'DELETE'],
    // The token in the path is the credential, so these are public and CSRF-free on purpose.
    ['name' => 'checkout#download', 'url' => '/checkout/{token}', 'verb' => 'GET'],
    ['name' => 'checkout#upload', 'url' => '/checkout/{token}', 'verb' => 'PUT'],
    // curl -T sends PUT, but several HTTP clients use POST for the same body.
    ['name' => 'checkout#uploadPost', 'url' => '/checkout/{token}', 'verb' => 'POST'],
    ['name' => 'settings#personal', 'url' => '/settings/personal', 'verb' => 'POST'],
    ['name' => 'grants#index', 'url' => '/api/grants', 'verb' => 'GET'],
    // POST keeps "bulk" from colliding with a user whose uid is "bulk" on PUT /api/grants/{uid}.
    ['name' => 'grants#bulk', 'url' => '/api/grants/bulk', 'verb' => 'POST'],
    ['name' => 'grants#update', 'url' => '/api/grants/{uid}', 'verb' => 'PUT'],
    ['name' => 'grants#service', 'url' => '/api/service', 'verb' => 'PUT'],
    ['name' => 'grants#oauthClients', 'url' => '/api/oauth-clients', 'verb' => 'GET'],
    ['name' => 'grants#updateOauthClients', 'url' => '/api/oauth-clients', 'verb' => 'PUT'],
    ['name' => 'tags#index', 'url' => '/api/admin/tags', 'verb' => 'GET'],
    ['name' => 'tags#update', 'url' => '/api/admin/tags', 'verb' => 'PUT'],
    ['name' => 'metadata#protectedResource', 'url' => '/.well-known/oauth-protected-resource', 'verb' => 'GET'],
    ['name' => 'metadata#authorizationServer', 'url' => '/.well-known/openid-configuration', 'verb' => 'GET'],
    ['name' => 'metadata#oauthServer', 'url' => '/.well-known/oauth-authorization-server', 'verb' => 'GET'],
    ['name' => 'metadata#jwks', 'url' => '/.well-known/jwks.json', 'verb' => 'GET'],
    ['name' => 'o_auth#authorize', 'url' => '/oauth/authorize', 'verb' => 'GET'],
    ['name' => 'o_auth#consent', 'url' => '/oauth/authorize', 'verb' => 'POST'],
    ['name' => 'o_auth#token', 'url' => '/oauth/token', 'verb' => 'POST'],
]];
