<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/*
 * Upgrade cells only (validation T12/T13), inside the container, with the released baseline's code:
 *
 *   seed <output.json>              while the baseline is the installed app, before the upgrade (harness.sh install-app):
 *                                   writes the synthetic snapshot of Compat/SnapshotProbe through the baseline's own
 *                                   GrantPolicy setters, issues one access token per user through its own store, and
 *                                   records the plan, the stored bytes and every answer of that release.
 *   read <output.json> <app-dir>    after the upgrade (Compat/UpgradeSnapshotTest): loads GrantPolicy from the baseline
 *                                   copy at <app-dir> (the one harness.sh moved aside) instead of the installed branch,
 *                                   and records the stored bytes and every answer that release gives for them now.
 *                                   Nothing is downgraded; it reads only, besides putting the service switch back.
 *
 * The accounts of the snapshot are created later by the fixture step; a preference only names its user.
 */

use OCA\Mcp\Tests\Integration\Compat\SnapshotProbe;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Server;

if (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['seed', 'read'], true) || !isset($argv[2]) || ($argv[1] === 'read' && !isset($argv[3]))) {
    fwrite(STDERR, "Usage: php legacy-policy.php seed <output.json> | read <output.json> <baseline-app-dir>\n");
    exit(1);
}
[, $mode, $output] = $argv;
require_once __DIR__ . '/../Support/ProbeCompletion.php';
$probeCompletion = new \OCA\Mcp\Tests\Integration\Support\ProbeCompletion();
require_once '/var/www/html/lib/base.php';
require_once __DIR__ . '/../Compat/SnapshotProbe.php';

if ($mode === 'seed') {
    Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');
} else {
    // The baseline's classes win over the installed branch's for everything loaded from here on: this loader is
    // prepended. The file every class came from is checked below, so a wrong release can never pass silently.
    $lib = rtrim($argv[3], '/') . '/lib/';
    // The server's boot may already have loaded classes of the installed branch (its Application); they are recorded
    // and checked after the reading. Nothing the reading uses may be among them.
    $mcpClasses = static fn (): array => array_values(array_filter([...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()],
        static fn (string $name): bool => str_starts_with($name, 'OCA\\Mcp\\') && !str_starts_with($name, 'OCA\\Mcp\\Tests\\')));
    $boot = $mcpClasses();
    spl_autoload_register(static function (string $class) use ($lib): void {
        if (str_starts_with($class, 'OCA\\Mcp\\')) {
            $file = $lib . str_replace('\\', '/', substr($class, strlen('OCA\\Mcp\\'))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }, true, true);
}

$config = Server::get(IConfig::class);
$appConfig = Server::get(IAppConfig::class);
$userConfig = Server::get(IUserConfig::class);
$db = Server::get(\OCP\IDBConnection::class);
$store = new \OCA\Mcp\OAuth\OAuthStore($db);
// The baseline's constructor: (IConfig, OAuthStore, ?IUserConfig). Built by hand so the branch's container never
// resolves it.
$policyClass = getenv('MCP_IT_MODE') === 'upgrade-local'
    ? 'OCA\\Mcp\\Access\\Grants\\GrantPolicy' : 'OCA\\Mcp\\Service\\GrantPolicy';
$policy = new $policyClass($config, $store, $userConfig);
$source = (string)(new ReflectionClass($policy))->getFileName();
if ($mode === 'read' && !str_starts_with($source, $lib)) {
    fwrite(STDERR, "GrantPolicy came from $source, not from the baseline at $lib\n");
    exit(1);
}
$catalog = $policyClass::CATALOG;
$uids = SnapshotProbe::uids();
$result = ['source' => $source, 'catalog' => $catalog];

if ($mode === 'seed') {
    $plan = SnapshotProbe::plan($catalog);
    foreach ($plan as $uid => $values) {
        foreach ($values as $key => $value) {
            if ($value === null) {
                continue;
            }
            match (true) {
                $key === 'eligible' => $policy->setEligible($uid, $value === '1'),
                $key === 'connected' => $policy->setConnected($uid, $value === '1'),
                default => $policy->setGrant($uid, ...[...explode('_', substr($key, strlen('grant_')), 2), $value === '1']),
            };
        }
    }
    // After the setters: the baseline's setEligible(false) deletes the user's tokens.
    $hasher = Server::get(\OCA\Mcp\OAuth\TokenHasher::class);
    $now = time();
    $ttl = 6 * 3600;
    $tokens = [];
    foreach ($uids as $uid) {
        $tokens[$uid] = ['access' => $hasher->generate('at'), 'refresh' => $hasher->generate('rt')];
        $store->insertToken(['user_id' => $uid, 'client_id' => SnapshotProbe::CLIENT, 'resource' => SnapshotProbe::RESOURCE, 'scope' => 'mcp',
            'access_hash' => $hasher->hash($tokens[$uid]['access']), 'access_expires' => $now + $ttl,
            'refresh_hash' => $hasher->hash($tokens[$uid]['refresh']), 'refresh_expires' => $now + $ttl, 'created_at' => $now], $now);
    }
    $result += ['appVersion' => Server::get(\OCP\App\IAppManager::class)->getAppVersion('mcp'), 'plan' => $plan, 'tokens' => $tokens,
        'appKeys' => $appConfig->getAllValues('mcp')];
}
$result += ['raw' => SnapshotProbe::raw($config, $appConfig, $userConfig, $uids),
    'answers' => SnapshotProbe::answers($policy, $catalog, $config, $appConfig, $userConfig, $uids)];
if ($mode === 'read') {
    // Every class of the app loaded by the reading, not only GrantPolicy, must be the baseline's. Classes the boot
    // loaded before the baseline loader are allowed only outside what a policy read uses (Service, OAuth, Db).
    $loaded = [];
    foreach ($mcpClasses() as $name) {
        $file = (string)(new ReflectionClass($name))->getFileName();
        if (in_array($name, $boot, true)) {
            if (preg_match('/^OCA\\\\Mcp\\\\(Service|Access|OAuth|Db)\\\\/', $name) === 1) {
                fwrite(STDERR, "$name was loaded from $file by the boot, before the baseline loader\n");
                exit(1);
            }
            continue;
        }
        if (!str_starts_with($file, $lib)) {
            fwrite(STDERR, "$name came from $file, not from the baseline at $lib\n");
            exit(1);
        }
        $loaded[] = $name;
    }
    sort($boot);
    sort($loaded);
    $result += ['boot' => $boot, 'loaded' => $loaded];
}
file_put_contents($output, json_encode($result, JSON_THROW_ON_ERROR));
echo "Legacy policy snapshot ($mode) of ", count($uids), ' users from ', $source, PHP_EOL;

$probeCompletion->complete();
