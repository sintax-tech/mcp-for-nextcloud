<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\OAuth;

use OCA\Mcp\Controller\OAuthController;
use OCA\Mcp\OAuth\AccessTokenAuthenticator;
use OCA\Mcp\OAuth\AuthorizationValidator;
use OCA\Mcp\OAuth\ClientMetadataFetcher;
use OCA\Mcp\OAuth\ClientResolver;
use OCA\Mcp\OAuth\CustomClient;
use OCA\Mcp\OAuth\NativeClient;
use OCA\Mcp\OAuth\OAuthClientsService;
use OCA\Mcp\OAuth\OAuthException;
use OCA\Mcp\OAuth\ResourceUrl;
use OCA\Mcp\OAuth\TokenHasher;
use OCA\Mcp\OAuth\TokenOwnerGate;
use OCA\Mcp\OAuth\TokenService;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * What the administrator withdraws (a self-hosted client turned off or deleted, a redirect URI removed, the native
 * client turned off) is withdrawn for good, whatever was in flight when the decision committed: the consent page
 * opened before it (F1), a code exchange, a refresh and a consent that read the old list in their external phase
 * (F3), and every credential issued before it. The other clients of the same user are untouched.
 *
 * The collaborators are the production classes over the in-memory doubles; the interleaving happens exactly where it
 * can in production, between the external phase of the unit and its local phase under the mutex. The same
 * interleavings on a real database, with the rollback of a failed cleanup, are in Integration/OAuth/OAuthClientsTest.
 */
final class ManagedClientWithdrawalTest extends TestCase {
    private const RESOURCE = 'https://cloud.example.org/apps/mcp/';
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
    private const URI_X = 'https://harness.example.com/oauth/callback';
    private const URI_Y = 'https://harness.example.com/second';
    private const OTHER = 'https://other.example.org/cb';

    private InMemoryConfig $data;
    private IConfig $config;
    private ClientsTable $table;
    private InMemoryOAuthStore $store;
    private OAuthClientsService $clients;
    private TokenService $tokens;
    private AccessTokenAuthenticator $authenticator;
    private OAuthController $controller;
    /** @var array<string, mixed> */
    private array $session = [];
    /** @var \Closure|null runs once, right after the external phase of the next issuance, as another request would */
    private ?\Closure $between = null;
    private int $now = 1_000_000;

