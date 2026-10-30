<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Polls;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tools\Polls\{PollsRefusal, PollsAnonymousIdentity, PollsVoteShapes, PollsResultShapes, PollsLinkShapes, PollsContentRequest};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Discriminants for protected reads using the actual handlers/context/MAC and independent native policies. */
final class PollsContentTest extends TestCase {
	/** @var PollsTestRig actual tools */
	private PollsTestRig $rig;
	/** @return void fresh, explicitly granted operation scene */
	protected function setUp(): void {
		$this->rig = new PollsTestRig($this);
		$this->rig->poll(1);
		$this->rig->policy->setGrant('alice', 'polls', 'results', true);
		$this->rig->policy->setGrant('alice', 'polls', 'links', true);
	}

	/**
	 * Adds a native projected row with private fields the MCP must discard.
	 * @param int $id vote id
	 * @param array<string,mixed> $change source fields
	 * @param int $poll native parent
	 * @return void
	 */
	private function vote(int $id, array $change = [], int $poll = 1): void {
		NativeProtectedScene::$votes[$poll][] = $change + ['id' => $id, 'pollId' => $poll, 'optionId' => 10,
			'optionText' => 'a<b and <3 people', 'answer' => 'yes', 'deleted' => 0, 'owner' => 'alice',
			'user' => ['id' => 'alice', 'type' => 'user', 'displayName' => 'Alice', 'email' => 'PRIVATE-EMAIL'], 'userId' => 'PRIVATE-RAW'];
	}

	/**
	 * Adds a native share with secret auxiliary fields.
	 * @param int $id share id
	 * @param array<string,mixed> $change native fields
	 * @return void
	 */
	private function share(int $id, array $change = []): void {
		NativeProtectedScene::$shares[1][] = $change + ['id' => $id, 'pollId' => 1, 'type' => 'public',
			'url' => 'https://cloud.test/sub/polls/s/PRIVATE-CAPACITY-' . $id, 'locked' => 0, 'deleted' => 0,
			'email' => 'PRIVATE-EMAIL', 'token' => 'PRIVATE-SEPARATE-TOKEN', 'voted' => 999];
	}

	/** @return void independent defaults, exact grants and no implicit read dependency */
	public function testIndependentGrantsDefaultDeniedAndNoReadPrerequisite(): void {
		foreach (['results', 'links'] as $operation) {
			$this->assertFalse(GrantPolicy::defaultGranted('polls', $operation));
			$this->assertTrue(GrantPolicy::inCatalog('polls', $operation));
		}
		$this->rig->policy->setGrant('alice', 'polls', 'read', false);
		$this->vote(1);
		$this->assertCount(1, $this->rig->call('polls_list_votes', ['poll_id' => 1])['votes']);
		$this->rig->policy->setGrant('alice', 'polls', 'links', false);
		$this->rig->call('polls_read_results', ['poll_id' => 1]);
		$this->expectException(PollsRefusal::class);
		$this->rig->call('polls_list_links', ['poll_id' => 1]);
	}

	/** @return void read and links never grant results */
	public function testResultsDeniedBeforeNativeRead(): void {
		$this->rig->policy->setGrant('alice', 'polls', 'results', false);
		try {
			$this->rig->call('polls_list_votes', ['poll_id' => 1]);
			$this->fail('Denied');
		} catch (PollsRefusal) {
			$this->assertSame([], NativeScene::$gets);
			$this->assertSame([], NativeProtectedScene::$calls);
		}
	}

	/** @return void scope comes from native RESULTS even when collections are empty; hidden aggregates are null */
	public function testOwnAndAllEmptyAndNativeOwnProjection(): void {
		$this->assertSame('all', $this->rig->call('polls_list_votes', ['poll_id' => 1])['scope']);
		$this->rig->poll(2, 'bob', ['access' => 'open', 'results' => false]);
		$this->assertSame('own', $this->rig->call('polls_list_votes', ['poll_id' => 2])['scope']);
		$result = $this->rig->call('polls_read_results', ['poll_id' => 2]);
		$this->assertNull($result['options']);
		$this->assertFalse($result['resultsVisible']);
		$this->vote(1, [], 2);
		$this->vote(2, ['owner' => 'bob', 'user' => ['id' => 'bob', 'type' => 'user', 'displayName' => 'Bob']], 2);
		$this->assertCount(1, $this->rig->call('polls_list_votes', ['poll_id' => 2])['votes']);
		$this->assertSame(0, NativeScene::$factories);
	}

