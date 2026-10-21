<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Compat;

use OC\DB\Connection;
use OC\DB\MigrationService;
use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Migration\PolicyStateSeedStep;
use OCA\Mcp\OAuth\AccessTokenAuthenticator;
use OCA\Mcp\OAuth\OAuthException;
use OCA\Mcp\OAuth\TokenHasher;
use OCA\Mcp\OAuth\TokenService;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Server;

/**
 * T12 and the first part of T13 on the real upgrade from the released baseline, without any group: the snapshot the
 * baseline wrote through its own setters (ci/legacy-policy.php seed, before `occ upgrade`) keeps every byte, type and
 * key after the upgrade and after the migrations and the seed step run again; the branch answers every operation of
 * the CATALOG exactly as the baseline did, under each state of the service switch; the baseline's own GrantPolicy,
 * loaded from the copy the upgrade moved aside, still reads the same bytes into the same answers; and nothing was
 * revoked: global epoch 0, no cut, every token the baseline issued authenticates exactly when canConnect allows it.
 *
 * That 1.0.8 still reads these preferences is a fact about the data. It does not make going back to 1.0.8 safe, even
 * before adopting groups (decisions 1.1.0, D4): 1.0.8 does not know the epochs and cuts that revoke credentials in
 * 1.1.0. This suite runs first (phpunit.xml, suite "compat"), before any other test can switch the service off, and
 * its tests run in declaration order (executionOrder="default" there): the last one cuts users the others read.
 */
final class UpgradeSnapshotTest extends IntegrationTestCase {
    private array $seed = [];
    private IConfig $config;
    private IAppConfig $appConfig;
    private IUserConfig $userConfig;

    protected function setUp(): void {
        parent::setUp();
        if (!in_array(getenv('MCP_IT_MODE'), ['upgrade', 'upgrade-local'], true)) {
            $this->markTestSkipped('Only an upgrade cell has the snapshot written by the baseline');
        }
        $path = (string)getenv('MCP_IT_SNAPSHOT');
        $this->assertFileExists($path, 'The upgrade step must have written the baseline snapshot');
        $this->seed = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->config = Server::get(IConfig::class);
        $this->appConfig = Server::get(IAppConfig::class);
        $this->userConfig = Server::get(IUserConfig::class);
    }

    private function raw(): array {
        return SnapshotProbe::raw($this->config, $this->appConfig, $this->userConfig, SnapshotProbe::uids());
    }

    public function testThePlanCoversEveryOperationInEveryStateAndTheBaselineStoredExactlyIt(): void {
        $this->assertSame('1.0.8', $this->seed['appVersion'], 'the snapshot must be written by the released baseline');
        $this->assertNotSame(getenv('MCP_IT_EXPECTED_VERSION'), $this->seed['appVersion']);
        $this->assertSame(GrantPolicy::CATALOG, $this->seed['catalog'], 'the baseline and the branch must share the CATALOG');
        $plan = $this->seed['plan'];
        $this->assertSame(SnapshotProbe::uids(), array_keys($plan));
        $pairs = [];
        foreach ($plan as $values) {
            $pairs[] = json_encode([$values['eligible'], $values['connected']]);
        }
        $this->assertCount(9, array_unique($pairs), 'every (eligible, connected) pair over 1/0/absent');
        foreach (GrantPolicy::CATALOG as $module => $operations) {
            foreach ($operations as $operation) {
                $seen = array_count_values(array_map(static fn (?string $v): string => $v ?? 'absent', array_column($plan, "grant_{$module}_{$operation}")));
                ksort($seen);
                $this->assertSame(['0' => 3, '1' => 3, 'absent' => 3], $seen, "$module/$operation");
            }
        }
        foreach ($plan as $uid => $values) {
            $stored = array_map(static fn (array $cell): string => $cell['value'], $this->seed['raw']['users'][$uid]);
            $expected = array_filter($values, static fn (?string $v): bool => $v !== null);
            ksort($expected, SORT_STRING);
            $this->assertSame($expected, $stored, "$uid: the baseline stored exactly the plan, no default");
            $this->assertNotNull(Server::get(IUserManager::class)->get($uid), "$uid is a real account");
        }
        $this->assertNull($this->seed['raw']['service'], 'the baseline put the service switch back to absent');
    }

