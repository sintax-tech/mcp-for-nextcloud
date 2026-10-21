<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

use OCP\{Server, IDBConnection, IUserManager, IConfig};
use OCA\Mcp\Db\{PolicyStateMapper, OAuthClientsMapper};
use OCA\Mcp\OAuth\{OAuthClientsService, CustomClient, AuthorizationRequest, TokenService};

if (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['seed', 'verify'], true)) exit(2);
define('OC_CONSOLE', 1);
require_once __DIR__ . '/../Support/ProbeCompletion.php';
$probeCompletion = new \OCA\Mcp\Tests\Integration\Support\ProbeCompletion();
require_once '/var/www/html/lib/base.php';
Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');
$uid = 'mcp-it-replacement';
$path = '/tmp/mcp-it-replacement.json';
$db = Server::get(IDBConnection::class);
function snapshot(IDBConnection $db, string $uid): array {
    $tables = ['migrations', 'mcp_policy_state', 'mcp_policy_events', 'mcp_auth_users', 'mcp_auth_recheck',
        'mcp_group_grants', 'mcp_group_life', 'mcp_scope_modes', 'mcp_scope_items', 'mcp_oauth_clients',
        'mcp_oauth_codes', 'mcp_oauth_tokens', 'mcp_oauth_spent', 'preferences'];
    $result = [];
    foreach ($tables as $table) {
        $qb = $db->getQueryBuilder(); $qb->select('*')->from($table);
        if ($table === 'migrations') $qb->where($qb->expr()->eq('app', $qb->createNamedParameter('mcp')));
        if ($table === 'preferences') $qb->where($qb->expr()->eq('userid', $qb->createNamedParameter($uid)));
        $rows = $qb->executeQuery()->fetchAll();
        $rows = array_map(static function (array $row): array { ksort($row); return array_map(static fn ($v) => $v === null ? null : (string)$v, $row); }, $rows);
        usort($rows, static fn ($a, $b) => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
        $result[$table] = $rows;
    }
    return $result;
}
if ($argv[1] === 'seed') {
    // Exact pinned upstream baseline classes, never loaded alongside the candidate classes.
    $policy = Server::get('OCA\Mcp\Service\GrantPolicy');
    $writer = Server::get('OCA\Mcp\Service\Policy\PolicyWriter');
    $user = Server::get(IUserManager::class)->createUser($uid, bin2hex(random_bytes(24)));
    if ($user === false) throw new RuntimeException('Could not create replacement fixture');
    $policy->setGlobalEnabled(true); $policy->setEligible($uid, true); $policy->setConnected($uid, true);
    $policy->setGrant($uid, 'notes', 'edit', false);
    $writer->setResourceScope(Server::get(PolicyStateMapper::class)->state()['revision'], 'admin', $uid, 'files', []);
    Server::get(OAuthClientsService::class)->update(Server::get(PolicyStateMapper::class)->state()['revision'], null, null,
        [['name' => 'Replacement fixture', 'enabled' => true, 'redirectUris' => ['https://replacement.example/callback']]]);
    $client = CustomClient::clients(Server::get(OAuthClientsMapper::class))[0];
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $resource = 'http://localhost/apps/mcp/';
    $service = Server::get(TokenService::class);
    $code = $service->consent(new AuthorizationRequest(CustomClient::metadata($client), $client['redirectUris'][0], 'replacement', $challenge, $resource, 'mcp'), $uid);
    if (!is_string($code)) throw new RuntimeException('Baseline consent refused');
    $tokens = $service->exchangeCode(['code' => $code, 'code_verifier' => $verifier, 'client_id' => CustomClient::clientId($client['id']),
        'redirect_uri' => $client['redirectUris'][0], 'resource' => $resource]);
    file_put_contents($path, json_encode(['snapshot' => snapshot($db, $uid), 'token' => $tokens['access_token'], 'resource' => $resource], JSON_THROW_ON_ERROR));
    chmod($path, 0600);
    echo "Seeded upstream policy, restricted scope, client and generation-qualified credential.\n";
} else {
    $before = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if ($before['snapshot'] !== snapshot($db, $uid)) throw new RuntimeException('Upstream transition changed persistent state');
    $policy = Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class);
    $policy->within($uid, static function () use ($policy, $uid): void {
        if ($policy->scopeItems($uid, 'files') !== [] || $policy->granted($uid, 'notes', 'edit')) throw new RuntimeException('Replacement lost policy restrictions');
    });
    // Public HTTP entry checks DI and the old credential in a fresh Apache worker after cache reset.
    require_once __DIR__ . '/../Support/Http.php';
    $reply = \OCA\Mcp\Tests\Integration\Support\Http::request('POST', 'http://localhost/index.php/apps/mcp/',
        ['Authorization: Bearer ' . $before['token'], 'Content-Type: application/json', 'Accept: application/json, text/event-stream'],
        json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object)[]], JSON_THROW_ON_ERROR));
    if ($reply['status'] !== 200 || !isset(json_decode($reply['body'], true)['result'])) throw new RuntimeException('Replacement rejected the preserved credential');
    foreach (['admin', 'personal'] as $entry) {
        $file = '/var/www/html/custom_apps/mcp/js/' . $entry . '.js';
        if (!is_file($file) || filesize($file) === 0) throw new RuntimeException('Replacement asset missing');
    }
    // The following shared suite assumes a fresh fixture namespace; remove only this probe's seeded state.
    Server::get(\OCA\Mcp\Access\ResourceScope\ResourceScopeWriter::class)->setResourceScope(
        Server::get(PolicyStateMapper::class)->state()['revision'], 'admin', $uid, 'files', null);
    Server::get(OAuthClientsService::class)->update(Server::get(PolicyStateMapper::class)->state()['revision'], null, null, []);
    if (!Server::get(IUserManager::class)->get($uid)?->delete()) throw new RuntimeException('Replacement fixture cleanup failed');
    unlink($path);
    echo "Upstream transition preserved migrations, policy, clients, generations, scopes and bearer access; assets present.\n";
}

$probeCompletion->complete();