	/** @return void a foreign row from a faulty own collection is a refusal, not silent filtering */
	public function testOwnForeignVoteRefused(): void {
		$shape = new PollsVoteShapes($this->anonymous());
		$this->vote(1, ['owner' => 'bob']);
		$this->expectException(PollsRefusal::class);
		$shape->project([new \OCA\Polls\Db\Vote(NativeProtectedScene::$votes[1][0])], 1, false, static fn () => null);
	}

	/** @return void anonymous reduction never carries the stable native hash or pseudonym, and binds poll/secret */
	public function testAnonymousIdentityGroupingAcrossPollsAndSecrets(): void {
		$hash = md5('bob');
		$user = ['id' => $hash, 'type' => 'anonymous', 'displayName' => 'Native Stable Pseudonym', 'userId' => 'bob'];
		$this->vote(1, ['owner' => 'bob', 'user' => $user]);
		$this->vote(2, ['owner' => 'bob', 'user' => $user, 'optionId' => 11]);
		$one = $this->rig->call('polls_list_votes', ['poll_id' => 1]);
		$this->assertSame($one['votes'][0]['user'], $one['votes'][1]['user']);
		$this->assertStringStartsWith('anon1.', $one['votes'][0]['user']['id']);
		$this->assertSame('Anonymous', $one['votes'][0]['user']['displayName']);
		$encoded = json_encode($one, JSON_THROW_ON_ERROR);
		foreach ([$hash, 'Native Stable Pseudonym', 'PRIVATE-RAW', 'PRIVATE-EMAIL', 'bob'] as $secret) {
			$this->assertStringNotContainsString($secret, $encoded);
		}
		$this->rig->poll(2);
		$this->vote(3, ['owner' => 'bob', 'user' => $user], 2);
		$two = $this->rig->call('polls_list_votes', ['poll_id' => 2]);
		$this->assertNotSame($one['votes'][0]['user']['id'], $two['votes'][0]['user']['id']);
		NativeProtectedScene::$secret = 'OTHER-SECRET';
		$this->assertNotSame($one['votes'][0]['user']['id'], $this->rig->call('polls_list_votes', ['poll_id' => 1])['votes'][0]['user']['id']);
	}

	/** @return void missing secret refuses rather than exposing a native identity */
	public function testAnonymousEmptySecretRefuses(): void {
		NativeProtectedScene::$secret = '';
		$this->vote(1, ['user' => ['id' => md5('bob'), 'type' => 'anonymous', 'displayName' => 'NativeName']]);
		$this->expectException(PollsRefusal::class);
		$this->rig->call('polls_list_votes', ['poll_id' => 1]);
	}

	/** @return void native own exception is unchanged, and 8.6 user objects serialize inside the checked context */
	public function testNativeIdentifiedOwnAndLazyUserShape(): void {
		$user = new class implements \JsonSerializable {
			/** @return array<string,string> native 8.6 shape */
			public function jsonSerialize(): array {
				if (NativeScene::session()->cleans !== 1) {
					throw new \LogicException('Serialized outside context');
				}
				return ['id' => 'alice', 'type' => 'user', 'displayName' => 'Alice', 'email' => 'PRIVATE-EMAIL'];
			}
		};
		$this->vote(1, ['user' => $user]);
		$this->assertSame(['id' => 'alice', 'type' => 'user', 'displayName' => 'Alice'], $this->rig->call('polls_list_votes', ['poll_id' => 1])['votes'][0]['user']);
		$this->assertSame(2, NativeScene::session()->cleans);
	}

	/** @return iterable<string,array{mixed}> invalid non-integer/masked flags */
	public static function flags(): iterable {
		foreach ([0, null, false, true, '1', 2] as $i => $flag) {
			yield 'flag-' . $i => [$flag];
		}
	}

	/**
	 * Visibility is independent from Poll permission and checked outside the requested page too.
	 * @param mixed $flag native joined flag
	 * @return void
	 */
	#[DataProvider('flags')]
	public function testMaskedOrMalformedFlagOutsidePageRefuses(mixed $flag): void {
		$this->rig->option(1, 1, ['votes' => ['yes' => 0, 'no' => 0, 'maybe' => 0, 'count' => 0]]);
		$this->rig->option(2, 1, ['show_results' => $flag, 'votes' => ['yes' => 0, 'no' => 0, 'maybe' => 0, 'count' => 0]]);
		$this->expectException(PollsRefusal::class);
		$this->rig->call('polls_read_results', ['poll_id' => 1, 'limit' => 1]);
	}

