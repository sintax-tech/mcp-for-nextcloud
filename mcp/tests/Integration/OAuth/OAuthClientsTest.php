<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\OAuth;

use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\OAuthClientsMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\OAuth\AccessTokenAuthenticator;
use OCA\Mcp\OAuth\AuthorizationRequest;
use OCA\Mcp\OAuth\CustomClient;
use OCA\Mcp\OAuth\NativeClient;
use OCA\Mcp\OAuth\OAuthClientsService;
use OCA\Mcp\OAuth\OAuthException;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\OAuth\TokenHasher;
use OCA\Mcp\OAuth\TokenOwnerGate;
use OCA\Mcp\OAuth\TokenService;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\OAuth\Revocation\CauseProcessor;
use OCA\Mcp\Access\Policy\Transactions\PolicyConflict;
use OCA\Mcp\Access\Policy\Events\PolicyEventProcessor;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * PR #8 on the real server (review findings F2, F3 and the storage question): the administrator's OAuth client
 * settings are saved through the production OAuthClientsService, one policy command with the page's revision.
 * A second real database session observes what is committed; a deletion that fails inside the command rolls the whole
 * change back; and a unit that read the old settings in its external phase is refused after the withdrawal commits.
 */
final class OAuthClientsTest extends OAuthAccountTestCase {
    private const URI_X = 'https://harness.example.com/oauth/callback';
    private const URI_Y = 'https://harness.example.com/second';
    private const OTHER = 'https://other.example.org/cb';

    /** @var list<array<string, mixed>> the rows of the clients table before the test, put back afterwards */
    private array $saved = [];

    protected function setUp(): void {
        parent::setUp();
        $qb = $this->official()->getQueryBuilder();
        $this->saved = $qb->select('*')->from(OAuthClientsMapper::TABLE)->executeQuery()->fetchAll();
    }

    protected function tearDown(): void {
        // Test-only reset of the app's own table (and of the stale legacy values a test wrote).
        $db = $this->official();
        while ($db->inTransaction()) {
            $db->rollBack();
        }
        $db->getQueryBuilder()->delete(OAuthClientsMapper::TABLE)->executeStatement();
        foreach ($this->saved as $row) {
            $qb = $db->getQueryBuilder();
            $qb->insert(OAuthClientsMapper::TABLE);
            foreach ($row as $column => $value) {
                $qb->setValue($column, $qb->createNamedParameter($value));
            }
            $qb->executeStatement();
        }
        $apps = Server::get(IAppConfig::class);
        $apps->deleteKey('mcp', CustomClient::CLIENTS_KEY);
        $apps->deleteKey('mcp', NativeClient::CONFIG_KEY);
        $apps->clearCache(false);
        parent::tearDown();
    }

    private function revision(): string {
        return Server::get(PolicyStateMapper::class)->state()['revision'];
    }

    /** @param list<array<string, mixed>> $clients the whole list, as the admin page sends it */
    private function save(array $clients, ?OAuthClientsService $service = null): void {
        ($service ?? Server::get(OAuthClientsService::class))->update($this->revision(), null, null, $clients);
    }

    /** @return list<array<string, mixed>> the stored clients, as the page would send them back */
    private function listed(): array {
        return array_map(static fn (array $c): array => array_diff_key($c, ['gen' => 1]), CustomClient::clients(Server::get(OAuthClientsMapper::class)));
    }

    /** @return array{id:string, clientId:string} */
    private function addClient(string $name, array $uris): array {
        $this->save([...$this->listed(), ['name' => $name, 'enabled' => true, 'redirectUris' => $uris]]);
        $added = array_values(array_filter($this->listed(), static fn (array $c): bool => $c['name'] === $name))[0];
        return ['id' => $added['id'], 'clientId' => CustomClient::clientId($added['id'])];
    }

    private function edit(string $id, ?array $changes): void {
        $list = [];
        foreach ($this->listed() as $client) {
            if ($client['id'] === $id) {
                if ($changes === null) {
                    continue;
                }
                $client = $changes + $client;
            }
            $list[] = $client;
        }
        $this->save($list);
    }

