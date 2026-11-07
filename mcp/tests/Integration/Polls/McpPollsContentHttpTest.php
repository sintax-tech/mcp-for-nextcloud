<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Polls;

use OCA\Mcp\Tests\Integration\Support\ServiceSwitchFixture;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\{LoginFactory, McpClient, Credential};
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

/** Real Basic/Bearer reads against native services, not a claim about real AI clients or all pinned runtimes. */
#[Group('polls')]
final class McpPollsContentHttpTest extends IntegrationTestCase {
	/** @var ServiceSwitchFixture|null Per-test service switch, absent when the opt-in skips setup. */
	private ?ServiceSwitchFixture $serviceSwitch = null;

	/** @var array<int,array{id:int,options:list<int>}> scoped native fixtures */
	private static array $scene = [];
	/** @var array<string,Credential> credentials, never printed */
	private static array $credentials = [];

	/** @return void native setup is separate from every observed read-only snapshot */
	protected function setUp(): void {
		parent::setUp();
		if (getenv('MCP_IT_WITH_POLLS') !== '1') {
			$this->markTestSkipped('Official pinned Polls cell only');
		}
		Server::get(\OCP\App\IAppManager::class)->loadApp('polls');
		if (self::$scene === []) {
			self::$scene = PollsContentActor::run(PollsScene::OWNER, 'prepare');
		}
		$policy = Server::get(GrantPolicy::class);
		foreach (PollsScene::USERS as $uid) {
			$policy->setGrant($uid, 'polls', 'results', true);
			$policy->setGrant($uid, 'polls', 'links', true);
		}

		$this->serviceSwitch = ServiceSwitchFixture::open();
	}

	/** @return void Restore the service switch even when the HTTP test fails. */
	protected function tearDown(): void {
		try {
			$this->serviceSwitch?->restore();
		} finally {
			parent::tearDown();
		}
	}


	/**
	 * Authenticated real MCP endpoint.
	 * @param string $actor fixture user
	 * @param string $kind basic or bearer
	 * @return McpClient client
	 */
	private function client(string $actor, string $kind = 'basic'): McpClient {
		$key = $actor . ':' . $kind;
		$credential = self::$credentials[$key] ??= ($kind === 'basic' ? LoginFactory::basic($actor) : LoginFactory::bearer($actor));
		return new McpClient($credential);
	}

	/** @return void both credential routes preserve native own/all/EDIT and every Polls table stays unchanged */
	public function testBasicBearerNativeComparisonAndDatabaseIntegrity(): void {
		$before = PollsScene::snapshot();
		$id = self::$scene[1]['id'];
		foreach (['basic', 'bearer'] as $kind) {
			foreach ([PollsScene::OWNER, PollsScene::VIEWER, PollsScene::DELEGATE] as $actor) {
				$native = PollsContentActor::run($actor, 'native', ['poll_id' => $id]);
				$reply = $this->client($actor, $kind)->tool('polls_list_votes', ['poll_id' => $id]);
				$this->assertTrue($reply->ok(), $reply->describe());
				$data = $reply->json();
				$this->assertSame($native['all'] ? 'all' : 'own', $data['scope']);
				$this->assertEqualsCanonicalizing(array_column($native['votes'], 'id'), array_column($data['votes'], 'id'));
				$this->assertArrayNotHasKey('total', $data);
				foreach ($data['votes'] as $vote) {
					$this->assertSame(['id', 'pollId', 'optionId', 'optionText', 'answer', 'deleted', 'user'], array_keys($vote));
					$this->assertSame(['id', 'type', 'displayName'], array_keys($vote['user']));
				}
				$reply = $this->client($actor, $kind)->tool('polls_read_results', ['poll_id' => $id]);
				$this->assertTrue($reply->ok(), $reply->describe());
				$data = $reply->json();
				$expected = array_column($native['options'], 'votes', 'id');
				$this->assertSame(PollsNativeEquality::keys($expected), PollsNativeEquality::keys(array_column($data['options'], 'votes', 'id')));
				$this->assertSame(['yes' => 0, 'no' => 0, 'maybe' => 0, 'count' => 0], $data['options'][2]['votes']);
				$reply = $this->client($actor, $kind)->tool('polls_list_links', ['poll_id' => $id]);
				$this->assertSame($native['edit'], $reply->ok());
				if ($native['edit']) {
					$links = $reply->json()['links'];
					$nativeUrls = array_column($native['links'], 'url', 'id');
					$sameContext = PollsContentActor::run($actor, 'observe', ['tool' => 'polls_list_links', 'arguments' => ['poll_id' => $id]]);
					$this->assertArrayHasKey('answer', $sameContext);
					$returnedUrls = array_column($sameContext['answer']['links'], 'url', 'id');
					ksort($nativeUrls);
					ksort($returnedUrls);
					$this->assertTrue($nativeUrls === $returnedUrls, 'URLs match native getURL in the identical bootstrap context; credentials not printed');
					$httpUrls = array_column($links, 'url', 'id');
					$this->assertEqualsCanonicalizing(array_keys($nativeUrls), array_keys($httpUrls));
					foreach ($httpUrls as $shareId => $url) {
						$this->assertSame(PollsNativeEquality::routingPath($nativeUrls[$shareId]), PollsNativeEquality::routingPath($url));
						$this->assertSame(parse_url($nativeUrls[$shareId], PHP_URL_QUERY), parse_url($url, PHP_URL_QUERY));
						$this->assertSame(parse_url($nativeUrls[$shareId], PHP_URL_FRAGMENT), parse_url($url, PHP_URL_FRAGMENT));
						$this->assertSame('localhost', parse_url($url, PHP_URL_HOST));
					}
					$this->assertContains('external', array_column($links, 'type'));
					foreach ($links as $link) {
						$this->assertSame(['id', 'type', 'url', 'locked'], array_keys($link));
					}
				}
			}
			foreach ([PollsScene::ADMIN, PollsScene::OUTSIDER] as $actor) {
				foreach (['polls_list_votes', 'polls_read_results', 'polls_list_links'] as $tool) {
					$this->assertFalse($this->client($actor, $kind)->tool($tool, ['poll_id' => $id])->ok());
				}
			}
		}
		$this->assertSame($before, PollsScene::snapshot(), 'All native Polls tables unchanged by reads');
	}