	/** @return void genuine zeros are accepted, aggregates match native values without a synthetic global total */
	public function testRealZeroAndNativeCounts(): void {
		$this->rig->option(1, 1, ['votes' => ['yes' => 0, 'no' => 0, 'maybe' => 0, 'count' => 0]]);
		$this->rig->option(2, 1, ['votes' => ['yes' => 3, 'no' => 2, 'maybe' => 1, 'count' => 6]]);
		$result = $this->rig->call('polls_read_results', ['poll_id' => 1]);
		$this->assertSame(['yes' => 0, 'no' => 0, 'maybe' => 0, 'count' => 0], $result['options'][0]['votes']);
		$this->assertSame(6, $result['options'][1]['votes']['count']);
		$this->assertArrayNotHasKey('total', $result);
	}

	/** @return void supported direct links only; fixed coverage does not reveal omitted audiences */
	public function testDirectLinksAndNativeUrlWithoutRecipients(): void {
		$this->rig->version = '9.3.0';
		foreach (['public', 'user', 'admin', 'group', 'email', 'contact', 'external'] as $i => $type) {
			$this->share($i + 1, ['type' => $type, 'locked' => $type === 'admin' ? 1 : 0]);
		}
		$this->share(8, ['type' => 'circle']);
		$this->share(9, ['type' => 'contactGroup']);
		$this->share(10, ['pollId' => null, 'groupId' => 3, 'url' => '']);
		NativeScene::$polls[1]['groups'] = [3];
		$result = $this->rig->call('polls_list_links', ['poll_id' => 1]);
		$this->assertSame(0, NativeProtectedScene::$effects, 'No getEffectiveShare or registration boundary');
		$this->assertCount(7, $result['links']);
		$this->assertSame('user', $result['links'][2]['type']);
		$this->assertTrue($result['links'][2]['locked']);
		$this->assertSame('native-direct-participation-links', $result['coverage']);
		$this->assertSame(NativeProtectedScene::$shares[1][0]['url'], $result['links'][0]['url']);
		$encoded = json_encode($result, JSON_THROW_ON_ERROR);
		foreach (['PRIVATE-EMAIL', 'PRIVATE-SEPARATE-TOKEN', 'voted', 'omitted', 'totalShares', 'groupId'] as $field) {
			$this->assertStringNotContainsString($field, $encoded);
		}
	}

	/** @return void siteadmin is not automatically a native editor */
	public function testAdminWithoutEditDoesNotCollectShares(): void {
		$this->rig->poll(1, 'bob', ['access' => 'open']);
		NativeScene::session()->type = 'admin';
		try {
			$this->rig->call('polls_list_links', ['poll_id' => 1]);
			$this->fail('Native EDIT required');
		} catch (PollsRefusal) {
			$this->assertSame([], NativeProtectedScene::$calls);
		}
	}

	/** @return void native delegated EDIT works without siteadmin */
	public function testDelegatedEditor(): void {
		$this->rig->poll(1, 'bob', ['delegates' => ['alice']]);
		$this->share(1);
		$this->assertCount(1, $this->rig->call('polls_list_links', ['poll_id' => 1])['links']);
	}

	/** @return iterable<string,array{array<string,mixed>}> contradictions actually received, not invisible upstream types */
	public static function badShares(): iterable {
		yield 'foreign poll' => [['pollId' => 99]];
		yield 'foreign inherited group' => [['pollId' => null, 'groupId' => 99]];
		yield 'received unknown' => [['type' => 'future']];
		yield 'bad URL' => [['url' => 'javascript:PRIVATE']];
	}

	/**
	 * Contradictory shares refuse the whole response without an audience hint.
	 * @param array<string,mixed> $bad native fields
	 * @return void
	 */
	#[DataProvider('badShares')]
	public function testBadShareRefused(array $bad): void {
		$this->share(1, $bad);
		$this->expectException(PollsRefusal::class);
		$this->rig->call('polls_list_links', ['poll_id' => 1]);
	}

	/** @return void changed contents, scope, option filter, limit, actor and tool cannot replay the cursor */
	public function testCursorRoundTripAndDigestChange(): void {
		$this->vote(1);
		$this->vote(2);
		$first = $this->rig->call('polls_list_votes', ['poll_id' => 1, 'limit' => 1]);
		$this->assertTrue($first['hasMore']);
		$second = $this->rig->call('polls_list_votes', ['poll_id' => 1, 'limit' => 1, 'cursor' => $first['nextCursor']]);
		$this->assertSame(2, $second['votes'][0]['id']);
		$this->assertSame(['o' => 1], json_decode(base64_decode(strtr(explode('.', $first['nextCursor'])[1], '-_', '+/')), true));
		NativeProtectedScene::$votes[1][1]['answer'] = 'no';
		$this->expectException(PollsRefusal::class);
		$this->rig->call('polls_list_votes', ['poll_id' => 1, 'limit' => 1, 'cursor' => $first['nextCursor']]);
	}

