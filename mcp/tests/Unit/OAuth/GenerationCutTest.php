<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\OAuth;

use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\PolicyStateSeeder;
use OCA\Mcp\Db\RecheckMapper;
use OCA\Mcp\Migration\Version000500Date20260930000000;
use OCA\Mcp\Migration\Version000700Date20261001000000;
use OCA\Mcp\Migration\Version000800Date20261002000000;
use OCA\Mcp\Migration\Version000800Date20261002010000;
use OCA\Mcp\Migration\Version001002Date20261008000000;
use OCA\Mcp\Migration\Version001100Date20261015000000;
use OCA\Mcp\Migration\Version001100Date20261015010000;
use OCA\Mcp\OAuth\AccessTokenAuthenticator;
use OCA\Mcp\OAuth\AuthorizationRequest;
use OCA\Mcp\OAuth\ClientMetadata;
use OCA\Mcp\OAuth\OAuthException;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\OAuth\TokenHasher;
use OCA\Mcp\OAuth\TokenOwnerGate;
use OCA\Mcp\OAuth\TokenService;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\Events\PolicyEventProcessor;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Tests\Support\Access\Policy\PolicyServices;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * T7 (except the cases of recheck causes, ticket 06), the OAuth part of T11 and T12 on SQLite in memory: the policy
 * writer, the OAuth store, the generation, state and event mappers and the event delivery are the production classes
 * on one real connection; only the official config and the user manager are doubles.
 */
final class GenerationCutTest extends TestCase {
    private const CLIENT = 'https://claude.ai/oauth/mcp-client-metadata';
    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';
    private const RESOURCE = 'https://cloud.example.org/apps/mcp/';
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    private SqliteDatabase $database;
    private IDBConnection $db;
    private InMemoryConfig $data;
    private OAuthStore $store;
    private AuthUsersMapper $authUsers;
    private PolicyStateMapper $state;
    private PolicyServices $services;
    private PolicyTransaction $transaction;
    private GrantPolicy $policy;
    private TokenService $service;
    private AccessTokenAuthenticator $authenticator;
    private TokenHasher $hasher;
    private int $now = 1_760_000_000;
    private bool $aliceEnabled = true;
    /** @var (\Closure(): void)|null runs inside every account read, in the external phase */
    private ?\Closure $onAccountRead = null;
    /** @var array<string, list<string>> members by group id, served by one Database backend */
    private array $groups = [];
    /** @var (\Closure(): void)|null runs once at the next classification, after the lifecycles of a resolution were read */
    private ?\Closure $onGroupExists = null;
    /** Whether the physical cleanup of the OAuth tables fails (the revocation itself still commits). */
    private bool $cleanupFails = false;

