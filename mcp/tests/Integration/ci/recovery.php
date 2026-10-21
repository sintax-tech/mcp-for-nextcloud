<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

use OCP\{Server, IUserManager};
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\ResourceScope\ResourceScopeWriter;
use OCA\Mcp\Db\{PolicyStateMapper, OAuthClientsMapper};
use OCA\Mcp\OAuth\{OAuthClientsService, NativeClient, ClientResolver, AuthorizationRequest, TokenService, OAuthException, AccessTokenAuthenticator};

if (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['seed', 'cut', 'close', 'verify'], true)) exit(2);
require_once __DIR__ . '/../Support/ProbeCompletion.php';
$completion = new \OCA\Mcp\Tests\Integration\Support\ProbeCompletion();
define('OC_CONSOLE', 1);
require_once '/var/www/html/lib/base.php';
Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');
require_once __DIR__ . '/../Support/Http.php';
$uid = 'mcp-it-recovery';
$path = '/tmp/mcp-it-recovery.json';
$policy = Server::get(GrantPolicy::class);
$state = Server::get(PolicyStateMapper::class);
$service = Server::get(TokenService::class);
$resource = 'http://localhost/apps/mcp/';
$redirect = NativeClient::REDIRECT_URIS[0];
function requireRecovery(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function listWithRecoveryToken(string $token): int {
    return \OCA\Mcp\Tests\Integration\Support\Http::request('POST', 'http://localhost/index.php/apps/mcp/',
        ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json, text/event-stream'],
        json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], JSON_THROW_ON_ERROR))['status'];
}
$issue = static function (string $verifier) use ($service, $uid, $resource, $redirect): string {
    $client = NativeClient::metadata((string)ClientResolver::nativeGeneration(Server::get(OAuthClientsMapper::class)));
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    return $service->consent(new AuthorizationRequest($client, $redirect, 'recovery', $challenge, $resource, 'mcp'), $uid)
        ?? throw new RuntimeException('Recovery consent refused');
};
$exchange = static fn (string $code, string $verifier): array => $service->exchangeCode(['code' => $code,
    'code_verifier' => $verifier, 'client_id' => NativeClient::CLIENT_ID, 'redirect_uri' => $redirect, 'resource' => $resource]);
if ($argv[1] === 'seed') {
    requireRecovery(Server::get(IUserManager::class)->createUser($uid, bin2hex(random_bytes(24))) !== false, 'Recovery user missing');
    $policy->setGlobalEnabled(true); $policy->setEligible($uid, true); $policy->setConnected($uid, true);
    $policy->setGrant($uid, 'notes', 'edit', false);
    Server::get(ResourceScopeWriter::class)->setResourceScope($state->state()['revision'], 'admin', $uid, 'files', []);
    Server::get(OAuthClientsService::class)->update($state->state()['revision'], null, true, null);
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $tokens = $exchange($issue($verifier), $verifier);
    $pending = $issue($verifier);
    requireRecovery(listWithRecoveryToken($tokens['access_token']) === 200, 'Pre-backup credential must work');
    requireRecovery(file_put_contents($path, json_encode(['tokens' => $tokens, 'code' => $pending, 'verifier' => $verifier,
        'epoch' => $state->state()['auth_epoch']], JSON_THROW_ON_ERROR)) !== false, 'Recovery fixture write failed');
    chmod($path, 0600);
    echo "Recovery fixture: live credential, pending code and restricted scope saved privately.\n";
} else {
    $before = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if ($argv[1] === 'cut') {
        $policy->setGlobalEnabled(false);
        requireRecovery($state->state()['auth_epoch'] !== $before['epoch'], 'Post-backup revocation did not advance epoch');
        echo "Post-backup credential revocation committed.\n";
    } elseif ($argv[1] === 'close') {
        requireRecovery($state->state()['auth_epoch'] === $before['epoch'], 'Database restore was not actually performed');
        requireRecovery($policy->globalEnabled(), 'Backup must contain the old enabled policy');
        // Maintenance remains on. Do not expose restored credentials before invalidating them.
        $policy->setGlobalEnabled(false);
        // Service shutdown cuts OAuth credentials, but intentionally preserves the personal preference.
        // Recovery also disconnects this account explicitly before its permission review and reconnection.
        $policy->setConnected($uid, false);
        requireRecovery(!$policy->globalEnabled() && !$policy->connected($uid), 'Recovery must require reconnection');
        requireRecovery(Server::get(AccessTokenAuthenticator::class)->authenticate('Bearer ' . $before['tokens']['access_token'], $resource) === null,
            'Restored bearer survived recovery cut');
        $policy->within($uid, static function () use ($policy, $uid): void {
            requireRecovery($policy->scopeItems($uid, 'files') === [] && !$policy->granted($uid, 'notes', 'edit'), 'Recovery changed restrictions');
        });
        echo "Restored app/database remain closed; old credentials invalidated and restrictions verified.\n";
    } else {
        requireRecovery(!$policy->globalEnabled(), 'Service reopened before recovery verification');
        requireRecovery(listWithRecoveryToken($before['tokens']['access_token']) === 401, 'Old bearer accepted after maintenance');
        foreach ([static fn () => $exchange($before['code'], $before['verifier']),
            static fn () => $service->refresh(['refresh_token' => $before['tokens']['refresh_token'], 'client_id' => NativeClient::CLIENT_ID, 'resource' => $resource])] as $attempt) {
            $refused = false;
            try { $attempt(); } catch (OAuthException $e) { $refused = $e->error === 'invalid_grant'; }
            requireRecovery($refused, 'Restored pending code or refresh token was accepted');
        }
        // Reopen only this disposable rehearsal after its explicit restriction review.
        $policy->setGlobalEnabled(true); $policy->setConnected($uid, true);
        $policy->within($uid, static function () use ($policy, $uid): void {
            requireRecovery($policy->scopeItems($uid, 'files') === [] && !$policy->granted($uid, 'notes', 'edit'), 'Reopening lost restrictions');
        });
        requireRecovery(listWithRecoveryToken($before['tokens']['access_token']) === 401, 'Reopening resurrected an old credential');
        $fresh = $exchange($issue($before['verifier']), $before['verifier']);
        requireRecovery(listWithRecoveryToken($fresh['access_token']) === 200, 'New connection failed after recovery');
        $policy->setGlobalEnabled(false);
        echo "Recovery passed: old access/refresh/code rejected; explicit reconnection works; service left closed.\n";
    }
}
$completion->complete();