	/** @return void native anonymous identities group within a poll but neither hash nor pseudonym survives projection */
	public function testNativeAnonymousIntraInterPollAndOwnException(): void {
		$before = PollsScene::snapshot();
		$identities = [];
		foreach ([1, 2] as $index) {
			$id = self::$scene[$index]['id'];
			$native = PollsContentActor::run(PollsScene::OWNER, 'native', ['poll_id' => $id]);
			$reply = $this->client(PollsScene::OWNER)->tool('polls_list_votes', ['poll_id' => $id]);
			$this->assertTrue($reply->ok(), $reply->describe());
			$rows = $reply->json()['votes'];
			$anonymous = array_values(array_filter($rows, static fn ($v) => $v['user']['type'] === 'anonymous'));
			$this->assertCount(2, $anonymous);
			$this->assertSame($anonymous[0]['user'], $anonymous[1]['user']);
			$this->assertStringStartsWith('anon1.', $anonymous[0]['user']['id']);
			$this->assertSame('Anonymous', $anonymous[0]['user']['displayName']);
			$encoded = json_encode($reply->json(), JSON_THROW_ON_ERROR);
			foreach ($native['votes'] as $vote) {
				if ($vote['user']['type'] === 'anonymous') {
					$this->assertFalse(str_contains($encoded, $vote['user']['id']), 'Native anonymous hash absent');
					$this->assertNotContains($vote['user']['displayName'], array_column(array_column($rows, 'user'), 'displayName'), 'Native pseudonym replaced by generic label (8.6 literal Anon is a prefix of Anonymous)');
				}
			}
			$own = array_values(array_filter($rows, static fn ($v) => $v['user']['type'] !== 'anonymous'));
			$this->assertCount(2, $own);
			$this->assertSame(PollsScene::OWNER, $own[0]['user']['id']);
			$identities[] = $anonymous[0]['user']['id'];
		}
		$this->assertNotSame($identities[0], $identities[1]);
		$this->assertSame($before, PollsScene::snapshot());
	}

	/** @return void positive/zero anonymization and native owner/delegate exceptions are preserved, not reimplemented */
	public function testNativePositiveAndDisabledAnonymity(): void {
		$before = PollsScene::snapshot();
		foreach ([3, 4] as $index) {
			$id = self::$scene[$index]['id'];
			foreach ([PollsScene::OWNER, PollsScene::DELEGATE, PollsScene::VIEWER] as $actor) {
				$native = PollsContentActor::run($actor, 'native', ['poll_id' => $id]);
				$reply = $this->client($actor)->tool('polls_list_votes', ['poll_id' => $id]);
				$this->assertTrue($reply->ok(), $reply->describe());
				$byId = array_column($native['votes'], null, 'id');
				foreach ($reply->json()['votes'] as $vote) {
					$user = $byId[$vote['id']]['user'];
					$this->assertSame($user['type'], $vote['user']['type']);
					if ($user['type'] === 'anonymous') {
						$this->assertStringStartsWith('anon1.', $vote['user']['id']);
						$this->assertSame('Anonymous', $vote['user']['displayName']);
						$this->assertNotSame($user['id'], $vote['user']['id']);
					} else {
						$this->assertSame(PollsNativeEquality::keys($user), PollsNativeEquality::keys($vote['user']));
					}
				}
			}
		}
		$this->assertSame($before, PollsScene::snapshot());
	}