    protected function setUp(): void {
        $this->store = new InMemoryOAuthStore();
        $this->data = new InMemoryConfig();
        $this->config = $this->data->mock($this);
        $this->table = new ClientsTable($this);
        $policy = InMemoryConfig::policy($this->config, $this->store);
        $policy->setGlobalEnabled(true);
        $policy->setEligible('alice', true);
        $services = InMemoryConfig::services($this->config, $this->store);
        $writer = $services->transaction;
        $system = $this->createMock(IConfig::class);
        $system->method('getSystemValueString')->willReturn('instance-secret');
        $hasher = new TokenHasher($system);
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn () => $this->now);
        $alice = $this->createMock(IUser::class);
        $alice->method('getUID')->willReturn('alice');
        $alice->method('getDisplayName')->willReturn('Alice');
        $alice->method('isEnabled')->willReturn(true);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(fn (string $uid) => $uid === 'alice' ? $alice : null);
        $hook = function (): void {
            if ($this->between !== null) {
                $run = $this->between;
                $this->between = null;
                $run();
            }
        };
        $gate = new class($users, $policy, $services->credentials, $this->createMock(IUserConfig::class), $this->createMock(IAppConfig::class),
            new NullLogger(), InMemoryConfig::noCauses($this), $hook) extends TokenOwnerGate {
            public function __construct($users, $policy, $writer, $userConfig, $appConfig, $logger, $causes, private \Closure $hook) {
                parent::__construct($users, $policy, $writer, $userConfig, $appConfig, $logger, $causes);
            }

            public function admission(string $uid, bool $connection): ?array {
                $admitted = parent::admission($uid, $connection);
                ($this->hook)();
                return $admitted;
            }
        };
        $this->clients = new OAuthClientsService($writer, $this->config, $this->table->mapper, $this->store);
        $this->tokens = new TokenService($this->store, $hasher, $time, $gate, $services->credentials,
            InMemoryConfig::authUsers($this->config), InMemoryConfig::processor($this->config), $this->table->mapper, $services->transaction, $services->holds);
        $this->authenticator = new AccessTokenAuthenticator($this->store, $hasher, $time, $gate,
            InMemoryConfig::authUsers($this->config), InMemoryConfig::state($this->config), $this->table->mapper);
        $this->controller = $this->controller($users, $alice, $policy);
    }

    private function controller(IUserManager $users, IUser $alice, GrantPolicy $policy): OAuthController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParams')->willReturnCallback(fn () => $this->query);
        $session = $this->createMock(ISession::class);
        $session->method('get')->willReturnCallback(fn (string $key) => $this->session[$key] ?? null);
        $session->method('set')->willReturnCallback(function (string $key, $value): void {
            $this->session[$key] = $value;
        });
        $session->method('remove')->willReturnCallback(function (string $key): void {
            unset($this->session[$key]);
        });
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($alice);
        $urls = $this->createMock(ResourceUrl::class);
        $urls->method('base')->willReturn(self::RESOURCE);
        $urls->method('issuer')->willReturn('https://cloud.example.org');
        $generator = $this->createMock(IURLGenerator::class);
        $generator->method('linkToRoute')->willReturn('/consent');
        $resolver = new ClientResolver($this->createMock(ClientMetadataFetcher::class), $this->table->mapper);
        return new OAuthController('mcp', $request, $userSession, $session, $generator, new AuthorizationValidator($resolver),
            $this->tokens, $policy, $urls);
    }

    /** @var array<string, string> the query of the next authorize() */
    private array $query = [];

    /** The administrator saves the full list; the page's revision is the current one, so only the content matters. */
    private function save(array $clients): void {
        $this->clients->update((string)$this->data->revision, null, null, $clients);
    }

    /**
     * @param list<string> $uris
     * @return array{id:string, clientId:string} the new client, saved with these URIs
     */
    private function addClient(string $name, array $uris, bool $enabled = true): array {
        $stored = array_map(static fn (array $c): array => array_diff_key($c, ['gen' => 1]), CustomClient::clients($this->table->mapper));
        $this->save([...$stored, ['name' => $name, 'enabled' => $enabled, 'redirectUris' => $uris]]);
        $added = array_values(array_filter(CustomClient::clients($this->table->mapper), static fn (array $c): bool => $c['name'] === $name))[0];
        return ['id' => $added['id'], 'clientId' => CustomClient::clientId($added['id'])];
    }

    /** Rewrites one stored client, keeping the others as they are; null deletes it. */
    private function edit(string $id, ?array $changes): void {
        $list = [];
        foreach (CustomClient::clients($this->table->mapper) as $client) {
            $client = array_diff_key($client, ['gen' => 1]);
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

    /** @return string the id of the consent page the user sees for this client and redirect URI */
    private function openConsent(string $clientId, string $redirect): string {
        $this->query = ['client_id' => $clientId, 'redirect_uri' => $redirect, 'response_type' => 'code', 'code_challenge_method' => 'S256',
            'code_challenge' => self::CHALLENGE, 'state' => 'st', 'scope' => 'mcp'];
        $page = $this->controller->authorize();
        $this->assertInstanceOf(TemplateResponse::class, $page);
        $this->assertNotNull($page->getParams()['pending'], (string)($page->getParams()['error'] ?? 'no consent page'));
        return $page->getParams()['pending'];
    }

    private function consentCode(string $clientId, string $redirect): string {
        return $this->tokens->consent(new \OCA\Mcp\OAuth\AuthorizationRequest(
            CustomClient::owns($clientId) ? CustomClient::metadata(CustomClient::find($this->table->mapper, $clientId)) : NativeClient::metadata((string)ClientResolver::nativeGeneration($this->table->mapper)),
            $redirect, 'xyz', self::CHALLENGE, self::RESOURCE, 'mcp'), 'alice');
    }

    /** @return array<string, string|int> */
    private function exchange(string $code, string $clientId, string $redirect): array {
        return $this->tokens->exchangeCode(['grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => self::VERIFIER,
            'client_id' => $clientId, 'redirect_uri' => $redirect, 'resource' => self::RESOURCE]);
    }

    private function bearer(array $tokens): ?string {
        return $this->authenticator->authenticate('Bearer ' . $tokens['access_token'], self::RESOURCE)?->getUID();
    }

    private function assertRefused(callable $action, string $error): void {
        try {
            $action();
            $this->fail($error . ' expected');
        } catch (OAuthException $e) {
            $this->assertSame($error, $e->error);
        }
    }

    // ---- F1: the consent page opened before the withdrawal -------------------------------------------------

    /** @return array<string, array{string}> */
    public static function decisionsProvider(): array {
        return ['allow' => ['allow'], 'deny' => ['deny']];
    }

    #[DataProvider('decisionsProvider')]
    public function testAPendingConsentStillWorksWhileNothingWasWithdrawn(string $decision): void {
        $client = $this->addClient('QwenPaw', [self::URI_X]);
        $response = $this->controller->consent($this->openConsent($client['clientId'], self::URI_X), $decision);
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringStartsWith(self::URI_X . '?', $response->getRedirectURL());
        $this->assertCount($decision === 'allow' ? 1 : 0, $this->store->codes);
    }

    /** Scenario F1 of the review: client A with URI X open on the consent page, the administrator replaces X by Y. */
    #[DataProvider('decisionsProvider')]
    public function testAPendingConsentNeverReturnsToAUriTheAdministratorWithdrew(string $decision): void {
        $client = $this->addClient('QwenPaw', [self::URI_X]);
        $pending = $this->openConsent($client['clientId'], self::URI_X);
        $this->edit($client['id'], ['redirectUris' => [self::URI_Y]]);
        $response = $this->controller->consent($pending, $decision);
        $this->assertInstanceOf(TemplateResponse::class, $response, 'a local error page, never a redirect to the withdrawn URI');
        $this->assertNotNull($response->getParams()['error']);
        $this->assertSame([], $this->store->codes);
        $this->assertSame([], $this->store->tokens);
    }

    /** @return array<string, array{string, string}> */
    public static function withdrawalsProvider(): array {
        $cases = [];
        foreach (['allow', 'deny'] as $decision) {
            foreach (['turned off', 'deleted', 'turned off and on again', 'URI removed and added back'] as $what) {
                $cases[$what . ', ' . $decision] = [$what, $decision];
            }
        }
        return $cases;
    }

    #[DataProvider('withdrawalsProvider')]
    public function testAPendingConsentNeverReturnsToAClientTheAdministratorWithdrew(string $what, string $decision): void {
        $client = $this->addClient('QwenPaw', [self::URI_X]);
        $pending = $this->openConsent($client['clientId'], self::URI_X);
        match ($what) {
            'turned off' => $this->edit($client['id'], ['enabled' => false]),
            'deleted' => $this->edit($client['id'], null),
            'turned off and on again' => [$this->edit($client['id'], ['enabled' => false]), $this->edit($client['id'], ['enabled' => true])],
            'URI removed and added back' => [$this->edit($client['id'], ['redirectUris' => [self::URI_Y]]),
                $this->edit($client['id'], ['redirectUris' => [self::URI_Y, self::URI_X]])],
        };
        $response = $this->controller->consent($pending, $decision);
        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertNotNull($response->getParams()['error']);
        $this->assertSame([], $this->store->codes, 'a re-enabled client does not resurrect the authorization withdrawn');
    }

    /** The redirect URI is compared against the stored list itself, not only through the generation the app advances. */
    #[DataProvider('decisionsProvider')]
    public function testAPendingConsentIsRefusedForAUriMissingFromAListEditedOutsideTheApp(string $decision): void {
        $client = $this->addClient('QwenPaw', [self::URI_X]);
        $pending = $this->openConsent($client['clientId'], self::URI_X);
        $this->table->db->dbal->update(\OCA\Mcp\Db\OAuthClientsMapper::TABLE, ['redirect_uris' => json_encode([self::URI_Y])],
            ['client_id' => $client['clientId']]);
        $this->assertInstanceOf(TemplateResponse::class, $this->controller->consent($pending, $decision));
        $this->assertSame([], $this->store->codes);
    }

    public function testANewConsentAfterTheClientIsEnabledAgainWorks(): void {
        $client = $this->addClient('QwenPaw', [self::URI_X]);
        $this->edit($client['id'], ['enabled' => false]);
        $this->edit($client['id'], ['enabled' => true]);
        $response = $this->controller->consent($this->openConsent($client['clientId'], self::URI_X), 'allow');
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertCount(1, $this->store->codes);
    }

    #[DataProvider('decisionsProvider')]
    public function testAPendingConsentOfTheNativeClientDiesWhenItIsTurnedOff(string $decision): void {
        $this->clients->update((string)$this->data->revision, null, true, null);
        $pending = $this->openConsent(NativeClient::CLIENT_ID, 'http://localhost:53421/oauth/callback');
        $this->clients->update((string)$this->data->revision, null, false, null);
        $response = $this->controller->consent($pending, $decision);
        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame([], $this->store->codes);
    }

    // ---- F3: a unit that read the old list before the withdrawal committed ---------------------------------

    /**
     * Scenario F3 of the review for the exchange: the code is valid and the client enabled when the unit starts; the
     * administrator removes the URI (or turns the client off, or deletes it) before the unit takes the mutex. The
     * withdrawal wins: no token is stored afterwards, for any of the three withdrawals.
     *
     * @return array<string, array{string}>
     */
    public static function retirementsProvider(): array {
        return ['URI removed' => ['uri'], 'turned off' => ['off'], 'deleted' => ['delete']];
    }

    private function retire(string $id, string $how): void {
        match ($how) {
            'uri' => $this->edit($id, ['redirectUris' => [self::URI_Y]]),
            'off' => $this->edit($id, ['enabled' => false]),
            'delete' => $this->edit($id, null),
        };
    }

    #[DataProvider('retirementsProvider')]
    public function testAnExchangeThatReadTheOldListStoresNothingAfterTheWithdrawal(string $how): void {
        $client = $this->addClient('QwenPaw', [self::URI_X, self::URI_Y]);
        $other = $this->addClient('Other', [self::OTHER]);
        $keep = $this->exchange($this->consentCode($other['clientId'], self::OTHER), $other['clientId'], self::OTHER);
        $code = $this->consentCode($client['clientId'], self::URI_X);
        $this->between = fn () => $this->retire($client['id'], $how);
        $this->assertRefused(fn () => $this->exchange($code, $client['clientId'], self::URI_X), 'invalid_grant');
        $this->assertSame([], array_values(array_filter($this->store->tokens, static fn (array $t): bool => $t['client_id'] === $client['clientId'])));
        $this->assertSame([], array_values(array_filter($this->store->codes, static fn (array $t): bool => $t['client_id'] === $client['clientId'])));
        $this->assertSame('alice', $this->bearer($keep), 'the user\'s other client is not cut');
    }

    #[DataProvider('retirementsProvider')]
    public function testARefreshThatReadTheOldListRotatesNothingAfterTheWithdrawal(string $how): void {
        $client = $this->addClient('QwenPaw', [self::URI_X, self::URI_Y]);
        $tokens = $this->exchange($this->consentCode($client['clientId'], self::URI_X), $client['clientId'], self::URI_X);
        $this->between = fn () => $this->retire($client['id'], $how);
        $this->assertRefused(fn () => $this->tokens->refresh(['refresh_token' => $tokens['refresh_token'], 'client_id' => $client['clientId']]), 'invalid_grant');
        $this->assertSame([], array_values($this->store->tokens));
        $this->assertSame([], $this->store->spent);
        $this->assertNull($this->bearer($tokens));
    }

    #[DataProvider('retirementsProvider')]
    public function testAConsentThatReadTheOldListIssuesNoCodeAfterTheWithdrawal(string $how): void {
        $client = $this->addClient('QwenPaw', [self::URI_X, self::URI_Y]);
        $this->between = fn () => $this->retire($client['id'], $how);
        $this->assertRefused(fn () => $this->consentCode($client['clientId'], self::URI_X), 'invalid_client');
        $this->assertSame([], $this->store->codes);
    }

    /** The other order of the same race: the unit commits first, so the withdrawal deletes what it issued. */
    #[DataProvider('retirementsProvider')]
    public function testWhatWasIssuedBeforeTheWithdrawalIsDeletedByIt(string $how): void {
        $client = $this->addClient('QwenPaw', [self::URI_X, self::URI_Y]);
        $other = $this->addClient('Other', [self::OTHER]);
        $keep = $this->exchange($this->consentCode($other['clientId'], self::OTHER), $other['clientId'], self::OTHER);
        $tokens = $this->exchange($this->consentCode($client['clientId'], self::URI_X), $client['clientId'], self::URI_X);
        $pendingCode = $this->consentCode($client['clientId'], self::URI_Y);
        $this->assertSame('alice', $this->bearer($tokens));

        $this->retire($client['id'], $how);

        $this->assertNull($this->bearer($tokens));
        $this->assertSame([], array_values(array_filter($this->store->tokens, static fn (array $t): bool => $t['client_id'] === $client['clientId'])));
        $this->assertSame([], array_values(array_filter($this->store->codes, static fn (array $t): bool => $t['client_id'] === $client['clientId'])));
        $this->assertRefused(fn () => $this->exchange($pendingCode, $client['clientId'], self::URI_Y), 'invalid_grant');
        $this->assertSame('alice', $this->bearer($keep), 'a withdrawal is by client: the same user\'s other client keeps its connection');
    }

    public function testTurningTheClientOnAgainNeverRevivesWhatItIssuedBefore(): void {
        $client = $this->addClient('QwenPaw', [self::URI_X]);
        $tokens = $this->exchange($this->consentCode($client['clientId'], self::URI_X), $client['clientId'], self::URI_X);
        $this->edit($client['id'], ['enabled' => false]);
        $this->edit($client['id'], ['enabled' => true]);
        $this->assertNull($this->bearer($tokens));
        $this->assertRefused(fn () => $this->tokens->refresh(['refresh_token' => $tokens['refresh_token'], 'client_id' => $client['clientId']]), 'invalid_grant');
    }

    public function testAnAccessTokenOfAWithdrawnClientIsRefusedEvenIfItsRowSurvived(): void {
        $client = $this->addClient('QwenPaw', [self::URI_X]);
        $tokens = $this->exchange($this->consentCode($client['clientId'], self::URI_X), $client['clientId'], self::URI_X);
        // The settings alone (a cleanup that did not run, a value changed outside the app) already refuse the Bearer.
        $this->table->custom([]);
        $this->assertCount(1, $this->store->tokens);
        $this->assertNull($this->bearer($tokens));
    }

    /**
     * R2 of the re-reading of PR #8: the AppConfig cache loader may publish a snapshot older than a withdrawal another
     * worker committed (here: client A enabled in generation 1, and the native client on). Admission never reads it: a
     * consent page of generation 1 is refused after the client was turned off and on again, no code is issued after
     * the withdrawal, and a Bearer of a client whose row is gone is refused, whatever the stale snapshot says.
     */
    public function testAStaleAppConfigSnapshotNeverDecidesAdmission(): void {
        $client = $this->addClient('QwenPaw', [self::URI_X]);
        $pending = $this->openConsent($client['clientId'], self::URI_X);
        $this->edit($client['id'], ['enabled' => false]);
        $this->edit($client['id'], ['enabled' => true]);
        // What a delayed loader would publish: the list and the switch as they were before the withdrawal.
        $this->data->app['mcp'][CustomClient::CLIENTS_KEY] = json_encode([['id' => $client['id'], 'name' => 'QwenPaw', 'enabled' => true,
            'redirectUris' => [self::URI_X], 'gen' => 1]]);
        $this->data->app['mcp'][NativeClient::CONFIG_KEY] = '1';

        $this->assertInstanceOf(TemplateResponse::class, $this->controller->consent($pending, 'allow'));
        $this->assertRefused(fn () => $this->tokens->consent(new \OCA\Mcp\OAuth\AuthorizationRequest(
            new \OCA\Mcp\OAuth\ClientMetadata($client['clientId'], 'QwenPaw', [self::URI_X], '1'), self::URI_X, 'xyz', self::CHALLENGE,
            self::RESOURCE, 'mcp'), 'alice'), 'invalid_client');
        $this->assertSame([], $this->store->codes, 'nothing is issued after the withdrawal');

        $this->assertNull(ClientResolver::nativeGeneration($this->table->mapper), 'the native client stays off: the table has no row');
        $this->store->insertToken(['user_id' => 'alice', 'client_id' => NativeClient::CLIENT_ID, 'resource' => self::RESOURCE, 'scope' => 'mcp',
            'access_hash' => 'h', 'access_expires' => $this->now + 100, 'refresh_hash' => 'r', 'refresh_expires' => $this->now + 100], $this->now);
        $this->assertTrue(ClientResolver::builtInDisabled($this->table->mapper, NativeClient::CLIENT_ID));
    }

    /** The native client has a generation too: a consent page opened before it was turned off and on again dies. */
    #[DataProvider('decisionsProvider')]
    public function testAPendingConsentOfTheNativeClientDiesWhenItIsTurnedOffAndOnAgain(string $decision): void {
        $this->clients->update((string)$this->data->revision, null, true, null);
        $pending = $this->openConsent(NativeClient::CLIENT_ID, 'http://localhost:53421/oauth/callback');
        $this->clients->update((string)$this->data->revision, null, false, null);
        $this->clients->update((string)$this->data->revision, null, true, null);
        $this->assertInstanceOf(TemplateResponse::class, $this->controller->consent($pending, $decision));
        // The same decision is taken again under the mutex, from the table: a request of generation 1 issues nothing.
        $this->assertRefused(fn () => $this->tokens->consent(new \OCA\Mcp\OAuth\AuthorizationRequest(NativeClient::metadata('1'),
            'http://localhost:53421/oauth/callback', 'xyz', self::CHALLENGE, self::RESOURCE, 'mcp'), 'alice'), 'invalid_client');
        $this->assertSame([], $this->store->codes);
        $this->assertInstanceOf(RedirectResponse::class, $this->controller->consent($this->openConsent(NativeClient::CLIENT_ID,
            'http://localhost:53421/oauth/callback'), 'allow'), 'a consent opened now works');
    }
}
