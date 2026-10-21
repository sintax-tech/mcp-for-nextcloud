<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

return ['routes' => [
    ['name' => 'override_preview#index', 'url' => '/api/grants/commands/preview', 'verb' => 'POST'],
    ['name' => 'group_directory#index', 'url' => '/api/group-directory', 'verb' => 'GET'],
    ['name' => 'group_directory#detail', 'url' => '/api/group-directory/detail', 'verb' => 'GET'],
    ['name' => 'inspector#access', 'url' => '/api/inspector/access', 'verb' => 'GET'],
    ['name' => 'inspector#path', 'url' => '/api/inspector/path', 'verb' => 'GET'],
    ['name' => 'inspector#calendar', 'url' => '/api/inspector/calendar', 'verb' => 'GET'],
    ['name' => 'scope#show', 'url' => '/api/scope/{uid}/{module}', 'verb' => 'GET'],
    ['name' => 'scope#candidates', 'url' => '/api/scope/{uid}/{module}/candidates', 'verb' => 'GET'],
    ['name' => 'scope#update', 'url' => '/api/scope/{uid}/{module}', 'verb' => 'PUT'],
    ['name' => 'my_scope#index', 'url' => '/api/my/scope', 'verb' => 'GET'],
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
    ['name' => 'grants#commands', 'url' => '/api/grants/commands', 'verb' => 'POST'],
    ['name' => 'grants#undo', 'url' => '/api/grants/undo', 'verb' => 'POST'],
    ['name' => 'grants#group', 'url' => '/api/group-grants', 'verb' => 'GET'],
    ['name' => 'grants#previewGroup', 'url' => '/api/group-grants/preview', 'verb' => 'POST'],
    ['name' => 'grants#saveGroup', 'url' => '/api/group-grants', 'verb' => 'PUT'],
    ['name' => 'grants#bulk', 'url' => '/api/grants/bulk', 'verb' => 'POST'],
    ['name' => 'grants#update', 'url' => '/api/grants/{uid}', 'verb' => 'PUT'],
    ['name' => 'grants#service', 'url' => '/api/service', 'verb' => 'PUT'],
    ['name' => 'grants#oauthClients', 'url' => '/api/oauth-clients', 'verb' => 'GET'],
    ['name' => 'grants#updateOauthClients', 'url' => '/api/oauth-clients', 'verb' => 'PUT'],
    ['name' => 'grants#logsAccess', 'url' => '/api/logs-access', 'verb' => 'GET'],
    ['name' => 'grants#updateLogsAccess', 'url' => '/api/logs-access', 'verb' => 'PUT'],
    ['name' => 'logs_privacy#show', 'url' => '/api/logs-privacy', 'verb' => 'GET'],
    ['name' => 'logs_privacy#update', 'url' => '/api/logs-privacy', 'verb' => 'PUT'],
    ['name' => 'connections#index', 'url' => '/api/connections', 'verb' => 'GET'],
    // Two path segments, so a user id never collides with a numeric connection id.
    ['name' => 'connections#revokeUser', 'url' => '/api/connections/users/{uid}', 'verb' => 'DELETE'],
    ['name' => 'connections#destroy', 'url' => '/api/connections/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
    ['name' => 'my_connections#index', 'url' => '/api/my/connections', 'verb' => 'GET'],
    ['name' => 'my_connections#destroy', 'url' => '/api/my/connections/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
    ['name' => 'checkout_limit#show', 'url' => '/api/checkout-limit', 'verb' => 'GET'],
    ['name' => 'checkout_limit#update', 'url' => '/api/checkout-limit', 'verb' => 'PUT'],
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