	/** @return void options filter runs only on authorized projection, missing ids do not create existence queries */
	public function testOptionFilterAndMalformedTextLiteral(): void {
		$this->vote(1, ['optionText' => "<3 people\xFF\u{200e}\u{0081}a<b"]);
		$this->vote(2, ['optionId' => 11]);
		$one = $this->rig->call('polls_list_votes', ['poll_id' => 1, 'option_id' => 10, 'limit' => 1]);
		$this->assertCount(1, $one['votes']);
		$this->assertStringStartsWith('<3 people', $one['votes'][0]['optionText']);
		$this->assertStringEndsWith('a<b', $one['votes'][0]['optionText']);
		$this->assertStringNotContainsString("\u{200e}", $one['votes'][0]['optionText']);
		$this->assertSame([], $this->rig->call('polls_list_votes', ['poll_id' => 1, 'option_id' => 999])['votes']);
	}

	/** @return void no login can reach native serialization; both UID sources and cleanup remain required */
	public function testNoLoginRefusesBeforeSerialization(): void {
		$this->vote(1);
		$this->rig->uid = null;
		try {
			$this->rig->call('polls_list_votes', ['poll_id' => 1]);
			$this->fail('Not logged in');
		} catch (PollsRefusal) {
			$this->assertSame(0, NativeProtectedScene::$serializations);
			$this->assertSame([], NativeProtectedScene::$calls);
		}
	}

	/** @return void missing and forbidden are indistinguishable at the tool boundary */
	public function testMissingAndForbiddenSameRefusal(): void {
		$this->rig->poll(2, 'bob');
		$messages = [];
		foreach ([2, 999] as $id) {
			try {
				$this->rig->call('polls_list_votes', ['poll_id' => $id]);
				$this->fail('Denied');
			} catch (PollsRefusal $error) {
				$messages[] = $error->getMessage();
			}
		}
		$this->assertSame($messages[0], $messages[1]);
	}

	/** @return void an own observation refuses foreign rows collected under a transiently broader native policy */
	public function testHandlerOwnForeignCollectionRefuses(): void {
		$this->rig->poll(1, 'bob', ['access' => 'open']);
		$this->vote(1, ['owner' => 'bob']);
		NativeProtectedScene::$collectionAll = true;
		$this->expectException(PollsRefusal::class);
		$this->rig->call('polls_list_votes', ['poll_id' => 1]);
	}

	/** @return void observed all plus transient own collection is an explicit undetectable ABA limit, never a completeness proof */
	public function testAllScopeMayContainOnlyOwnCollectionUnderAba(): void {
		$this->vote(1);
		$this->vote(2, ['owner' => 'bob', 'user' => ['id' => 'bob', 'type' => 'user', 'displayName' => 'Bob']]);
		NativeProtectedScene::$collectionAll = false;
		$result = $this->rig->call('polls_list_votes', ['poll_id' => 1]);
		$this->assertSame('all', $result['scope']);
		$this->assertCount(1, $result['votes']);
		$this->assertStringContainsString('incomplete all-scope', $result['limits']);
	}

	/** @return iterable<string,array{string}> independent poisoned native identities */
	public static function identities(): iterable {
		yield 'query id' => ['forceId'];
		yield 'predicate user' => ['forceObject'];
	}

	/**
	 * Either UID source suffices to refuse before serialization, for each new read tool.
	 * @param string $field independently stale native identity
	 * @return void
	 */
	#[DataProvider('identities')]
	public function testBothNativeIdsCheckedForAllProtectedReads(string $field): void {
		$this->rig->poll(1, 'alice', ['access' => 'open']);
		NativeScene::session()->forceId = 'alice';
		NativeScene::session()->$field = 'bob';
		foreach (['polls_list_votes', 'polls_read_results', 'polls_list_links'] as $tool) {
			try {
				$this->rig->call($tool, ['poll_id' => 1]);
				$this->fail('Mixed identity');
			} catch (PollsRefusal) {
				$this->assertSame(0, NativeProtectedScene::$serializations);
			}
		}
		$this->assertSame(6, NativeScene::session()->cleans);
	}