    public function testUpgradeAndReexecutionKeepEveryByteAndRevokeNothing(): void {
        $this->assertSame($this->seed['raw'], $this->raw(), 'after occ upgrade');
        $this->assertSame('0', Server::get(PolicyStateMapper::class)->state()['auth_epoch']);
        $hasher = Server::get(TokenHasher::class);
        $before = [];
        foreach (SnapshotProbe::uids() as $uid) {
            $before[$uid] = $this->token($hasher->hash($this->seed['tokens'][$uid]['access']));
            $this->assertNotNull($before[$uid], "$uid: the baseline token survived the upgrade");
            $this->assertSame(['0', '1'], [$before[$uid]['auth_global_epoch'], $before[$uid]['auth_gen']], $uid);
            $this->assertSame(['issue_seq' => '1', 'revoked_below' => '0'], Server::get(AuthUsersMapper::class)->generations($uid), $uid);
        }
        $state = Server::get(PolicyStateMapper::class)->state();

        // Every migration of the app again through the server's own migration service (what occ
        // migrations:execute runs, a command only debug mode offers), then the seed step the server runs after one.
        $migrations = new MigrationService('mcp', Server::get(Connection::class));
        foreach (glob(dirname(__DIR__, 3) . '/lib/Migration/Version*.php') as $file) {
            $migrations->executeStep(substr(basename($file, '.php'), strlen('Version')));
        }
        Server::get(PolicyStateSeedStep::class)->run($this->createMock(IOutput::class));

        $this->assertSame($this->seed['raw'], $this->raw(), 'after the migrations and the seed step ran again');
        $this->assertSame($state, Server::get(PolicyStateMapper::class)->state());
        foreach (SnapshotProbe::uids() as $uid) {
            $this->assertSame($before[$uid], $this->token($hasher->hash($this->seed['tokens'][$uid]['access'])), $uid);
            $this->assertSame(['issue_seq' => '1', 'revoked_below' => '0'], Server::get(AuthUsersMapper::class)->generations($uid), $uid);
        }
        foreach ($this->seed['appKeys'] as $key => $value) {
            if (!in_array($key, GrantPolicy::RESERVED_APP_KEYS, true)) {
                $this->assertSame($value, $this->appConfig->getAllValues('mcp')[$key] ?? null, "app key $key");
            }
        }
        foreach (array_keys($this->appConfig->getAllValues('mcp')) as $key) {
            $this->assertFalse($key === GrantPolicy::SERVICE_KEY || preg_match('/^(grant_|eligible$|connected$)/', $key) === 1,
                "the upgrade materialized the policy key $key");
        }
    }

    public function testTheBranchAndTheBaselineCodeGiveTheBaselineAnswersOverTheSameBytes(): void {
        $policy = Server::get(GrantPolicy::class);
        $branch = SnapshotProbe::answers($policy, GrantPolicy::CATALOG, $this->config, $this->appConfig, $this->userConfig, SnapshotProbe::uids());
        $this->assertSame($this->seed['answers'], $branch);
        $this->assertSame($this->seed['raw'], $this->raw(), 'reading through the branch wrote nothing');

        // T13: the bytes as the branch left them, read by the baseline's own code in another process.
        $out = sys_get_temp_dir() . '/mcp-it-snapshot-read-' . bin2hex(random_bytes(4)) . '.json';
        exec('php ' . escapeshellarg(__DIR__ . '/../ci/legacy-policy.php') . ' read ' . escapeshellarg($out) . ' '
            . escapeshellarg((string)getenv('MCP_IT_BASELINE_APP')) . ' 2>&1', $lines, $status);
        $this->assertSame(0, $status, implode("\n", $lines));
        $read = json_decode((string)file_get_contents($out), true, 512, JSON_THROW_ON_ERROR);
        unlink($out);
        $this->assertStringStartsWith(rtrim((string)getenv('MCP_IT_BASELINE_APP'), '/') . '/lib/', $read['source']);
        // The script refuses any class of the app the reading loaded from elsewhere, and any class of Service, OAuth
        // or Db the server's boot loaded from the branch before its loader; these are the ones the reading needed.
        $this->assertSame([], preg_grep('/^OCA\\\\Mcp\\\\(Service|OAuth|Db)\\\\/', $read['boot']));
        $this->assertContains(getenv('MCP_IT_MODE') === 'upgrade-local' ? 'OCA\\Mcp\\Access\\Grants\\GrantPolicy' : 'OCA\\Mcp\\Service\\GrantPolicy', $read['loaded']);
        $this->assertContains('OCA\\Mcp\\OAuth\\OAuthStore', $read['loaded']);
        $this->assertSame($this->seed['answers'], $read['answers']);
        $this->assertSame($this->seed['raw'], $read['raw']);
        $this->assertSame($this->seed['raw'], $this->raw());
    }

