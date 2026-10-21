<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\ResourceScope;

use OCA\Mcp\Tests\Support\Access\ResourceScope\ScopeFixture;

use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Grants\Groups\GroupSourceClassifier;
use OCA\Mcp\Access\Grants\Groups\GroupTrustObserver;
use OCA\Mcp\Access\Policy\Resolution\LogsRoleReader;
use OCA\Mcp\Access\Grants\Groups\MembershipReader;
use OCA\Mcp\Access\Policy\Resolution\PolicyResolver;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Tests\Support\Access\Policy\PolicyServices;
use OCA\Mcp\Access\ResourceScope\ScopeResolution;
use OCA\Mcp\Access\ResourceScope\ScopeGuard;
use OCP\App\IAppManager;
use OCP\Config\IUserConfig;
use OCP\Group\Backend\INamedBackend;
use OCP\GroupInterface;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * B4: the ScopeGuard and the resource_scope gate of the effective access. S9 (next operation applies, nothing
 * revoked), S10 (grant first, scope applied whatever the grant's source) and S11 (a failed read is unavailable, never
 * unrestricted), plus the one read per operation and the unchanged result without a scope row.
 */
final class ScopeGuardTest extends TestCase {
    private ScopeFixture $fx;
    private PolicyServices $writer;
    private PolicyResolver $resolver;
    private GroupLifeMapper $life;
    /** @var list<string> groups of alice in the Database backend */
    private array $groups = [];

    protected function setUp(): void {
        $this->fx = new ScopeFixture($this);
        $this->fx->config->app['mcp']['service_enabled'] = '1';
        $this->fx->config->user['alice']['mcp']['connected'] = '1';
        $this->fx->config->user['alice']['mcp']['eligible'] = '1';
        $this->writer = $this->fx->services();
        $this->life = new GroupLifeMapper($this->fx->db);
        $this->resolver = $this->resolver(new ResourceScopeMapper($this->fx->db));
    }

    private function resolver(ResourceScopeMapper $scopes): PolicyResolver {
        $backend = $this->createMockForIntersectionOfInterfaces([GroupInterface::class, INamedBackend::class]);
        $backend->method('getBackendName')->willReturn('Database');
        $backend->method('getUserGroups')->willReturnCallback(fn () => $this->groups);
        $backend->method('groupExists')->willReturnCallback(fn (string $gid) => in_array($gid, $this->groups, true));
        $manager = $this->createMock(IGroupManager::class);
        $manager->method('getBackends')->willReturn([$backend]);
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturn(true);
        $apps->method('getAppVersion')->willReturn('99.0.0');
        $logs = $this->createMock(LogsRoleReader::class);
        $logs->method('read')->willReturn(['available' => true, 'permitted' => true]);
        $config = $this->fx->config->mock($this);
        return new PolicyResolver($this->fx->state, $this->writer->transaction, $config, $this->createMock(IUserConfig::class),
            $this->createMock(IAppConfig::class), $this->fx->userManager(), new MembershipReader($manager),
            new GroupSourceClassifier($manager), new GroupGrantsMapper($this->fx->db), $this->life,
            new GroupTrustObserver($this->fx->db, $this->writer->transaction, $this->life, $this->createMock(LoggerInterface::class)),
            $apps, $logs, $scopes);
    }

    /** An active Database group of alice granting one operation; alice has no individual value for it. */
    private function groupGrant(string $module, string $operation): void {
        $this->groups = ['team'];
        $this->fx->database->dbal->insert('mcp_group_grants', ['group_key' => IdentityKey::group('team'),
            'group_id' => 'team', 'module' => $module, 'operation' => $operation]);
        $this->fx->db->beginTransaction();
        try {
            $this->life->put('team', GroupLifeMapper::ACTIVE, bin2hex(random_bytes(16)), '0');
            $this->fx->db->commit();
        } catch (\Throwable $e) {
            $this->fx->db->rollBack();
            throw $e;
        }
    }

    /** @param list<string>|null $items */
    private function scope(string $module, ?array $items): void {
        $this->fx->commands()->setResourceScope($this->fx->revision(), 'root', 'alice', $module, $items);
    }

    /** @return array{0:?bool, 1:string, 2:?string} allowed, reason and gate of one effective resolution */
    private function effective(string $module, string $operation, ?array $resources = null): array {
        $context = $this->resolver->begin('alice');
        try {
            $result = $context->resolveEffective($module, $operation, $resources);
            return [$result->allowed, $result->reasonCode, $result->blockingGate];
        } finally {
            $context->close();
        }
    }