	/** @return void final cleanup failure cannot return an already serialized answer or native exception content */
	public function testFinallyFailureRefusesProtectedReadAndLogsNoContents(): void {
		$this->vote(1);
		NativeScene::session()->failFinally = true;
		try {
			$this->rig->call('polls_list_votes', ['poll_id' => 1]);
			$this->fail('Cleanup failure');
		} catch (PollsRefusal $error) {
			$this->assertStringNotContainsString('PRIVATE', $error->getMessage());
			$this->assertStringNotContainsString('PRIVATE', json_encode($this->rig->logs, JSON_THROW_ON_ERROR));
		}
	}

	/** @return iterable<string,array{array<string,mixed>}> direct calls cannot bypass the closed schema */
	public static function badInputs(): iterable {
		foreach ([[], ['poll_id' => 0], ['poll_id' => '1'], ['poll_id' => 1, 'limit' => null], ['poll_id' => 1, 'limit' => 101],
			['poll_id' => 1, 'limit' => 0], ['poll_id' => 1, 'cursor' => ''], ['poll_id' => 1, 'cursor' => str_repeat('x', 513)],
			['poll_id' => 1, 'token' => 'private'], ['poll_id' => 1, 'scope' => 'all'], ['poll_id' => 1, 'option_id' => 0]] as $i => $input) {
			yield 'invalid-' . $i => [$input];
		}
	}

	/**
	 * Invalid direct-handler input is refused before context or native state.
	 * @param array<string,mixed> $input invalid arguments
	 * @return void
	 */
	#[DataProvider('badInputs')]
	public function testClosedInputBeforeNativeRead(array $input): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->rig->call('polls_list_votes', $input);
	}

	/** @return void text budgets are post-fetch, preserve literal markup and flag even final empty fields */
	public function testTextBudgetAndNoCredentialCut(): void {
		for ($i = 1; $i <= 6; $i++) {
			$this->vote($i, ['optionText' => str_repeat('文', 26000), 'user' => ['id' => 'alice', 'type' => 'user', 'displayName' => 'Alice']]);
		}
		$result = $this->rig->call('polls_list_votes', ['poll_id' => 1]);
		$this->assertTrue($result['truncated']);
		$this->assertSame(20000, mb_strlen($result['votes'][0]['optionText'], 'UTF-8'));
		$this->assertSame('', $result['votes'][5]['optionText']);
		$this->assertGreaterThan(100000, $result['totalChars']);
		$this->assertSame('alice', $result['votes'][5]['user']['id']);
	}

	/** @return void replay across tool/poll/limit/option and offset is not a valid continuation */
	public function testCursorCannotChangeQueryOrPublicOffset(): void {
		$this->vote(1);
		$this->vote(2);
		$first = $this->rig->call('polls_list_votes', ['poll_id' => 1, 'limit' => 1]);
		$cursor = $first['nextCursor'];
		$parts = explode('.', $cursor);
		$parts[1] = rtrim(strtr(base64_encode('{"o":2}'), '+/', '-_'), '=');
		foreach ([['polls_read_results', ['poll_id' => 1, 'limit' => 1, 'cursor' => $cursor]],
			['polls_list_votes', ['poll_id' => 1, 'limit' => 2, 'cursor' => $cursor]],
			['polls_list_votes', ['poll_id' => 1, 'limit' => 1, 'option_id' => 10, 'cursor' => $cursor]],
			['polls_list_votes', ['poll_id' => 1, 'limit' => 1, 'cursor' => implode('.', $parts)]]] as [$tool, $arguments]) {
			try {
				$this->rig->call($tool, $arguments);
				$this->fail('Cursor must restart');
			} catch (PollsRefusal) {
				$this->assertTrue(true);
			}
		}
	}

	/** @return void even identical vote data cannot carry a cursor across a native all-to-own permission change */
	public function testCursorScopeChangeWithIdenticalAuthorizedDataRefuses(): void {
		$this->rig->poll(1, 'bob', ['access' => 'open', 'results' => true]);
		$this->vote(1);
		$this->vote(2);
		$first = $this->rig->call('polls_list_votes', ['poll_id' => 1, 'limit' => 1]);
		NativeScene::$polls[1]['results'] = false;
		$this->expectException(PollsRefusal::class);
		$this->rig->call('polls_list_votes', ['poll_id' => 1, 'limit' => 1, 'cursor' => $first['nextCursor']]);
	}

	/**
	 * Creates the real anonymous reducer with the test secret source.
	 * @return PollsAnonymousIdentity reducer
	 */
	private function anonymous(): PollsAnonymousIdentity {
		$config = $this->createMock(\OCP\IConfig::class);
		$config->method('getSystemValueString')->willReturn('secret');
		return new PollsAnonymousIdentity($config);
	}
}
