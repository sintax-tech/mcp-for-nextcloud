<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/*
 * Upgrade cells only (harness.sh install-app): runs inside the container while the released baseline is still the
 * installed app, before the upgrade. It issues OAuth credentials through the baseline's own store and hasher, as a
 * client of that release would hold them: a pending code, a grant rotated once (which leaves a spent record) and a
 * second grant. The plain values and the rows as the baseline wrote them go to the file named by the argument, for
 * OAuth/LegacyCredentialsTest to check after the upgrade (validation T12). It also turns the native client on, as the
 * baseline's admin page does, for Compat/NativeClientMigrationTest. The owner is created later by the fixture
 * step; a row only names it.
 */

use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\OAuth\TokenHasher;
use OCP\IDBConnection;
use OCP\Server;

if (PHP_SAPI !== 'cli' || !isset($argv[1])) {
    fwrite(STDERR, "Usage: php legacy-credentials.php <output.json>\n");
    exit(1);
}
require_once '/var/www/html/lib/base.php';
require_once __DIR__ . '/../Compat/ReleaseUpgradeProbe.php';
\OCA\Mcp\Tests\Integration\Compat\ReleaseUpgradeProbe::checkpoint('credentials', $argv[1]);
Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');

$uid = 'mcp-it-legacy';
$client = 'https://mcp-it.example/client.json';
$redirect = 'https://mcp-it.example/callback';
$resource = 'https://mcp-it.example/index.php/apps/mcp/mcp';
$hasher = Server::get(TokenHasher::class);
$store = Server::get(OAuthStore::class);
$now = time();
// Long enough for the rest of the job; the baseline's own TTLs only apply to what it issues itself.
$ttl = 6 * 3600;

$verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
$code = $hasher->generate('code');
$store->insertCode(['code_hash' => $hasher->hash($code), 'user_id' => $uid, 'client_id' => $client, 'redirect_uri' => $redirect,
    'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
    'resource' => $resource, 'scope' => 'mcp', 'expires_at' => $now + $ttl], $now);

$tokens = [];
foreach (['rotated', 'plain'] as $name) {
    $tokens[$name] = ['access' => $hasher->generate('at'), 'refresh' => $hasher->generate('rt')];
    $store->insertToken(['user_id' => $uid, 'client_id' => $client, 'resource' => $resource, 'scope' => 'mcp',
        'access_hash' => $hasher->hash($tokens[$name]['access']), 'access_expires' => $now + $ttl,
        'refresh_hash' => $hasher->hash($tokens[$name]['refresh']), 'refresh_expires' => $now + $ttl, 'created_at' => $now], $now);
}
$spent = $tokens['rotated']['refresh'];
$id = (int)$store->findByRefresh($hasher->hash($spent))['id'];
$tokens['rotated'] = ['access' => $hasher->generate('at'), 'refresh' => $hasher->generate('rt')];
if (!$store->rotate($id, $hasher->hash($spent), $hasher->hash($tokens['rotated']['access']), $now + $ttl,
    $hasher->hash($tokens['rotated']['refresh']), $now + $ttl)) {
    fwrite(STDERR, "The baseline store refused to rotate the legacy grant\n");
    exit(1);
}

// The native client switched on through the baseline's own setting, as its admin page writes it: the upgrade must carry it
// into the app's table of clients (Compat/NativeClientMigrationTest).
Server::get(\OCP\IConfig::class)->setAppValue('mcp', 'oauth_native_client_enabled', '1');

$db = Server::get(IDBConnection::class);
$rows = [];
foreach (['mcp_oauth_codes', 'mcp_oauth_tokens', 'mcp_oauth_spent'] as $table) {
    $qb = $db->getQueryBuilder();
    $result = $qb->select('*')->from($table)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))->executeQuery();
    $rows[$table] = array_map(static fn (array $row): array => array_map('strval', $row), $result->fetchAll());
    $result->closeCursor();
}
file_put_contents($argv[1], json_encode(['uid' => $uid, 'client' => $client, 'redirect' => $redirect, 'resource' => $resource,
    'code' => $code, 'verifier' => $verifier, 'tokens' => $tokens, 'spent' => $spent, 'rows' => $rows], JSON_THROW_ON_ERROR));
echo 'Issued legacy credentials: ', implode(', ', array_map(static fn ($t, $r): string => "$t=" . count($r), array_keys($rows), $rows)), PHP_EOL;