    public function testS10GroupGrantWithAnIndividualScopeAppliesTheScope(): void {
        $this->groupGrant('files', 'edit');
        $this->scope('files', [$this->fx->projects]);

        $context = $this->resolver->begin('alice');
        $inside = $context->resolveEffective('files', 'edit', [$this->fx->projects]);
        $outside = $context->resolveEffective('files', 'edit', [$this->fx->archive]);
        $context->close();

        self::assertSame('group', $inside->grantResolution->source, 'the grant comes from the group');
        self::assertSame([true, 'allowed', null], [$inside->allowed, $inside->reasonCode, $inside->blockingGate]);
        self::assertSame([false, 'out_of_scope', 'resource_scope'], [$outside->allowed, $outside->reasonCode, $outside->blockingGate]);
        self::assertSame(ScopeResolution::RESTRICTED, $outside->scopeResolution->mode);
        self::assertSame(1, $outside->scopeResolution->count());
    }

    public function testS10WithoutTheGrantTheGrantBlocksBeforeTheScope(): void {
        $this->scope('files', [$this->fx->projects]);

        self::assertSame([false, 'grant', 'grant'], $this->effective('files', 'edit', [$this->fx->archive]));
        $this->fx->config->user['alice']['mcp']['grant_files_read'] = '0';
        self::assertSame([false, 'grant', 'grant'], $this->effective('files', 'read', [$this->fx->archive]));
    }

    public function testAnAncestorInTheChainIsEnoughAndAnEmptyChainIsOutside(): void {
        $this->scope('files', [$this->fx->projects]);

        self::assertSame([true, 'allowed', null], $this->effective('files', 'read', ['987654', $this->fx->projects]));
        self::assertSame([false, 'out_of_scope', 'resource_scope'], $this->effective('files', 'read', []));
    }

    public function testRestrictedToNothingKeepsTheModuleButNoResource(): void {
        $this->scope('calendar', []);

        self::assertSame([true, 'allowed', null], $this->effective('calendar', 'read'));
        self::assertSame([false, 'out_of_scope', 'resource_scope'], $this->effective('calendar', 'read', ['11']));
    }

    public function testCalendarAndTasksScopesAreIndependent(): void {
        $this->scope('calendar', ['11']);

        self::assertSame([false, 'out_of_scope', 'resource_scope'], $this->effective('calendar', 'read', ['12']));
        self::assertSame([true, 'allowed', null], $this->effective('tasks', 'read', ['12']));
    }

    /** @return array<string, array{string, string}> every catalog cell */
    public static function cells(): array {
        $cells = [];
        foreach (GrantPolicy::CATALOG as $module => $operations) {
            foreach ($operations as $operation) {
                $cells["$module $operation"] = [$module, $operation];
            }
        }
        return $cells;
    }

    #[DataProvider('cells')]
    public function testWithoutAScopeRowTheResultIsTheGrantResult(string $module, string $operation): void {
        $context = $this->resolver->begin('alice');
        $plain = $context->resolveEffective($module, $operation);
        $withResource = $context->resolveEffective($module, $operation, ['123']);
        $context->close();

        self::assertSame(ScopeResolution::UNRESTRICTED, $plain->scopeResolution->mode);
        self::assertSame([$plain->allowed, $plain->reasonCode, $plain->blockingGate],
            [$withResource->allowed, $withResource->reasonCode, $withResource->blockingGate]);
        self::assertSame(GrantPolicy::defaultGranted($module, $operation), $plain->allowed);
        self::assertNotSame('resource_scope', $plain->blockingGate);
    }

    /** @return array<string, array{\Closure(ScopeGuardTest):void}> */
    public static function brokenReads(): array {
        return [
            'items table missing' => [static fn (self $t) => $t->fx->database->dbal->executeStatement('DROP TABLE ' . ResourceScopeMapper::ITEMS_TABLE)],
            'identity collision' => [static fn (self $t) => $t->fx->database->dbal->insert(ResourceScopeMapper::MODES_TABLE,
                ['user_key' => IdentityKey::user('alice'), 'module' => 'files', 'user_id' => 'mallory', 'policy_rev' => '1'])],
            'invalid revision' => [static fn (self $t) => $t->fx->database->dbal->insert(ResourceScopeMapper::MODES_TABLE,
                ['user_key' => IdentityKey::user('alice'), 'module' => 'files', 'user_id' => 'alice', 'policy_rev' => 'x'])],
            'non-canonical item' => [static function (self $t): void {
                $t->fx->database->dbal->insert(ResourceScopeMapper::MODES_TABLE,
                    ['user_key' => IdentityKey::user('alice'), 'module' => 'calendar', 'user_id' => 'alice', 'policy_rev' => '1']);
                $t->fx->database->dbal->insert(ResourceScopeMapper::ITEMS_TABLE, ['user_key' => IdentityKey::user('alice'),
                    'module' => 'calendar', 'resource_key' => IdentityKey::resource('calendar', '011'), 'resource_id' => '011']);
            }],
        ];
    }