    /**
     * Last in the class: a refusal in use cuts the user, so nothing after it may expect the snapshot's credentials.
     * With the service on, each baseline token authenticates and its refresh token rotates exactly when the baseline
     * allowed the user; with the service off or absent, as in the baseline, none does.
     */
    public function testEveryBaselineTokenAuthenticatesAndRefreshesExactlyWhenTheBaselineAllowedIt(): void {
        $authenticator = Server::get(AccessTokenAuthenticator::class);
        $tokens = Server::get(TokenService::class);
        $bearer = static fn (string $token): ?string => $authenticator->authenticate('Bearer ' . $token, SnapshotProbe::RESOURCE)?->getUID();
        $access = array_map(static fn (array $pair): string => $pair['access'], $this->seed['tokens']);
        try {
            $this->service('1');
            foreach (SnapshotProbe::uids() as $uid) {
                $allowed = $this->seed['answers']['1']['users'][$uid]['canConnect'];
                $this->assertSame($allowed ? $uid : null, $bearer($access[$uid]), $uid);
                try {
                    $refreshed = $tokens->refresh(['refresh_token' => $this->seed['tokens'][$uid]['refresh'], 'client_id' => SnapshotProbe::CLIENT]);
                    $this->assertTrue($allowed, "$uid: a refresh the baseline would refuse was accepted");
                    $this->assertSame($uid, $bearer((string)$refreshed['access_token']), $uid);
                    $this->assertNull($bearer($access[$uid]), "$uid: rotation replaced the baseline access token");
                    $access[$uid] = (string)$refreshed['access_token'];
                } catch (OAuthException) {
                    $this->assertFalse($allowed, "$uid: the refresh token of an allowed user was refused");
                }
            }
            foreach (['0', null] as $service) {
                $this->service($service);
                foreach (SnapshotProbe::uids() as $uid) {
                    $allowed = $this->seed['answers'][$service ?? 'absent']['users'][$uid]['canConnect'];
                    $this->assertSame($allowed ? $uid : null, $bearer($access[$uid]), "$uid service " . ($service ?? 'absent'));
                }
            }
        } finally {
            $this->service(null);
        }
        $this->assertSame('0', Server::get(PolicyStateMapper::class)->state()['auth_epoch']);
        $this->assertSame($this->seed['raw'], $this->raw(), 'an access refused in use revokes credentials, never preferences');
    }

    /** Writes the service switch raw, as 1.0.8 stores it (never through the writer, which would advance the epoch). */
    private function service(?string $value): void {
        if ($value === null) {
            $this->config->deleteAppValue('mcp', GrantPolicy::SERVICE_KEY);
        } else {
            $this->config->setAppValue('mcp', GrantPolicy::SERVICE_KEY, $value);
        }
        $this->appConfig->clearCache(false);
    }

    /** @return array<string, string>|null the token row with that access hash, every value as a string */
    private function token(string $accessHash): ?array {
        $qb = $this->official()->getQueryBuilder();
        $result = $qb->select('*')->from('mcp_oauth_tokens')->where($qb->expr()->eq('access_hash', $qb->createNamedParameter($accessHash)))->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row === false ? null : array_map('strval', $row);
    }
}
