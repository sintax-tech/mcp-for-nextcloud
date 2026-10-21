<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\Policy\Resolution;

use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Migration\Version001100Date20261014000000;
use OCA\Mcp\Migration\Version001100Date20261015000000;
use OCA\Mcp\Migration\Version001100Date20261015010000;
use OCA\Mcp\Migration\Version001100Date20261015020000;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Access\ResourceScope\ScopeGuard;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Logs\LogsAccess;
use OCA\Mcp\Access\Grants\Groups\GroupSourceClassifier;
use OCA\Mcp\Access\Grants\Groups\GroupTrustObserver;
use OCA\Mcp\Access\Grants\Groups\MembershipReader;
use OCA\Mcp\Access\Policy\Resolution\LogsRoleReader;
use OCA\Mcp\Access\Policy\Resolution\PolicyOperationContext;
use OCA\Mcp\Access\Policy\Resolution\PolicyResolver;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Access\Policy\Events\PolicyEventProcessor;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\App\IAppManager;
use OCP\Config\IUserConfig;
use OCP\Group\Backend\INamedBackend;
use OCP\GroupInterface;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ResolutionCoreTest extends TestCase {
    private SqliteDatabase $database;
    private IDBConnection $db;
    private PolicyStateMapper $state;
    private GroupLifeMapper $life;
    private InMemoryConfig $data;
    private IConfig $config;
    private IUserConfig $userConfig;
    private IAppConfig $appConfig;
    private IUserManager $users;
    private IGroupManager $manager;
    private IAppManager $apps;
    private LogsAccess $logs;
    private PolicyTransaction $writer;
    private PolicyResolver $resolver;
    private array $backends = [];
    private array $memberships = [];
    private array $exists = [];
    private int $membershipReads = 0;
    private int $classifications = 0;
    private bool $accountEnabled = true;

    protected function setUp(): void {
        $this->database = new SqliteDatabase($this, [new Version001100Date20261014000000(),
            new Version001100Date20261015000000($this->createMock(IDBConnection::class)),
            new Version001100Date20261015010000(), new Version001100Date20261015020000()]);
        $this->db = $this->database->connection();
        (new Version001100Date20261015000000($this->db))->postSchemaChange($this->createMock(IOutput::class), fn () => null, []);
        $this->state = new PolicyStateMapper($this->db);
        $this->life = new GroupLifeMapper($this->db);
        $this->data = new InMemoryConfig();
        $this->config = $this->data->mock($this);
        $this->data->app['mcp']['service_enabled'] = '1';
        $this->data->user['alice']['mcp']['connected'] = '1';
        $this->userConfig = $this->createMock(IUserConfig::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $user->method('isEnabled')->willReturnCallback(fn () => $this->accountEnabled);
        $this->users = $this->createMock(IUserManager::class);
        $this->users->method('get')->willReturn($user);
        $this->manager = $this->createMock(IGroupManager::class);
        $this->manager->method('getBackends')->willReturnCallback(fn () => $this->backends);
        $this->manager->expects($this->never())->method('get');
        $this->manager->expects($this->never())->method('getUserGroups');
        $this->manager->expects($this->never())->method('clearBackends');
        $this->apps = $this->createMock(IAppManager::class);
        $this->apps->method('isEnabledForUser')->willReturn(true);
        $this->apps->method('getAppVersion')->willReturn('99.0.0');
        $this->logs = $this->createMock(LogsAccess::class);
        $this->logs->method('available')->willReturn(true);
        $this->logs->method('permits')->willReturn(true);
        $this->writer = new PolicyTransaction($this->db, $this->state, $this->createMock(PolicyEventsMapper::class),
            $this->userConfig, $this->appConfig, $this->createMock(PolicyEventProcessor::class));
        $this->resolver = $this->resolver();
    }

    private function resolver(?PolicyStateMapper $state = null): PolicyResolver {
        return new PolicyResolver($state ?? $this->state, $this->writer, $this->config, $this->userConfig,
            $this->appConfig, $this->users, new MembershipReader($this->manager), new GroupSourceClassifier($this->manager),
            new GroupGrantsMapper($this->db), $this->life,
            new GroupTrustObserver($this->db, $this->writer, $this->life, $this->createMock(LoggerInterface::class)),
            $this->apps, new LogsRoleReader($this->config, $this->logsGroups(), $this->manager, $this->createMock(\OCP\Security\Ip\IRemoteAddress::class), $this->createMock(\OCP\IUserSession::class)),
            new ResourceScopeMapper($this->db));
    }

    /** No group listed for the logs role: only administrators hold it. */
    private function logsGroups(): \OCA\Mcp\Db\LogsGroupsMapper {
        $mapper = $this->createMock(\OCA\Mcp\Db\LogsGroupsMapper::class);
        $mapper->method('state')->willReturn(['groups' => [], 'version' => 0]);
        return $mapper;
    }

    private function backend(?string $name = 'Database', array $gids = ['team']): GroupInterface {
        $backend = $name === null ? $this->createMock(GroupInterface::class)
            : $this->createMockForIntersectionOfInterfaces([GroupInterface::class, INamedBackend::class]);
        $id = spl_object_id($backend);
        $this->memberships[$id] = $this->exists[$id] = $gids;
        $backend->method('getUserGroups')->willReturnCallback(function ($uid) use ($id): array {
            $this->assertSame('alice', $uid);
            $this->assertFalse($this->db->inTransaction());
            ++$this->membershipReads;
            return $this->memberships[$id];
        });
        $backend->method('groupExists')->willReturnCallback(function ($gid) use ($id): bool {
            $this->assertFalse($this->db->inTransaction());
            ++$this->classifications;
            return in_array($gid, $this->exists[$id], true);
        });
        if ($name !== null) { $backend->method('getBackendName')->willReturn($name); }
        return $backend;
    }

    private function seed(string $gid = 'team', string $state = 'active', string $module = '_system', string $op = 'eligible'): void {
        $this->database->dbal->insert('mcp_group_grants', ['group_key' => IdentityKey::group($gid),
            'group_id' => $gid, 'module' => $module, 'operation' => $op]);
        $this->db->beginTransaction();
        try { $this->life->put($gid, $state, bin2hex(random_bytes(16)), '0'); $this->db->commit(); }
        catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    public static function sources(): array {
        return [['Database', true], ['LDAP', false], ['SAML', false], [null, false], ['database', false]];
    }

    #[DataProvider('sources')]
    public function testOnlyExactNamedDatabaseWithActiveLifeGrants(?string $name, bool $allowed): void {
        $this->backends = [$this->backend($name)];
        $this->seed();
        $this->seed('team', 'active', 'notes', 'edit');
        $context = $this->resolver->begin('alice');
        try {
            $this->assertSame($allowed ? true : null, $context->eligible()->allowed);
            $this->assertSame($allowed ? 'active' : 'needs_confirm', $this->life->get('team')['state']);
            $this->assertCount(1, $context->candidates());
            $this->assertSame($allowed ? true : null, $context->resolveEffective('files', 'read')->allowed);
            $this->assertSame($allowed ? true : null, $context->granted('notes', 'edit')->allowed);
            $this->assertCount(2, $this->database->dbal->fetchAllAssociative('SELECT * FROM mcp_group_grants'));
        } finally { $context->close(); }
    }

    public function testTwoDatabaseBackendsDoNotPassAndReturningToSingletonDoesNotReactivate(): void {
        $first = $this->backend();
        $this->backends = [$first, $this->backend()];
        $this->seed();
        $context = $this->resolver->begin('alice');
        $this->assertNull($context->eligible()->allowed);
        $context->close();
        $this->backends = [$first];
        $context = $this->resolver->begin('alice');
        $this->assertNull($context->eligible()->allowed);
        $this->assertSame('needs_confirm', $this->life->get('team')['state']);
        $context->close();
    }

    public static function inactiveStates(): array { return [['pending_delete'], ['needs_confirm']]; }

    #[DataProvider('inactiveStates')]
    public function testInactiveOnlySourceIsUnknownButAnIndividualAllowOrAnotherActiveSourceWins(string $state): void {
        $this->backends = [$this->backend('Database', ['team', 'valid'])];
        $this->seed('team', $state);
        $context = $this->resolver->begin('alice');
        $this->assertNull($context->eligible()->allowed);
        $this->assertSame('eligibility_unknown', $context->resolveEffective('files', 'read')->blockingGate);
        $context->close();
        $this->data->user['alice']['mcp']['eligible'] = '1';
        $context = $this->resolver->begin('alice');
        $this->assertTrue($context->eligible()->allowed);
        $context->close();
        unset($this->data->user['alice']['mcp']['eligible']);
        $this->seed('valid');
        $context = $this->resolver->begin('alice');
        $this->assertTrue($context->eligible()->allowed);
        $this->assertSame(['valid'], $context->eligible()->groupIds);
        $context->close();
    }

    public function testLdapWithoutPolicyIsNotACandidateAndCannotGrantEligibility(): void {
        $this->backends = [$this->backend('LDAP')];
        $context = $this->resolver->begin('alice');
        $this->assertFalse($context->eligible()->allowed);
        $this->assertSame([], $context->candidates());
        $this->assertSame(0, $this->classifications);
        $context->close();
    }

    public function testSameManagerAndResolverReadNewMembershipAndConfigBetweenOperationsWithOverlay(): void {
        $backend = $this->backend();
        $this->backends = [$backend];
        $this->seed();
        $first = $this->resolver->begin('alice');
        $this->assertTrue($first->eligible()->allowed);
        $reads = $this->data->batchCalls;
        $this->memberships[spl_object_id($backend)] = [];
        $this->assertTrue($first->eligible()->allowed); // One unit owns its observed snapshot.
        $this->assertSame($reads, $this->data->batchCalls);
        $first->close();
        $next = $this->resolver->begin('alice');
        $this->assertFalse($next->eligible()->allowed);
        $next->close();
        $this->memberships[spl_object_id($backend)] = ['team'];
        $next = $this->resolver->begin('alice', [], ['team']);
        $this->assertFalse($next->eligible()->allowed);
        $next->close();
        $this->expectException(PolicyUnavailable::class);
        $first->eligible();
    }

    public function testManagerModeReceivesIUserAndFreshModeReceivesUidAndPreservesNumericIds(): void {
        $manager = $this->createMock(IGroupManager::class);
        $user = $this->createMock(IUser::class);
        $manager->expects($this->once())->method('getUserGroupIds')->with($user)->willReturn(['1', '01', '1']);
        $this->assertSame(['01', '1'], (new MembershipReader($manager))->read($user, false));
    }

    public function testBackendFailureIsUnavailableAndNeverAnEmptyMembershipOrTrustLossWrite(): void {
        $backend = $this->createMock(GroupInterface::class);
        $backend->method('getUserGroups')->willThrowException(new \RuntimeException('Directory offline'));
        $this->backends = [$backend];
        $this->seed();
        try { $this->resolver->begin('alice'); $this->fail('Read failed open'); }
        catch (PolicyUnavailable $e) { $this->assertSame(503, $e->getCode()); }
        $this->assertSame('active', $this->life->get('team')['state']);
        $this->assertSame('0', $this->state->state()['revision']);
    }

    public function testClassifierFailureIsUnavailableRatherThanUntrusted(): void {
        $backend = $this->createMock(GroupInterface::class);
        $backend->method('groupExists')->willThrowException(new \RuntimeException('Directory offline'));
        $this->backends = [$backend];
        $this->expectException(PolicyUnavailable::class);
        (new GroupSourceClassifier($this->manager))->trusted('team');
    }

    public function testForeignTransactionWithholdsWithoutWritingOrTakingOwnership(): void {
        $this->backends = [$this->backend('LDAP')];
        $this->seed();
        // Direct observer admission avoids remote reads within a caller's transaction.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $this->db->beginTransaction();
        try {
            (new GroupTrustObserver($this->db, $this->writer, $this->life, $logger))->observe([
                ['groupId' => 'team', 'gen' => $this->life->get('team')['gen']],
            ]);
            $this->assertTrue($this->db->inTransaction());
            $this->assertSame('active', $this->life->get('team')['state']);
        } finally { $this->db->rollBack(); }
    }

    public function testObserverCannotMarkANewerGenerationUntrusted(): void {
        $this->seed();
        (new GroupTrustObserver($this->db, $this->writer, $this->life, $this->createMock(LoggerInterface::class)))
            ->observe([['groupId' => 'team', 'gen' => str_repeat('a', 32)]]);
        $this->assertSame('active', $this->life->get('team')['state']);
        $this->assertSame('0', $this->state->state()['revision']);
    }

    public static function truthTable(): iterable {
        foreach (GrantPolicy::CATALOG as $module => $operations) {
            foreach ($operations as $op) {
                foreach (['inherit', '0', '1', 'bad', 1] as $raw) {
                    foreach ([false, true] as $group) {
                        yield "$module/$op/" . get_debug_type($raw) . "/$raw/" . (int)$group => [$module, $op, $raw, $group];
                    }
                }
            }
        }
    }

    #[DataProvider('truthTable')]
    public function testCatalogTruthTablePreservesDefaultsAndIndividualPrecedence(string $module, string $op, mixed $raw, bool $group): void {
        // Pure resolution table; real storage and classification are tested independently above.
        $context = new PolicyOperationContext('0', $this->writer,
            ['grant_' . $module . '_' . $op => $raw === 'inherit' ? ['present' => false] : ['present' => true, 'value' => $raw]],
            $group ? [['groupId' => 'team', 'grants' => [$module => [$op]], 'trusted' => true, 'state' => 'active']] : [],
            true, true, array_fill_keys(array_keys(GrantPolicy::CATALOG), true), true, true,
            ScopeGuard::read(new ResourceScopeMapper($this->db), 'alice'));
        $expected = $raw === 'inherit' ? ($group || GrantPolicy::defaultGranted($module, $op)) : $raw === '1';
        $this->assertSame($expected, $context->granted($module, $op)->allowed);
        $context->close();
    }

    public function testRevisionChangeRetriesOnceAndNeverReturnsAHybridSnapshot(): void {
        $state = $this->createMock(PolicyStateMapper::class);
        $calls = 0;
        $state->method('state')->willReturnCallback(static function () use (&$calls): array {
            return ['revision' => ++$calls === 1 ? '0' : '1', 'lock_seq' => '0', 'auth_epoch' => '0'];
        });
        $context = $this->resolver($state)->begin('alice');
        $this->assertSame('1', $context->revision);
        $this->assertSame(4, $calls);
        $context->close();
    }

    public function testRepeatedRevisionChangeIsUnavailable(): void {
        $state = $this->createMock(PolicyStateMapper::class);
        $calls = 0;
        $state->method('state')->willReturnCallback(static function () use (&$calls): array {
            return ['revision' => (string)++$calls, 'lock_seq' => '0', 'auth_epoch' => '0'];
        });
        $this->expectException(PolicyUnavailable::class);
        $this->resolver($state)->begin('alice');
    }

    public function testAbandonedWriterBlocksNewAndAlreadyOpenReadsWithoutDefault(): void {
        $context = $this->resolver->begin('alice');
        $this->userConfig->method('clearCache')->willThrowException(new \RuntimeException('Cannot invalidate cache'));
        try { $this->writer->runInternal(['alice'], fn () => null, fn () => false); }
        catch (PolicyUnavailable) {}
        try { $this->resolver->begin('alice'); $this->fail('Abandoned writer accepted'); }
        catch (PolicyUnavailable $e) { $this->assertSame(503, $e->getCode()); }
        $this->expectException(PolicyUnavailable::class);
        $context->granted('files', 'read');
    }

    public function testMissingStateRowIsUnavailableWithoutReconstruction(): void {
        $this->database->dbal->delete('mcp_policy_state', ['id' => 1]);
        $this->expectException(PolicyUnavailable::class);
        $this->resolver->begin('alice');
    }

    public function testMoreThanTwoHundredGroupsAreReadAndSortedWithoutTruncation(): void {
        $ids = array_map(static fn ($n) => 'group-' . $n, range(0, 204));
        $this->backends = [$this->backend('Database', $ids)];
        foreach ($ids as $gid) { $this->seed($gid); }
        $context = $this->resolver->begin('alice');
        $this->assertCount(205, $context->candidates());
        $this->assertCount(205, $context->eligible()->groupIds);
        $this->assertContains('group-204', $context->eligible()->groupIds);
        $context->close();
    }
    public static function gates(): array {
        return [
            'all open' => [true, true, '1', '1', true, true, true, '1', true, null],
            'account' => [false, true, '1', '1', true, true, true, '1', false, 'account'],
            'service' => [true, false, '1', '1', true, true, true, '1', false, 'service'],
            'eligibility deny' => [true, true, '0', '1', true, true, true, '1', false, 'eligibility'],
            'eligibility invalid' => [true, true, 'bad', '1', true, true, true, '1', false, 'eligibility'],
            'connection' => [true, true, '1', '0', true, true, true, '1', false, 'connection'],
            'app' => [true, true, '1', '1', false, true, true, '1', false, 'app'],
            'log unavailable' => [true, true, '1', '1', true, false, true, '1', false, 'logs_available'],
            'log role' => [true, true, '1', '1', true, true, false, '1', false, 'logs_role'],
            'grant deny' => [true, true, '1', '1', true, true, true, '0', false, 'grant'],
        ];
    }

    #[DataProvider('gates')]
    public function testEffectiveGatesDoNotChangeConfiguredGrant(bool $account, bool $service, string $eligible,
        string $connected, bool $app, bool $available, bool $role, string $grant, bool $allowed, ?string $gate): void {
        $context = new PolicyOperationContext('0', $this->writer, [
            'eligible' => ['present' => true, 'value' => $eligible],
            'connected' => ['present' => true, 'value' => $connected],
            'grant_logs_read' => ['present' => true, 'value' => $grant],
        ], [], $account, $service, ['logs' => $app], $available, $role, ScopeGuard::read(new ResourceScopeMapper($this->db), 'alice'));
        $result = $context->resolveEffective('logs', 'read');
        $this->assertSame($allowed, $result->allowed);
        $this->assertSame($gate, $result->blockingGate);
        $this->assertSame($grant === '1', $result->grantResolution->allowed);
        $context->close();
    }

    public function testResidualOrdinaryGrantCannotBecomeDefaultAndMissingLifecycleNeverGrants(): void {
        $this->backends = [$this->backend()];
        $this->seed('team', 'active', 'files', 'read');
        $this->database->dbal->delete('mcp_group_life', ['group_key' => IdentityKey::group('team')]);
        $this->seed('team', 'active', 'files', 'edit');
        $this->database->dbal->delete('mcp_group_life', ['group_key' => IdentityKey::group('team')]);
        $context = $this->resolver->begin('alice');
        // Group rows only grant: an allowing default does not depend on the indeterminate group (§3.3.5).
        $read = $context->granted('files', 'read');
        $this->assertTrue($read->allowed);
        $this->assertSame('default', $read->source);
        $this->assertSame(['team'], array_column($context->candidates(), 'groupId'));
        // A denying default depended on the group alone, so it stays unknown instead of denied.
        $edit = $context->granted('files', 'edit');
        $this->assertNull($edit->allowed);
        $this->assertSame(['team'], $edit->groupIds);
        $this->assertFalse($context->eligible()->allowed);
        $context->close();
    }

    /** F2 of the second reading: what removing an individual Allow leaves, without changing eligible() itself. */
    public function testInheritedEligibilityIgnoresTheIndividualValue(): void {
        $this->data->user['alice']['mcp']['eligible'] = '1';
        foreach ([['Database', 'active', true, 'group'], [null, null, false, 'default'], ['LDAP', 'active', null, 'unknown'],
            ['Database', 'needs_confirm', null, 'unknown']] as [$backend, $state, $allowed, $source]) {
            $this->database->dbal->executeStatement('DELETE FROM mcp_group_grants');
            $this->database->dbal->executeStatement('DELETE FROM mcp_group_life');
            $this->backends = $backend === null ? [] : [$this->backend($backend)];
            if ($state !== null) {
                $this->seed('team', $state);
            }
            $context = $this->resolver->begin('alice');
            try {
                $this->assertTrue($context->eligible()->allowed, 'the individual Allow still decides eligible()');
                $inherited = $context->inheritedEligibility();
                $this->assertSame($allowed, $inherited->allowed, (string)$backend . '/' . (string)$state);
                $this->assertSame($source, $inherited->source);
            } finally { $context->close(); }
        }
    }

    public function testIndividualAllowWithAGroupNeedingConfirmationStaysAllowed(): void {
        $this->backends = [$this->backend()];
        $this->seed('team', GroupLifeMapper::NEEDS_CONFIRM);
        $this->seed('team', GroupLifeMapper::NEEDS_CONFIRM, 'files', 'read');
        $this->data->user['alice']['mcp']['eligible'] = '1';
        $context = $this->resolver->begin('alice');
        try {
            $this->assertTrue($context->eligible()->allowed);
            $this->assertSame('individual_allow', $context->eligible()->source);
            $read = $context->resolveEffective('files', 'read');
            $this->assertTrue($read->allowed);
            $this->assertNull($read->blockingGate);
            $this->assertSame(['team'], array_column($context->candidates(), 'groupId'));
        } finally { $context->close(); }
    }

    public function testUnknownEligibilityNeverCutsCredentialsAndExplicitDenyWins(): void {
        $this->backends = [$this->backend('LDAP')];
        $this->seed();
        $before = $this->database->dbal->fetchAllAssociative('SELECT * FROM mcp_auth_users');
        $context = $this->resolver->begin('alice');
        $this->assertNull($context->eligible()->allowed);
        $context->close();
        $this->assertSame($before, $this->database->dbal->fetchAllAssociative('SELECT * FROM mcp_auth_users'));
        $this->assertSame([], $this->database->dbal->fetchAllAssociative('SELECT * FROM mcp_auth_recheck'));
        $this->data->user['alice']['mcp']['eligible'] = '0';
        $context = $this->resolver->begin('alice');
        $this->assertFalse($context->eligible()->allowed);
        $this->assertSame('individual_deny', $context->eligible()->source);
        $context->close();
    }

    public function testUnknownCatalogCellThrowsInsteadOfApplyingADefault(): void {
        $context = $this->resolver->begin('alice');
        try {
            $this->expectException(\InvalidArgumentException::class);
            $context->granted('files', 'delete');
        } finally { $context->close(); }
    }

    public function testOptionalAppVersionAndUserEnablementAreCheckedPerOperation(): void {
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturn(true);
        $version = '1.0.0';
        $apps->method('getAppVersion')->willReturnCallback(static function () use (&$version): string { return $version; });
        $this->apps = $apps;
        $resolver = $this->resolver();
        $this->data->user['alice']['mcp']['eligible'] = '1';
        $this->data->user['alice']['mcp']['grant_deck_read'] = '1';
        $context = $resolver->begin('alice');
        $this->assertSame('app', $context->resolveEffective('deck', 'read')->blockingGate);
        $context->close();
        $version = '99.0.0';
        $context = $resolver->begin('alice');
        $this->assertTrue($context->resolveEffective('deck', 'read')->allowed);
        $context->close();
        $this->accountEnabled = false;
        $context = $resolver->begin('alice');
        $this->assertSame('account', $context->resolveEffective('deck', 'read')->blockingGate);
        $context->close();
    }

    public function testFiftyOperationCostIsMeasuredWithoutDirectoryEnumerationOrSilentCaps(): void {
        $this->backends = [$this->backend()];
        $this->seed();
        $this->database->queryCount = $this->data->batchCalls = 0;
        $started = hrtime(true);
        for ($n = 0; $n < 50; ++$n) {
            $context = $this->resolver->begin('alice');
            try { $this->assertTrue($context->eligible()->allowed); }
            finally { $context->close(); }
        }
        $elapsed = (hrtime(true) - $started) / 1e6;
        $cells = array_sum(array_map('count', GrantPolicy::CATALOG)) + 2;
        $this->assertSame(50 * $cells, $this->data->batchCalls);
        // Four policy SELECTs per unit plus the one resource scope read of B4.
        $this->assertSame(250, $this->database->queryCount);
        $this->assertSame(50, $this->membershipReads);
        $this->assertSame(50, $this->classifications);
        fwrite(STDERR, sprintf("T15 core: 50 units, %d app SELECTs, %d official config batch reads, 50 membership reads, 50 classifications, %.3f ms (SQLite memory + backend doubles; no member enumeration)\n",
            $this->database->queryCount, $this->data->batchCalls, $elapsed));
    }

}