    protected function setUp(): void {
        $this->database = new SqliteDatabase($this, [new Version000500Date20260930000000(), new Version000700Date20261001000000(),
            new Version000800Date20261002000000(), new Version000800Date20261002010000(), new Version001002Date20261008000000(),
            new Version001100Date20261015000000($this->createMock(IDBConnection::class)), new Version001100Date20261015010000(),
            new \OCA\Mcp\Migration\Version001100Date20261014000000(), new \OCA\Mcp\Migration\Version001100Date20261015020000()]);
        $this->db = $this->database->connection();
        (new PolicyStateSeeder($this->db))->seed();
        $this->data = new InMemoryConfig();
        $this->data->system['secret'] = 'instance-secret';
        $config = $this->data->mock($this);
        $this->hasher = new TokenHasher($config);
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn (): int => $this->now);
        $alice = $this->createMock(IUser::class);
        $alice->method('getUID')->willReturn('alice');
        $alice->method('isEnabled')->willReturnCallback(fn (): bool => $this->aliceEnabled);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $uid) use ($alice): ?IUser {
            if ($this->onAccountRead !== null) {
                ($this->onAccountRead)();
            }
            return $uid === 'alice' ? $alice : null;
        });
        $apps = $this->createMock(IAppConfig::class);
        $apps->method('hasKey')->willReturnCallback(fn ($app, $key): bool => array_key_exists($key, $this->data->app[$app] ?? []));
        $userConfig = $this->createMock(IUserConfig::class);
        $this->store = new OAuthStore($this->db);
        $this->authUsers = new AuthUsersMapper($this->db);
        $this->state = new PolicyStateMapper($this->db);
        $events = new PolicyEventsMapper($this->db, $time);
        $cleanup = new class($this->db, fn (): bool => $this->cleanupFails) extends OAuthStore {
            public function __construct(IDBConnection $db, private \Closure $fails) { parent::__construct($db); }
            public function deleteUpToGeneration(string $uid, string $cut): void {
                ($this->fails)() ? throw new \RuntimeException('cleanup down') : parent::deleteUpToGeneration($uid, $cut);
            }
            public function deleteBelowEpoch(string $epoch): void {
                ($this->fails)() ? throw new \RuntimeException('cleanup down') : parent::deleteBelowEpoch($epoch);
            }
        };
        $processor = new PolicyEventProcessor($events, $cleanup, new NullLogger(), $time);
        $this->transaction = new PolicyTransaction($this->db, $this->state, $events, $userConfig, $apps, $processor);
        $this->services = new PolicyServices($this->transaction, $config, $apps, $users, $time, $this->state, $events,
            $this->authUsers, new RecheckMapper($this->db), new \OCA\Mcp\Db\GroupGrantsMapper($this->db),
            new \OCA\Mcp\Db\GroupLifeMapper($this->db), $this->store, new \OCA\Mcp\Db\ResourceScopeMapper($this->db));
        $this->policy = new GrantPolicy($config, $userConfig, $this->services->grants, $this->services->service,
            InMemoryConfig::resolverOver($this, $this->state, $this->transaction, $config, $userConfig, $apps, $users, $this->db,
                fn (): array => [new \OCA\Mcp\Tests\Unit\ArrayGroupBackend(fn (): array => $this->groups, 'Database', function (): void {
                    [$hook, $this->onGroupExists] = [$this->onGroupExists, null];
                    $hook !== null && $hook();
                })]), $this->transaction);
        $gate = new TokenOwnerGate($users, $this->policy, $this->services->credentials, $userConfig, $apps, new NullLogger(), InMemoryConfig::noCauses($this));
        $hasher = $this->hasher;
        $this->service = new TokenService($this->store, $hasher, $time, $gate, $this->services->credentials, $this->authUsers, $processor, (new ClientsTable($this))->mapper, $this->transaction, $this->services->holds);
        $this->authenticator = new AccessTokenAuthenticator($this->store, $hasher, $time, $gate, $this->authUsers, $this->state, (new ClientsTable($this))->mapper);
        $this->policy->setGlobalEnabled(true);
        $this->policy->setEligible('alice', true);
    }

    private function consent(): string {
        $request = new AuthorizationRequest(new ClientMetadata(self::CLIENT, 'Claude', [self::REDIRECT]),
            self::REDIRECT, 'xyz', self::CHALLENGE, self::RESOURCE, 'mcp');
        $code = $this->service->consent($request, 'alice');
        $this->assertIsString($code);
        return $code;
    }

    /** @return array<string, string|int> */
    private function exchange(string $code): array {
        return $this->service->exchangeCode(['grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => self::VERIFIER,
            'client_id' => self::CLIENT, 'redirect_uri' => self::REDIRECT, 'resource' => self::RESOURCE]);
    }

    /** @return array<string, string|int> */
    private function refresh(string $token): array {
        return $this->service->refresh(['refresh_token' => $token, 'client_id' => self::CLIENT]);
    }

    private function bearer(array $tokens): ?string {
        return $this->authenticator->authenticate('Bearer ' . $tokens['access_token'], self::RESOURCE)?->getUID();
    }

    private function refused(\Closure $grant): void {
        try {
            $grant();
            $this->fail('Grant accepted');
        } catch (OAuthException $e) {
            $this->assertSame('invalid_grant', $e->error);
        }
    }

    /** @return list<string> auth_gen of every row of an OAuth table */
    private function generations(string $table): array {
        return array_map('strval', array_column($this->database->dbal->fetchAllAssociative('SELECT auth_gen FROM ' . $table . ' ORDER BY auth_gen'), 'auth_gen'));
    }

    /** T12: a 1.0.8 grant (defaults epoch 0, generation 1) and a user without a generation row stay valid. */
    public function testA108GrantStaysValidWithoutAnyGenerationRow(): void {
        $this->policy->setConnected('alice', true);
        // Written as 1.0.8 wrote it: no generation or epoch column named, so both take their defaults.
        $this->database->dbal->insert('mcp_oauth_tokens', ['user_id' => 'alice', 'client_id' => self::CLIENT, 'resource' => self::RESOURCE,
            'scope' => 'mcp', 'access_hash' => $this->hasher->hash('ncmcp_at_legacy'), 'access_expires' => $this->now + 60,
            'refresh_hash' => 'legacy-refresh', 'refresh_expires' => $this->now + 60, 'created_at' => $this->now]);

        $this->assertSame('alice', $this->authenticator->authenticate('Bearer ncmcp_at_legacy', self::RESOURCE)?->getUID());
        $this->assertSame(['1'], $this->generations('mcp_oauth_tokens'));
        $this->assertSame([], $this->database->dbal->fetchAllAssociative('SELECT * FROM mcp_auth_users'), 'nothing was materialized');
        $this->assertSame('0', $this->state->state()['auth_epoch']);
    }

    /** The code carries the next generation, the grant inherits it and rotation and spent records keep it. */
    public function testConsentIssuesTheNextGenerationAndTheLineageKeepsIt(): void {
        $first = $this->exchange($this->consent());
        $this->assertSame(['issue_seq' => '2', 'revoked_below' => '0'], $this->authUsers->generations('alice'));
        $next = $this->refresh($first['refresh_token']);
        $this->assertSame(['2'], $this->generations('mcp_oauth_tokens'));
        $this->assertSame(['2'], $this->generations('mcp_oauth_spent'));
        $this->assertSame('alice', $this->bearer($next));
        $this->assertTrue($this->policy->connected('alice'));
    }

    /** T7 (f): a new consent advances only its own generation; every other session of the user keeps working. */
    public function testAConsentNeverTouchesTheOtherSessionsOfTheUser(): void {
        $first = $this->exchange($this->consent());
        $revision = $this->state->state()['revision'];
        $second = $this->exchange($this->consent());
        $this->assertSame(['issue_seq' => '3', 'revoked_below' => '0'], $this->authUsers->generations('alice'));
        $this->assertSame([$revision, 'alice', 'alice'], [$this->state->state()['revision'], $this->bearer($first), $this->bearer($second)],
            'connected was already on: no policy change, no revocation');
    }

    /** T7: disconnect cuts every generation issued so far, codes not yet exchanged included; a new consent works. */
    public function testDisconnectCutsEveryEarlierCredentialAndANewConsentStartsAbove(): void {
        $grant = $this->exchange($this->consent());
        $pending = $this->consent();
        $this->policy->setConnected('alice', false);

        $this->assertSame(['issue_seq' => '3', 'revoked_below' => '3'], $this->authUsers->generations('alice'));
        $this->assertNull($this->bearer($grant));
        $this->assertSame([], $this->generations('mcp_oauth_tokens'), 'the cleanup after the commit removed them');
        $this->policy->setConnected('alice', true);
        $this->refused(fn () => $this->exchange($pending));
        $this->refused(fn () => $this->refresh($grant['refresh_token']));

        $fresh = $this->exchange($this->consent());
        $this->assertSame('alice', $this->bearer($fresh));
        // A late or repeated cleanup of the old cut never removes the generation issued after it.
        $this->store->deleteUpToGeneration('alice', '3');
        $this->assertSame('alice', $this->bearer($fresh));
    }

    /** The commit of the cut is the revocation: a cleanup that fails leaves rows that never authenticate again. */
    public function testAFailedCleanupKeepsTheRevokedCredentialsUnusableAndIsRetried(): void {
        $grant = $this->exchange($this->consent());
        $this->cleanupFails = true;
        $this->policy->setEligible('alice', false);

        $this->assertSame(['2'], $this->generations('mcp_oauth_tokens'), 'the physical delete failed');
        $this->assertNull($this->bearer($grant));
        $this->policy->setEligible('alice', true);
        $this->policy->setConnected('alice', true);
        $this->refused(fn () => $this->refresh($grant['refresh_token']));
        $pending = array_column($this->database->rows('mcp_policy_events'), 'attempts', 'kind');
        $this->assertSame(1, (int)$pending['cleanup']);

        $this->cleanupFails = false;
        $this->now += 120; // past the first retry delay
        $this->service->housekeeping();
        $this->assertSame([], $this->generations('mcp_oauth_tokens'));
    }

    /** T7: service off moves the global epoch; credentials of the old epoch never come back with the service. */
    public function testServiceOffMovesTheEpochAndOnlyLaterCredentialsWork(): void {
        $grant = $this->exchange($this->consent());
        $pending = $this->consent();
        $this->cleanupFails = true;
        $this->policy->setGlobalEnabled(false);
        $this->assertSame('1', $this->state->state()['auth_epoch']);
        $this->policy->setGlobalEnabled(true);
        $this->policy->setGlobalEnabled(false);
        $this->policy->setGlobalEnabled(true);
        $this->assertSame('2', $this->state->state()['auth_epoch'], 'each explicit disable moves it');

        $this->assertNull($this->bearer($grant));
        $this->refused(fn () => $this->refresh($grant['refresh_token']));
        $this->refused(fn () => $this->exchange($pending));
        $this->cleanupFails = false;
        $this->policy->setConnected('alice', true);
        $fresh = $this->exchange($this->consent());
        $this->assertSame('alice', $this->bearer($fresh));
    }

    /**
     * Review F1: the service was switched off outside the app (occ writes the raw value, no epoch moves) while grants
     * existed and nobody used them. Disabling it again from the app still revokes them, as 1.0.8 deleted them.
     */
    public function testDisablingAnAlreadyOffServiceStillRevokesEveryEarlierCredential(): void {
        $grant = $this->exchange($this->consent());
        $this->data->app['mcp'][GrantPolicy::SERVICE_KEY] = '0';
        $this->cleanupFails = true; // only the logical revocation counts

        $this->policy->setGlobalEnabled(false);
        $this->assertSame('1', $this->state->state()['auth_epoch']);
        $this->policy->setGlobalEnabled(true);

        $this->assertNull($this->bearer($grant));
        $this->refused(fn () => $this->refresh($grant['refresh_token']));
        $this->assertSame(['issue_seq' => '2', 'revoked_below' => '0'], $this->authUsers->generations('alice'),
            'the epoch alone revoked it, no per-user cut was needed');
    }

    /** Cut point (iii): an owner observed unusable is cut at the generation read before the check, never above. */
    public function testAnUnusableOwnerObservedInUseIsCutAtTheGenerationReadBefore(): void {
        $grant = $this->exchange($this->consent());
        $this->aliceEnabled = false;
        $this->assertNull($this->bearer($grant));
        $this->assertSame(['issue_seq' => '2', 'revoked_below' => '2'], $this->authUsers->generations('alice'));

        $this->aliceEnabled = true;
        $this->refused(fn () => $this->refresh($grant['refresh_token']));
        $fresh = $this->exchange($this->consent());
        $this->assertSame('alice', $this->bearer($fresh), 'the consent after the observation is not touched by it');
    }

    /** A failed read is not an answer: the request fails and nothing is revoked. */
    public function testAnAccountReadErrorFailsTheRequestAndRevokesNothing(): void {
        $grant = $this->exchange($this->consent());
        $this->onAccountRead = static fn () => throw new \RuntimeException('user backend down');
        try {
            $this->bearer($grant);
            $this->fail('Read error accepted');
        } catch (PolicyUnavailable) {
        }
        try {
            $this->refresh($grant['refresh_token']);
            $this->fail('Read error accepted');
        } catch (PolicyUnavailable) {
        }
        $this->onAccountRead = null;
        $this->assertSame(['issue_seq' => '2', 'revoked_below' => '0'], $this->authUsers->generations('alice'));
        $this->assertSame('alice', $this->bearer($grant));
        $this->refresh($grant['refresh_token']);
    }

    /** T7: replaying a refresh token spent before a cut cannot revoke the session of the consent made after it. */
    public function testASpentTokenFromBeforeACutNeverRevokesALaterConsent(): void {
        $first = $this->exchange($this->consent());
        $this->refresh($first['refresh_token']);
        $this->cleanupFails = true;
        $this->policy->setConnected('alice', false);
        $this->cleanupFails = false;
        $this->policy->setConnected('alice', true);
        $fresh = $this->exchange($this->consent());

        $this->refused(fn () => $this->refresh($first['refresh_token']));
        $this->assertSame('alice', $this->bearer($fresh));
    }

    /** While its lineage is valid, a replay still revokes every session of that owner and client (1.0.8 rule). */
    public function testAReplayOfAValidSpentTokenStillRevokesTheOwnerAndClient(): void {
        $first = $this->exchange($this->consent());
        $next = $this->refresh($first['refresh_token']);
        $this->refused(fn () => $this->refresh($first['refresh_token']));
        $this->assertNull($this->bearer($next));
    }

    /** §2.1.1: the generations change between the phases (another worker issued) -> the whole unit is replayed. */
    public function testIssuanceRaceIsReplayedAndAPersistentOneIsTemporarilyUnavailable(): void {
        $bump = function (): void {
            // Another worker's committed consent, between this request's external phase and its mutex.
            $this->database->dbal->executeStatement("INSERT INTO mcp_auth_users (user_key, user_id, issue_seq, revoked_below)
                VALUES (?, 'alice', 2, 0) ON CONFLICT (user_key) DO UPDATE SET issue_seq = issue_seq + 1", [hash('sha256', "user\0alice")]);
        };
        $calls = 0;
        $this->onAccountRead = function () use ($bump, &$calls): void {
            if (++$calls === 1) {
                $bump();
            }
        };
        $this->consent();
        $this->assertSame(['issue_seq' => '3', 'revoked_below' => '0'], $this->authUsers->generations('alice'), 'one replay, then issued above the other');

        $this->onAccountRead = $bump;
        $codes = count($this->database->rows('mcp_oauth_codes'));
        $request = new AuthorizationRequest(new ClientMetadata(self::CLIENT, 'Claude', [self::REDIRECT]), self::REDIRECT, 'xyz', self::CHALLENGE, self::RESOURCE, 'mcp');
        try {
            $this->service->consent($request, 'alice');
            $this->fail('A snapshot that never settles was accepted');
        } catch (PolicyUnavailable) {
        }
        $this->assertCount($codes, $this->database->rows('mcp_oauth_codes'), 'nothing was issued');
        $this->assertFalse($this->db->inTransaction());
    }

    /** Consent is one command: when the code cannot be stored, the generation and the revision roll back with it. */
    public function testAConsentThatCannotStoreItsCodeLeavesTheUserDisconnected(): void {
        $this->database->dbal->executeStatement('DROP TABLE mcp_oauth_codes');
        $request = new AuthorizationRequest(new ClientMetadata(self::CLIENT, 'Claude', [self::REDIRECT]), self::REDIRECT, 'xyz', self::CHALLENGE, self::RESOURCE, 'mcp');
        try {
            $this->service->consent($request, 'alice');
            $this->fail('Consent without its code accepted');
        } catch (\Throwable) {
        }
        $this->assertSame(['issue_seq' => '1', 'revoked_below' => '0'], $this->authUsers->generations('alice'));
        // The config double is not transactional; the official config rolling back with it is proven on the real
        // server (tests/Integration/OAuth/GenerationCutTest.php).
        $this->assertSame('2', $this->state->state()['revision'], 'only the two setUp writes: the failed consent left no revision');
    }

    /** The daily job: expired credentials, pending deliveries, expired receipts; receipts are never delivered. */
    public function testTheDailyJobPurgesExpiredRowsAndDeliversPendingEventsOnly(): void {
        $grant = $this->exchange($this->consent());
        $this->cleanupFails = true;
        $this->policy->setConnected('alice', false);
        $this->cleanupFails = false;
        $this->database->dbal->insert('mcp_policy_events', ['id' => str_repeat('a', 32), 'kind' => 'receipt', 'status' => 'pending',
            'payload' => '{}', 'created_at' => $this->now, 'attempts' => 0]);
        $this->database->dbal->insert('mcp_oauth_codes', ['code_hash' => 'expired', 'user_id' => 'bob', 'client_id' => 'c', 'redirect_uri' => 'r',
            'code_challenge' => 'x', 'resource' => 'r', 'scope' => 'mcp', 'expires_at' => $this->now - 1]);
        unset($grant);

        $this->now += \OCA\Mcp\Access\Policy\PolicyLimits::RECEIPT_TTL + 120;
        $job = new \OCA\Mcp\BackgroundJob\PolicyCleanupJob($this->timeAt(), $this->processor());
        $this->assertSame([86400, false], [$job->getInterval(), $job->isTimeSensitive()]);
        (new \ReflectionMethod($job, 'run'))->invoke($job, null);

        $this->assertSame([], $this->generations('mcp_oauth_tokens'));
        $this->assertSame([], $this->database->rows('mcp_oauth_codes'));
        $kinds = array_column($this->database->rows('mcp_policy_events'), 'status', 'kind');
        $this->assertArrayNotHasKey('receipt', $kinds, 'the expired receipt is purged, never delivered');
        $this->assertSame('delivered', $kinds['cleanup']);
    }

    private function processor(): PolicyEventProcessor {
        $time = $this->timeAt();
        return new PolicyEventProcessor(new PolicyEventsMapper($this->db, $time), $this->store, new NullLogger(), $time);
    }

    private function timeAt(): ITimeFactory {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn (): int => $this->now);
        return $time;
    }

    /** Alice eligible only through the Database group "team" (pending item of ticket 06: group cases over Bearer). */
    private function groupEligibleAlice(): void {
        unset($this->data->user['alice']['mcp']['eligible']);
        $this->groups = ['team' => ['alice'], 'staff' => ['alice']];
        InMemoryConfig::storeGroupPolicy($this->database, 'team', ['_system' => ['eligible']]);
    }

    public function testGroupEligibilityAdmitsConsentExchangeRefreshAndBearer(): void {
        $this->groupEligibleAlice();
        $first = $this->exchange($this->consent());
        $this->assertSame('alice', $this->bearer($first));
        $this->assertSame('alice', $this->bearer($this->refresh($first['refresh_token'])));
        $this->assertSame('1', $this->data->user['alice']['mcp']['connected']);
        $this->assertArrayNotHasKey('eligible', $this->data->user['alice']['mcp'], 'consent never writes an individual Allow');
    }

    /** Clarification 6 over Bearer: a sole indeterminate group is unavailable (503), never a cut; it recovers. */
    public function testASoleIndeterminateGroupMakesBearerAndRefreshUnavailableWithoutCutting(): void {
        $this->groupEligibleAlice();
        $grant = $this->exchange($this->consent());
        $generations = $this->authUsers->generations('alice');
        InMemoryConfig::storeGroupState($this->database, 'team', 'needs_confirm');
        foreach ([fn () => $this->bearer($grant), fn () => $this->refresh($grant['refresh_token']), fn () => $this->consent()] as $use) {
            try {
                $use();
                $this->fail('An unknown eligibility was answered');
            } catch (PolicyUnavailable) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame($generations, $this->authUsers->generations('alice'), 'nothing was cut');
        // Another valid source keeps the access while "team" stays indeterminate; the credential was never cut.
        InMemoryConfig::storeGroupPolicy($this->database, 'staff', ['_system' => ['eligible']]);
        $this->assertSame('alice', $this->bearer($grant));
        $this->assertSame('alice', $this->bearer($this->refresh($grant['refresh_token'])));
    }

    /** A group that stops granting is a loss observed in use: the credential is cut like an individual Deny. */
    public function testAGroupThatNoLongerGrantsIsALossObservedInUse(): void {
        $this->groupEligibleAlice();
        $grant = $this->exchange($this->consent());
        InMemoryConfig::storeGroupPolicy($this->database, 'team', []);
        $this->assertNull($this->bearer($grant));
        $this->assertSame(['issue_seq' => '2', 'revoked_below' => '2'], $this->authUsers->generations('alice'));
    }

    /**
     * F3 of the review of ticket 07: a pending_delete between the phases moves no revision, so the consent compares
     * under the mutex the lifecycle gen the admission read; the replay then sees the group no longer grants.
     */
    public function testConsentComparesTheGrantingLifecycleUnderTheMutex(): void {
        $this->groupEligibleAlice();
        $revision = $this->state->state()['revision'];
        $this->onGroupExists = fn () => InMemoryConfig::storeGroupState($this->database, 'team', 'pending_delete');
        try {
            $this->consent();
            $this->fail('A consent issued on a group being deleted');
        } catch (PolicyUnavailable) {
        }
        $this->assertNull($this->onGroupExists, 'the change landed between the phases');
        $this->assertSame([], $this->database->dbal->fetchAllAssociative('SELECT * FROM mcp_oauth_codes'));
        $this->assertSame(['issue_seq' => '1', 'revoked_below' => '0'], $this->authUsers->generations('alice'));
        $this->assertSame($revision, $this->state->state()['revision']);
    }

    /** The same comparison guards exchange and refresh, the other issuances of a group-admitted owner. */
    public function testExchangeAndRefreshCompareTheGrantingLifecycleUnderTheMutex(): void {
        $this->groupEligibleAlice();
        $code = $this->consent();
        $this->onGroupExists = fn () => InMemoryConfig::storeGroupState($this->database, 'team', 'pending_delete');
        try {
            $this->exchange($code);
            $this->fail('A code was exchanged on a group being deleted');
        } catch (PolicyUnavailable) {
        }
        $this->assertSame([], $this->generations('mcp_oauth_tokens'));
        $this->assertCount(1, $this->database->dbal->fetchAllAssociative('SELECT * FROM mcp_oauth_codes'), 'the code is not consumed');

        InMemoryConfig::storeGroupState($this->database, 'team', 'active');
        $grant = $this->exchange($code);
        $this->onGroupExists = fn () => InMemoryConfig::storeGroupState($this->database, 'team', 'pending_delete');
        try {
            $this->refresh($grant['refresh_token']);
            $this->fail('A refresh rotated on a group being deleted');
        } catch (PolicyUnavailable) {
        }
        InMemoryConfig::storeGroupState($this->database, 'team', 'active');
        $this->assertSame('alice', $this->bearer($grant), 'the refresh token was not rotated');
    }
}