    /** @return list<array<string, mixed>> the stored self-hosted clients, as another session reads them from the table */
    private function stored(IDBConnection $db): array {
        return CustomClient::clients(new OAuthClientsMapper($db));
    }

    private function requestFor(string $clientId, string $redirect): AuthorizationRequest {
        return new AuthorizationRequest(CustomClient::metadata(CustomClient::find(Server::get(OAuthClientsMapper::class), $clientId)),
            $redirect, 'xyz', self::CHALLENGE, self::RESOURCE, 'mcp');
    }

    /** @return array<string, string|int> */
    private function issue(string $clientId, string $redirect, ?TokenService $tokens = null): array {
        $tokens ??= Server::get(TokenService::class);
        $code = $tokens->consent($this->requestFor($clientId, $redirect), $this->uid);
        $this->assertIsString($code);
        return $tokens->exchangeCode(['code' => $code, 'code_verifier' => self::VERIFIER, 'client_id' => $clientId,
            'redirect_uri' => $redirect, 'resource' => self::RESOURCE]);
    }

    /** @return int the rows of the client in the OAuth tables, as another session reads them */
    private function rows(IDBConnection $db, string $clientId): int {
        $count = 0;
        foreach (['mcp_oauth_tokens', 'mcp_oauth_codes', 'mcp_oauth_spent'] as $table) {
            $qb = $db->getQueryBuilder();
            $count += (int)$qb->select($qb->func()->count('*'))->from($table)
                ->where($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId)))->executeQuery()->fetchOne();
        }
        return $count;
    }

    /** F2 of the review on two real sessions: the second administrator's page is out of date, so nothing of it is stored. */
    public function testAnOutdatedAdminPageIsAConflictAndNeverReEnablesWhatAnotherAdminTurnedOff(): void {
        $second = $this->openIndependentConnection();
        $c = $this->addClient('C', [self::URI_X]);
        $d = $this->addClient('D', [self::OTHER]);
        $page = $this->listed();
        $pageRevision = $this->revision();

        $this->edit($c['id'], ['enabled' => false]);
        $this->assertSame([false, true], array_column($this->stored($second), 'enabled'), 'the second session sees the commit');

        $stale = array_map(static fn (array $client): array => $client['id'] === $d['id'] ? ['name' => 'D renamed'] + $client : $client, $page);
        try {
            Server::get(OAuthClientsService::class)->update($pageRevision, null, null, $stale);
            $this->fail('A save from an outdated page was accepted');
        } catch (PolicyConflict) {
        }
        $this->assertSame([false, true], array_column($this->stored($second), 'enabled'));
        $this->assertSame(['C', 'D'], array_column($this->stored($second), 'name'));
        $this->assertFalse($this->official()->inTransaction());
    }

    /** F3 with an injected failure: the delete that fails inside the command rolls the whole withdrawal back. */
    public function testAFailedCleanupRollsTheWholeWithdrawalBackAndLeavesNothingHalfRevoked(): void {
        $second = $this->openIndependentConnection();
        $client = $this->addClient('QwenPaw', [self::URI_X, self::URI_Y]);
        $tokens = $this->issue($client['clientId'], self::URI_X);
        $before = [$this->stored($second), $this->rows($second, $client['clientId']), $this->revision()];
        $this->assertSame(1, $before[1]);

        $failing = new class($this->official()) extends OAuthStore {
            public function deleteForClient(string $clientId): void {
                parent::deleteForClient($clientId);
                throw new RuntimeException('Injected after the delete');
            }
        };
        $service = new OAuthClientsService(Server::get(PolicyTransaction::class), Server::get(IConfig::class), Server::get(OAuthClientsMapper::class), $failing);
        foreach (['uri' => ['redirectUris' => [self::URI_Y]], 'off' => ['enabled' => false], 'delete' => null] as $changes) {
            $list = [];
            foreach ($this->listed() as $listed) {
                if ($listed['id'] === $client['id']) {
                    if ($changes === null) {
                        continue;
                    }
                    $listed = $changes + $listed;
                }
                $list[] = $listed;
            }
            try {
                $this->save($list, $service);
                $this->fail('Injected failure swallowed');
            } catch (RuntimeException $e) {
                $this->assertSame('Injected after the delete', $e->getMessage());
            }
            $this->assertFalse($this->official()->inTransaction());
            $this->assertSame($before, [$this->stored($second), $this->rows($second, $client['clientId']), $this->revision()],
                'settings, credentials and revision are all as before: nothing is half withdrawn');
            $this->assertSame($this->uid, Server::get(AccessTokenAuthenticator::class)->authenticate('Bearer ' . $tokens['access_token'], self::RESOURCE)?->getUID());
        }
    }

    /** @return array<string, array{string}> */
    public static function unitsProvider(): array {
        return ['exchange' => ['exchange'], 'refresh' => ['refresh'], 'consent' => ['consent']];
    }

    /**
     * F3 on the real database, in both orders. First: the unit read the old list in its external phase and the
     * administrator removes the URI (committing, in its own transaction) before the unit takes the mutex, which then
     * finds the client withdrawn, writes nothing and, for a consent, leaves the user's connection as it was.
     */
    #[DataProvider('unitsProvider')]
    public function testAUnitThatReadTheOldListIssuesNothingAfterTheWithdrawalCommitted(string $unit): void {
        $second = $this->openIndependentConnection();
        $client = $this->addClient('QwenPaw', [self::URI_X, self::URI_Y]);
        $keep = $this->addClient('Other', [self::OTHER]);
        $kept = $this->issue($keep['clientId'], self::OTHER);
        $tokens = $this->issue($client['clientId'], self::URI_X);
        $code = $unit === 'exchange' ? Server::get(TokenService::class)->consent($this->requestFor($client['clientId'], self::URI_X), $this->uid) : null;
        $generations = (new AuthUsersMapper($second))->generations($this->uid);
        $armed = false;
        $hook = function () use (&$armed, $client): void {
            if ($armed) {
                $armed = false;
                $this->edit($client['id'], ['redirectUris' => [self::URI_Y]]);
            }
        };
        $gate = new class(Server::get(IUserManager::class), Server::get(GrantPolicy::class), Server::get(\OCA\Mcp\OAuth\Revocation\CredentialCommands::class),
            Server::get(IUserConfig::class), Server::get(IAppConfig::class), Server::get(LoggerInterface::class),
            Server::get(CauseProcessor::class), $hook) extends TokenOwnerGate {
            public function __construct($users, $policy, $writer, $userConfig, $appConfig, $logger, $causes, private \Closure $hook) {
                parent::__construct($users, $policy, $writer, $userConfig, $appConfig, $logger, $causes);
            }

            public function admission(string $uid, bool $connection): ?array {
                $admitted = parent::admission($uid, $connection);
                ($this->hook)();
                return $admitted;
            }
        };
        $service = new TokenService(Server::get(OAuthStore::class), Server::get(TokenHasher::class), Server::get(ITimeFactory::class), $gate, Server::get(\OCA\Mcp\OAuth\Revocation\CredentialCommands::class), Server::get(AuthUsersMapper::class), Server::get(PolicyEventProcessor::class), Server::get(OAuthClientsMapper::class), Server::get(\OCA\Mcp\Access\Policy\Transactions\PolicyTransaction::class), Server::get(\OCA\Mcp\Access\Grants\Groups\LifecycleHolds::class));

        $armed = true;
        try {
            match ($unit) {
                'exchange' => $service->exchangeCode(['code' => $code, 'code_verifier' => self::VERIFIER, 'client_id' => $client['clientId'],
                    'redirect_uri' => self::URI_X, 'resource' => self::RESOURCE]),
                'refresh' => $service->refresh(['refresh_token' => $tokens['refresh_token'], 'client_id' => $client['clientId']]),
                'consent' => $service->consent($this->requestFor($client['clientId'], self::URI_X), $this->uid),
            };
            $this->fail('The unit issued after the withdrawal');
        } catch (OAuthException $e) {
            $this->assertContains($e->error, ['invalid_grant', 'invalid_client']);
        }
        $this->assertSame(0, $this->rows($second, $client['clientId']), 'nothing of the withdrawn client is stored, issued before or after');
        $this->assertSame(1, $this->rows($second, $keep['clientId']), 'the other client of the same user is untouched');
        $this->assertSame($this->uid, $this->bearer($kept));
        $this->assertNull($this->bearer($tokens));
        $this->assertFalse($this->official()->inTransaction());
        $this->assertSame($generations['revoked_below'], (new AuthUsersMapper($second))->generations($this->uid)['revoked_below'], 'no cut by user');
    }

    /** The other order: the unit committed first, so the withdrawal that follows deletes what it issued. */
    public function testWhatAUnitIssuedBeforeTheWithdrawalIsDeletedByIt(): void {
        $second = $this->openIndependentConnection();
        $client = $this->addClient('QwenPaw', [self::URI_X, self::URI_Y]);
        $keep = $this->addClient('Other', [self::OTHER]);
        $kept = $this->issue($keep['clientId'], self::OTHER);
        $tokens = $this->issue($client['clientId'], self::URI_X);
        $code = Server::get(TokenService::class)->consent($this->requestFor($client['clientId'], self::URI_Y), $this->uid);
        $this->assertSame(2, $this->rows($second, $client['clientId']));

        $this->edit($client['id'], ['enabled' => false]);

        $this->assertSame(0, $this->rows($second, $client['clientId']));
        $this->assertNull($this->bearer($tokens));
        $this->assertSame($this->uid, $this->bearer($kept));
        $this->edit($client['id'], ['enabled' => true]);
        $this->assertNull($this->bearer($tokens), 'turning it on again does not bring the old connection back');
        $this->assertNotNull($code);
        try {
            Server::get(TokenService::class)->exchangeCode(['code' => $code, 'code_verifier' => self::VERIFIER, 'client_id' => $client['clientId'],
                'redirect_uri' => self::URI_Y, 'resource' => self::RESOURCE]);
            $this->fail('A code issued before the withdrawal was exchanged');
        } catch (OAuthException $e) {
            $this->assertSame('invalid_grant', $e->error);
        }
    }

    /**
     * A consent refused under the mutex rolls back the connection it had already turned on: the user stays as before
     * on every session.
     */
    public function testAConsentRefusedByTheWithdrawalLeavesTheUserDisconnectedOnEverySession(): void {
        $second = $this->openIndependentConnection();
        $client = $this->addClient('QwenPaw', [self::URI_X]);
        $stale = $this->requestFor($client['clientId'], self::URI_X);
        $this->edit($client['id'], ['enabled' => false]);
        $this->edit($client['id'], ['enabled' => true]);
        try {
            Server::get(TokenService::class)->consent($stale, $this->uid);
            $this->fail('A consent made before the client was turned off and on again was accepted');
        } catch (OAuthException $e) {
            $this->assertSame('invalid_client', $e->error);
        }
        $users = clone Server::get(IUserConfig::class);
        (new \ReflectionProperty($users, 'connection'))->setValue($users, $second);
        $users->clearCache($this->uid, false);
        $this->assertSame([], $users->getValuesByUsers('mcp', GrantPolicy::CONNECTED_KEY, null, [$this->uid]));
        $this->assertSame(0, $this->rows($second, $client['clientId']));
        $this->assertSame(['issue_seq' => '1', 'revoked_below' => '0'], (new AuthUsersMapper($second))->generations($this->uid));
        // A consent opened now works.
        $this->assertSame($this->uid, $this->bearer($this->issue($client['clientId'], self::URI_X)));
    }

    /**
     * The largest list the validation accepts (20 clients, 20 URIs of 2000 bytes each, about 800 KB of JSON) is stored
     * whole in the app's table and read back whole by another session and by the production reader.
     */
    public function testTheLargestAcceptedListIsStoredAndReadBackWhole(): void {
        $second = $this->openIndependentConnection();
        $list = [];
        for ($client = 0; $client < CustomClient::MAX_CLIENTS; ++$client) {
            $uris = [];
            for ($uri = 0; $uri < CustomClient::MAX_URIS; ++$uri) {
                $base = sprintf('https://h%d-%d.example.com/cb/', $client, $uri);
                $uris[] = $base . str_repeat('a', CustomClient::MAX_LENGTH - strlen($base));
            }
            $list[] = ['name' => 'Client ' . $client, 'enabled' => true, 'redirectUris' => $uris];
        }
        $this->save($list);
        $read = $this->stored($second);
        $this->assertCount(20, $read);
        $this->assertSame(2000, strlen($read[19]['redirectUris'][19]), 'the last URI of the last client is complete');
        $this->assertGreaterThan(780_000, strlen((string)json_encode(array_column($read, 'redirectUris'))));
        $this->assertSame(array_column($list, 'redirectUris'), array_column($read, 'redirectUris'));
        $this->assertSame($read, CustomClient::clients(Server::get(OAuthClientsMapper::class)), 'the production reader reads the same');
        $this->assertSame($list[7]['redirectUris'][3], CustomClient::find(Server::get(OAuthClientsMapper::class), CustomClient::clientId($read[7]['id']))['redirectUris'][3]);
    }

    /**
     * R2 of the re-reading, on the real server: whatever the AppConfig holds (here the stale values a delayed cache
     * loader could publish, written for real and loaded into the cache: client A enabled in generation 1, the native
     * client on), admission reads the app's table. A consent page of generation 1 issues nothing after A was turned
     * off and on again, the native client stays off, and a Bearer of a withdrawn client is refused.
     */
    public function testAStaleAppConfigSnapshotNeverDecidesAdmission(): void {
        $second = $this->openIndependentConnection();
        Server::get(OAuthClientsService::class)->update($this->revision(), null, false, null);
        $client = $this->addClient('QwenPaw', [self::URI_X]);
        $old = $this->requestFor($client['clientId'], self::URI_X);
        $this->assertSame('1', $old->client->generation);
        $tokens = $this->issue($client['clientId'], self::URI_X);
        $this->edit($client['id'], ['enabled' => false]);
        $this->edit($client['id'], ['enabled' => true]);

        $apps = Server::get(IAppConfig::class);
        $apps->setValueString('mcp', CustomClient::CLIENTS_KEY, (string)json_encode([['id' => $client['id'], 'name' => 'QwenPaw',
            'enabled' => true, 'redirectUris' => [self::URI_X], 'gen' => 1]]), true);
        Server::get(IConfig::class)->setAppValue('mcp', NativeClient::CONFIG_KEY, '1');
        $apps->clearCache(false);
        $this->assertSame('1', Server::get(IConfig::class)->getAppValue('mcp', NativeClient::CONFIG_KEY), 'the stale snapshot is loaded');

        try {
            Server::get(TokenService::class)->consent($old, $this->uid);
            $this->fail('A consent of generation 1 was admitted after the client was turned off and on again');
        } catch (OAuthException $e) {
            $this->assertSame('invalid_client', $e->error);
        }
        $this->assertSame(0, $this->rows($second, $client['clientId']), 'nothing issued after the withdrawal, nothing left from before it');
        $this->assertNull($this->bearer($tokens));
        $this->assertFalse(\OCA\Mcp\OAuth\ClientResolver::nativeEnabled(Server::get(OAuthClientsMapper::class)),
            'the native switch in the AppConfig turns nothing on');
        try {
            Server::get(\OCA\Mcp\OAuth\ClientResolver::class)->resolve(NativeClient::CLIENT_ID);
            $this->fail('The native client was admitted from the AppConfig');
        } catch (OAuthException $e) {
            $this->assertSame('invalid_client', $e->error);
        }
        $this->assertSame('2', CustomClient::find(new OAuthClientsMapper($second), $client['clientId'])['gen']);
    }
}
