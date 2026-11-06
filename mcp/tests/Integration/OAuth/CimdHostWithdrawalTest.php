<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\OAuth;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\{CauseProcessor, PolicyEventProcessor, PolicyWriter};
use OCA\Mcp\Db\{AuthUsersMapper, CimdHostsMapper, OAuthClientsMapper, PolicyStateMapper};
use OCA\Mcp\OAuth\Authorization\{AuthorizationRequest, OAuthException};
use OCA\Mcp\OAuth\Clients\{ClientAdmission, ClientMetadata, ClientMetadataFetcher, ClientResolver, OAuthClientsService};
use OCA\Mcp\OAuth\Storage\{OAuthStore, TokenHasher};
use OCA\Mcp\OAuth\Tokens\{TokenOwnerGate, TokenService};
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\{IAppConfig, IConfig, IUserManager, Server};
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;

/** Host withdrawal on the real server/official transaction, with an independent database observer. */
final class CimdHostWithdrawalTest extends OAuthAccountTestCase {
	protected const CLIENT = 'https://cimd.example.test/metadata';
	private array $savedHosts = [];
	private string $configHosts;

	/** @return void Add an isolated host through the same command the existing admin control uses. */
	protected function setUp(): void {
		parent::setUp();
		$this->savedHosts = Server::get(CimdHostsMapper::class)->all();
		$this->configHosts = Server::get(IConfig::class)->getAppValue('mcp', ClientMetadataFetcher::HOSTS_KEY, '');
		$this->saveHosts([...array_column(array_filter($this->savedHosts, static fn ($r) => $r['enabled']), 'host'), 'cimd.example.test']);
	}

	/** @return void Restore only test-owned state after deleting its account/credentials. */
	protected function tearDown(): void {
		parent::tearDown();
		$db = $this->official();
		$db->getQueryBuilder()->delete(CimdHostsMapper::TABLE)->executeStatement();
		$mapper = new CimdHostsMapper($db);
		foreach ($this->savedHosts as $r) { $mapper->put($r['host'], $r['enabled'], $r['gen']); }
		$config = Server::get(IConfig::class);
		$this->configHosts === '' ? $config->deleteAppValue('mcp', ClientMetadataFetcher::HOSTS_KEY) : $config->setAppValue('mcp', ClientMetadataFetcher::HOSTS_KEY, $this->configHosts);
		Server::get(IAppConfig::class)->clearCache(false);
	}

	/** @param list<string> $hosts Approved hosts. @return void Uses the official writer and revision. */
	private function saveHosts(array $hosts): void {
		Server::get(OAuthClientsService::class)->update(Server::get(PolicyStateMapper::class)->state()['revision'], array_values(array_unique($hosts)), null, null);
	}

	/** @return void Withdraw the isolated host while preserving the defaults used by other tests. */
	private function withdraw(): void {
		$this->saveHosts(array_values(array_diff(array_column(array_filter(Server::get(CimdHostsMapper::class)->all(), static fn ($r) => $r['enabled']), 'host'), ['cimd.example.test'])));
	}

	/** @return AuthorizationRequest Consent snapshot with the authoritative host generation. */
	protected function request(): AuthorizationRequest {
		return new AuthorizationRequest(new ClientMetadata(self::CLIENT, 'Isolated', [self::REDIRECT],
			ClientAdmission::generation(Server::get(OAuthClientsMapper::class), self::CLIENT) ?? '0'), self::REDIRECT, 'xyz', self::CHALLENGE, self::RESOURCE, 'mcp');
	}

	/** @param callable $action Refused operation. @return void No credentials can be issued. */
	private function refused(callable $action): void {
		try { $action(); self::fail('Withdrawn generation was accepted'); }
		catch (OAuthException $e) { self::assertContains($e->error, ['invalid_client', 'invalid_grant']); }
	}