    #[DataProvider('brokenReads')]
    public function testS11AFailedReadIsUnavailableForEveryScopeModuleAndNeverUnrestricted(\Closure $break): void {
        $break($this);

        $context = $this->resolver->begin('alice');
        foreach (ResourceScopeMapper::MODULES as $module) {
            $result = $context->resolveEffective($module, 'read');
            self::assertNull($result->allowed, $module);
            self::assertSame('resource_scope_unknown', $result->blockingGate, $module);
            self::assertSame(ScopeResolution::UNAVAILABLE, $result->scopeResolution->mode, $module);
            self::assertNull($context->resolveEffective($module, 'read', ['11'])->allowed, $module);
            try {
                $context->scope()->allows($module, ['11']);
                self::fail("$module read as unrestricted");
            } catch (PolicyUnavailable $e) {
                self::assertSame(503, $e->getCode());
            }
        }
        // A module without a scope is not affected, and a missing grant still blocks first.
        $deck = $context->resolveEffective('deck', 'read');
        self::assertSame([true, 'allowed', null], [$deck->allowed, $deck->reasonCode, $deck->blockingGate]);
        self::assertSame('grant', $context->resolveEffective('files', 'edit')->blockingGate);
        $context->close();
    }

    public function testS11AFailingMapperIsUnavailable(): void {
        $mapper = $this->createMock(ResourceScopeMapper::class);
        $mapper->method('forUser')->willThrowException(new \OCP\DB\Exception('down'));

        $guard = ScopeGuard::read($mapper, 'alice');

        self::assertSame(ScopeResolution::UNAVAILABLE, $guard->resolution('tasks')->mode);
        self::assertSame(ScopeResolution::UNRESTRICTED, $guard->resolution('notes')->mode, 'Notes has no scope (D4)');
        $this->expectException(PolicyUnavailable::class);
        $guard->allows('files', [$this->fx->projects]);
    }

    public function testS9TheNextOperationAppliesAChangeWithoutRevokingAnything(): void {
        $this->fx->oauth->insertToken(['uid' => 'alice', 'access_hash' => 'a', 'refresh_hash' => 'r'], $this->fx->now);
        $this->groupGrant('files', 'edit');
        $gen = $this->life->forGroups(['team'])[0]['gen'];
        $epoch = $this->fx->state->state()['auth_epoch'];

        self::assertSame([true, 'allowed', null], $this->effective('files', 'read', [$this->fx->archive]));
        $this->scope('files', [$this->fx->projects]);
        self::assertSame([false, 'out_of_scope', 'resource_scope'], $this->effective('files', 'read', [$this->fx->archive]));
        $this->scope('files', null);
        self::assertSame([true, 'allowed', null], $this->effective('files', 'read', [$this->fx->archive]));

        self::assertCount(1, $this->fx->oauth->tokens, 'no credential is revoked');
        self::assertSame($epoch, $this->fx->state->state()['auth_epoch']);
        self::assertSame($gen, $this->life->forGroups(['team'])[0]['gen']);
    }

    public function testTheScopeIsReadOncePerOperation(): void {
        $this->scope('files', [$this->fx->projects]);
        $this->fx->database->queryCount = 0;
        $context = $this->resolver->begin('alice');
        $afterBegin = $this->fx->database->queryCount;

        for ($i = 0; $i < 20; ++$i) {
            $context->resolveEffective('files', 'read', [$this->fx->archive]);
            $context->scope()->allows('files', [$this->fx->projects]);
        }

        self::assertSame($afterBegin, $this->fx->database->queryCount, 'no read after the snapshot');
        $context->close();
        $this->expectException(PolicyUnavailable::class);
        $context->scope();
    }

    public function testAScopeSavedWhileTheSnapshotIsAssembledIsNeverPairedWithTheOlderRevision(): void {
        $this->groups = ['team'];
        $saved = false;
        $scopes = $this->createMock(ResourceScopeMapper::class);
        $real = new ResourceScopeMapper($this->fx->db);
        $scopes->method('forUser')->willReturnCallback(function (string $uid) use (&$saved, $real): array {
            $read = $real->forUser($uid);
            if (!$saved) {
                $saved = true;
                $this->scope('files', [$this->fx->projects]); // commits a new revision after this read
            }
            return $read;
        });

        $context = $this->resolver($scopes)->begin('alice');

        self::assertSame($this->fx->revision(), $context->revision);
        self::assertSame(ScopeResolution::RESTRICTED, $context->scope()->resolution('files')->mode, 'the repeat read the saved scope');
        $context->close();
    }
}