	/** @return void hidden/closed results stay own/null, then native expiration permits aggregates; cursor state changes */
	public function testNativeNeverClosedExpirationAndCursorRevalidation(): void {
		$id = self::$scene[1]['id'];
		$client = $this->client(PollsScene::VIEWER);
		$first = $client->tool('polls_list_votes', ['poll_id' => $id, 'limit' => 1]);
		$this->assertTrue($first->ok(), $first->describe());
		$cursor = $first->json()['nextCursor'];
		try {
			foreach ([['showResults' => 'never', 'expire' => 0], ['showResults' => 'closed', 'expire' => time() + 3600]] as $config) {
				PollsContentActor::run(PollsScene::OWNER, 'configure', ['poll_id' => $id, 'config' => $config]);
				$before = PollsScene::snapshot();
				$votes = $client->tool('polls_list_votes', ['poll_id' => $id]);
				$this->assertTrue($votes->ok(), $votes->describe());
				$this->assertSame('own', $votes->json()['scope']);
				$this->assertCount(2, $votes->json()['votes']);
				foreach ($votes->json()['votes'] as $vote) {
					$this->assertSame(PollsScene::VIEWER, $vote['user']['id']);
				}
				$results = $client->tool('polls_read_results', ['poll_id' => $id]);
				$this->assertTrue($results->ok(), $results->describe());
				$this->assertNull($results->json()['options']);
				$this->assertFalse($results->json()['resultsVisible']);
				$this->assertFalse($client->tool('polls_list_votes', ['poll_id' => $id, 'limit' => 1, 'cursor' => $cursor])->ok());
				$this->assertSame($before, PollsScene::snapshot());
			}
			PollsContentActor::run(PollsScene::OWNER, 'configure', ['poll_id' => $id, 'config' => ['showResults' => 'closed', 'expire' => time() - 1]]);
			$results = $client->tool('polls_read_results', ['poll_id' => $id]);
			$this->assertTrue($results->ok(), $results->describe());
			$this->assertTrue($results->json()['resultsVisible']);
		} finally {
			PollsContentActor::run(PollsScene::OWNER, 'configure', ['poll_id' => $id, 'config' => ['showResults' => 'always', 'expire' => 0]]);
		}
	}

	/** @return void real context cleans stale identity/token; divergent and nonlogged endpoint actors are refused */
	public function testActorWarmTokenDivergenceAndFinally(): void {
		$id = self::$scene[1]['id'];
		$before = PollsScene::snapshot();
		foreach (['polls_list_votes', 'polls_read_results', 'polls_list_links'] as $tool) {
			$in = ['tool' => $tool, 'arguments' => ['poll_id' => $id], 'warm' => PollsScene::VIEWER];
			$result = PollsContentActor::run(PollsScene::OWNER, 'observe', $in);
			$this->assertArrayHasKey('answer', $result);
			$this->assertSame([null, null, null], $result['residue']);
			$result = PollsContentActor::run(PollsScene::OWNER, 'observe', $in + ['session' => PollsScene::VIEWER]);
			$this->assertArrayHasKey('error', $result);
			$result = PollsContentActor::run(PollsScene::OWNER, 'observe', $in + ['noLogin' => true]);
			$this->assertArrayHasKey('error', $result);
			$result = PollsContentActor::run(PollsScene::OWNER, 'observe', ['tool' => $tool, 'arguments' => ['poll_id' => 2147483000]]);
			$this->assertArrayHasKey('error', $result);
			$this->assertSame([null, null, null], $result['residue']);
		}
		$this->assertSame($before, PollsScene::snapshot());
	}

	/** @return void independent grants apply at the authenticated endpoint without granting other reads */
	public function testHttpIndependentGrantsAndNativeEditStillRequired(): void {
		$id = self::$scene[1]['id'];
		$policy = Server::get(GrantPolicy::class);
		try {
			$policy->setGrant(PollsScene::OWNER, 'polls', 'read', false);
			$policy->setGrant(PollsScene::OWNER, 'polls', 'links', false);
			$client = $this->client(PollsScene::OWNER, 'bearer');
			$this->assertTrue($client->tool('polls_list_votes', ['poll_id' => $id])->ok());
			$this->assertTrue($client->tool('polls_read_results', ['poll_id' => $id])->ok());
			$this->assertFalse($client->tool('polls_read_poll', ['poll_id' => $id])->ok());
			$this->assertFalse($client->tool('polls_list_links', ['poll_id' => $id])->ok());
			$policy->setGrant(PollsScene::OWNER, 'polls', 'results', false);
			$policy->setGrant(PollsScene::OWNER, 'polls', 'links', true);
			$this->assertTrue($client->tool('polls_list_links', ['poll_id' => $id])->ok());
			$this->assertFalse($client->tool('polls_list_votes', ['poll_id' => $id])->ok());
		} finally {
			foreach (['read', 'results', 'links'] as $operation) {
				$policy->setGrant(PollsScene::OWNER, 'polls', $operation, true);
			}
		}
	}
}