	/** @return void Old page/code/refresh/Bearer stay dead after reinsertion; a new consent works. */
	public function testEveryBoundaryAndReinsertionOnTheMigratedServer(): void {
		$second = $this->openIndependentConnection();
		$pending = $this->request();
		$tokens = $this->exchange($this->consent());
		$code = $this->consent();
		$rotated = Server::get(TokenService::class)->refresh(['client_id' => self::CLIENT, 'refresh_token' => $tokens['refresh_token']]);
		$this->withdraw();
		self::assertFalse((new CimdHostsMapper($second))->find('cimd.example.test')['enabled']);
		$this->refused(fn () => Server::get(ClientResolver::class)->revalidateCimd($pending->client));
		$this->refused(fn () => $this->exchange($code));
		$this->refused(fn () => Server::get(TokenService::class)->refresh(['client_id' => self::CLIENT, 'refresh_token' => $rotated['refresh_token']]));
		self::assertNull($this->bearer($rotated));
		$this->saveHosts([...array_column(array_filter(Server::get(CimdHostsMapper::class)->all(), static fn ($r) => $r['enabled']), 'host'), 'cimd.example.test']);
		$this->refused(fn () => Server::get(ClientResolver::class)->revalidateCimd($pending->client));
		$this->refused(fn () => Server::get(TokenService::class)->consent($pending, $this->uid));
		$this->refused(fn () => $this->exchange($code));
		self::assertNull($this->bearer($rotated));
		self::assertSame($this->uid, $this->bearer($this->exchange($this->consent())));
		foreach (['mcp_oauth_codes', 'mcp_oauth_tokens', 'mcp_oauth_spent'] as $name) {
			$qb = $second->getQueryBuilder();
			$result = $qb->select('client_id')->from($name)->where($qb->expr()->eq('client_id', $qb->createNamedParameter(self::CLIENT)))->executeQuery();
			$rows = $result->fetchAll(); $result->closeCursor();
			self::assertCount($name === 'mcp_oauth_tokens' ? 1 : 0, $rows);
		}
	}

	/** @return array<string,array{string}> Code exchange and refresh are the two racing token units. */
	public static function units(): array { return ['exchange' => ['exchange'], 'refresh' => ['refresh']]; }

	/** @param string $unit In-flight operation. @return void A committed withdrawal between external/read and mutex prevents issuance. */
	#[DataProvider('units')]
	public function testConcurrentWithdrawalWinsBeforeTheTokenUnitAcquiresTheMutex(string $unit): void {
		$second = $this->openIndependentConnection();
		$tokens = $this->exchange($this->consent());
		$code = $this->consent();
		$hook = fn () => $this->withdraw();
		$gate = new class(Server::get(IUserManager::class), Server::get(GrantPolicy::class), Server::get(PolicyWriter::class),
			Server::get(IUserConfig::class), Server::get(IAppConfig::class), Server::get(LoggerInterface::class), Server::get(CauseProcessor::class), $hook) extends TokenOwnerGate {
			private ?\Closure $hook;
			/** @param mixed ...$args Production dependencies and a one-shot interleaving hook. */
			public function __construct(...$args) { $this->hook = array_pop($args); parent::__construct(...$args); }
			/** @param string $uid Owner. @param bool $connection Connection check. @return array|null Admission. */
			public function admission(string $uid, bool $connection): ?array {
				$result = parent::admission($uid, $connection);
				$hook = $this->hook; $this->hook = null;
				if ($hook !== null) { $hook(); }
				return $result;
			}
		};
		$service = new TokenService(Server::get(OAuthStore::class), Server::get(TokenHasher::class), Server::get(ITimeFactory::class), $gate,
			Server::get(PolicyWriter::class), Server::get(AuthUsersMapper::class), Server::get(PolicyEventProcessor::class), Server::get(OAuthClientsMapper::class));
		$this->refused(fn () => $unit === 'exchange'
			? $service->exchangeCode(['code' => $code, 'code_verifier' => self::VERIFIER, 'client_id' => self::CLIENT, 'redirect_uri' => self::REDIRECT, 'resource' => self::RESOURCE])
			: $service->refresh(['client_id' => self::CLIENT, 'refresh_token' => $tokens['refresh_token']]));
		self::assertFalse((new CimdHostsMapper($second))->find('cimd.example.test')['enabled']);
		self::assertNull($this->bearer($tokens));
		self::assertSame([], $this->stamps($second, 'mcp_oauth_tokens'));
		self::assertSame([], $this->stamps($second, 'mcp_oauth_codes'));
		self::assertFalse($this->official()->inTransaction());
	}
}
