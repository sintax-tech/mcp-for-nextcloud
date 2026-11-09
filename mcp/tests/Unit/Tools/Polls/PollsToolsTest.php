<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Polls;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Modules\Polls\Integration\PollsSurface;
use OCA\Mcp\Modules\Polls\PollsRefusal;
use OCA\Mcp\Modules\Polls\Presentation\PollsText;
use OCA\Mcp\Modules\Polls\Reading\PollsCursor;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exercises the real handlers, context, factory, MAC, projection and native-shaped strict scene together. */
final class PollsToolsTest extends TestCase {
	/** @var PollsTestRig module and dependencies */
	private PollsTestRig $rig;

	/** @return void fresh strict scene for every test */
	protected function setUp(): void {
		$this->rig = new PollsTestRig($this);
	}

	/** @return void exactly the authorized read tools are registered, with exact grants and closed schemas */
	public function testExactReadToolsAndNoWriteGrants(): void {
		PollsReadCatalogAssertions::check($this, $this->rig->module);
	}

	/** @return void admin candidates are filtered BEFORE pagination and detail cost stays limit+1 */
	public function testAdminDoesNotBypassAccessOrConsumePageSlots(): void {
		NativeScene::session()->type = 'admin';
		for ($i=1;$i<=12;$i++) {
			$this->rig->poll($i,$i % 2 ? 'alice' : 'bob');
		}
		$page = $this->rig->call(arguments:['limit'=>2]);
		$this->assertSame([11,9],array_column($page['polls'],'id'));
		$this->assertSame([11,9,7],NativeScene::$gets);
		$this->assertTrue($page['hasMore']);
		$next = $this->rig->call(arguments:['limit'=>2,'cursor'=>$page['nextCursor']]);
		$this->assertSame([7,5],array_column($next['polls'],'id'));
		$this->assertSame([11,9,7,7,5,3],NativeScene::$gets);
		$this->assertStringNotContainsString('PRIVATE',json_encode($next));
		$this->assertStringNotContainsString('countParticipants',json_encode($page));
		$this->assertSame('https://cloud.example/sub/apps/polls/vote/11',$page['polls'][0]['url']);
	}

	/** @return void native own/open/member/delegated/archive rules determine discovery and direct access */
	public function testOwnOpenMemberDelegateAndArchivedDiscoveryBoundary(): void {
		$this->rig->poll(1);
		$this->rig->poll(2,'bob',['access'=>'open']);
		$this->rig->poll(3,'bob',['members'=>['alice']]);
		$this->rig->poll(4,'bob',['delegates'=>['alice']]);
		$this->rig->poll(5,'bob',['delegates'=>['alice'],'deleted'=>123]);
		$this->rig->poll(6,'bob');
		$this->assertSame([4,3,2,1],array_column($this->rig->call()['polls'],'id'));
		$this->assertSame(5,$this->rig->call('polls_read_poll',['poll_id'=>5])['poll']['id']);
	}

	/** @return void old persistent identity and token are cleared; final native serialization cannot leave them behind */
	public function testOldSessionAndWarmCachedUserAreCleanedAtBothEnds(): void {
		$this->rig->poll(1);
		$session = NativeScene::session();
		$session->stored = 'bob';
		$session->cached = new \OCA\Polls\Model\UserBase('bob','user');
		$session->token = 'PRIVATE-OLD-TOKEN';
		$this->rig->call();
		$this->assertSame(2,$session->cleans);
		$this->assertNull($session->stored);
		$this->assertNull($session->cached);
		$this->assertSame('',$session->token);
		$this->rig->call();
		$this->assertSame(4,$session->cleans);
	}

	/** @return iterable<string,array{0:string}> native non-internal identities */
	public static function unsupportedTypes(): iterable {
		foreach (['ghost','cron','email','public','external'] as $type) {
			yield $type => [$type];
		}
	}

	/**
	 * A matching UID alone cannot make an external/native-job actor eligible.
	 * @param string $type native type
	 * @return void
	 */
	#[DataProvider('unsupportedTypes')]
	public function testOnlyNativeUserAndAdminTypesAreAccepted(string $type): void {
		NativeScene::session()->type = $type;
		$this->expectException(PollsRefusal::class);
		try {
			$this->rig->call();
		} finally {
			$this->assertSame([],NativeScene::$gets);
			$this->assertSame(2,NativeScene::session()->cleans);
		}
	}

	/** @return iterable<string,array{0:string}> independent actor sources */
	public static function identitySources(): iterable {
		yield 'query ID' => ['forceId'];
		yield 'predicate object' => ['forceObject'];
	}

	/**
	 * Both native identities have to equal the explicit actor, not merely each other or the session.
	 * @param string $property source to poison independently
	 * @return void
	 */
	#[DataProvider('identitySources')]
	public function testEachNativeIdentityIsChecked(string $property): void {
		NativeScene::session()->{$property} = 'bob';
		if ($property === 'forceObject') {NativeScene::session()->forceId = 'alice';}
		$this->expectException(PollsRefusal::class);
		$this->rig->call();
	}

	/** @return void an error in final cleanup prevents returning a seemingly successful response */
	public function testCleanupFailureDoesNotReturnDataOrSecrets(): void {
		$this->rig->poll(1);
		NativeScene::session()->failFinally = true;
		$this->expectException(PollsRefusal::class);
		$this->expectExceptionMessage('Polls is unavailable for this request.');
		try {$this->rig->call();} finally {
			$this->assertSame('RuntimeException',$this->rig->logs[0]['exception_class']);
			$this->assertStringNotContainsString('PRIVATE',json_encode($this->rig->logs));
		}
	}

	/** @return void loss of ACCESS in the lookahead refuses the entire window, without refill queries */
	public function testChangingLookaheadRefusesWithoutRefill(): void {
		for ($i=1;$i<=5;$i++) {
			$this->rig->poll($i);
		}
		NativeScene::$onGet = static function (int $id): void {
			if ($id === 4) {
				NativeScene::$polls[$id]['owner'] = 'bob';
			}
		};
		$this->expectException(PollsRefusal::class);
		$this->expectExceptionMessage('changed');
		try {
			$this->rig->call(arguments:['limit'=>1]);
		} finally {
			$this->assertSame([5,4],NativeScene::$gets);
			$this->assertNull(NativeScene::session()->stored);
		}
	}

	/** @return void result/vote-dependent fields never enter the discovery digest */
	public function testVoteActivityDoesNotRestartThePollList(): void {
		$this->rig->poll(1);$this->rig->poll(2);$this->rig->poll(3);
		$page = $this->rig->call(arguments:['limit'=>1]);
		NativeScene::$polls[1]['lastInteraction'] = 999;
		NativeScene::$polls[2]['votes'] = 500;
		$next = $this->rig->call(arguments:['limit'=>1,'cursor'=>$page['nextCursor']]);
		$this->assertSame([2],array_column($next['polls'],'id'));
	}

	/** @return void native filtered options and rich serializations cannot leak votes, counts, shares or owners */
	public function testReadUsesFilteredOptionsAndClosedProjectionWithFreshFactory(): void {
		$this->rig->poll(1,'bob',['members'=>['alice']]);
		$this->rig->option(10,1,['order'=>2,'isoTimestamp'=>null,'isoDuration'=>null]);
		$this->rig->option(11,1,['order'=>2]);
		$this->rig->option(12,1,['deleted'=>123]);
		$this->rig->option(13,1,['hidden'=>true]);
		$page = $this->rig->call('polls_read_poll',['poll_id'=>1,'options_limit'=>1]);
		$this->assertSame([10],array_column($page['options'],'id'));
		$this->assertTrue($page['hasMore']);
		$this->assertNull($page['options'][0]['isoTimestamp']);
		$next = $this->rig->call('polls_read_poll',['poll_id'=>1,'options_limit'=>1,'options_cursor'=>$page['nextCursor']]);
		$this->assertSame([11],array_column($next['options'],'id'));
		$this->assertSame(2,NativeScene::$factories);
		foreach (['PRIVATE','votes','countParticipants','owner','shareToken','pollGroups'] as $forbidden) {
			$this->assertStringNotContainsString($forbidden,json_encode($next));
		}
	}

	/** @return void options locked by a vote invalidate only the options cursor, without disclosing counts */
	public function testVoteDependentLockInvalidatesOptionsCursor(): void {
		$this->rig->poll(1);$this->rig->option(1);$this->rig->option(2);
		$page = $this->rig->call('polls_read_poll',['poll_id'=>1,'options_limit'=>1]);
		NativeScene::$options[1][1]['locked'] = true;
		$this->expectException(PollsRefusal::class);
		$this->expectExceptionMessage('changed');
		$this->rig->call('polls_read_poll',['poll_id'=>1,'options_limit'=>1,'options_cursor'=>$page['nextCursor']]);
	}

	/** @return void a second call to the same id cannot reuse the previous native service policy/context */
	public function testSamePollAfterSessionAndPolicyChangeDoesNotReuseCachedPoll(): void {
		$this->rig->poll(1,'alice',['members'=>['bob']]);
		$this->rig->option(1);
		$this->rig->call('polls_read_poll',['poll_id'=>1]);
		$this->rig->uid = 'bob';
		$this->rig->call('polls_read_poll',['poll_id'=>1], 'bob');
		$this->assertSame(2,NativeScene::$factories);
		NativeScene::$polls[1]['members'] = [];
		$this->expectException(PollsRefusal::class);
		$this->expectExceptionMessage('Poll not found.');
		$this->rig->call('polls_read_poll',['poll_id'=>1], 'bob');
	}

	/** @return void native absent and forbidden failures produce the identical sanitized response */
	public function testAbsentAndForeignAreIndistinguishableAndCleanupRuns(): void {
		$this->rig->poll(2,'bob');
		$messages = [];
		foreach ([2,999] as $id) {
			try {
				$this->rig->call('polls_read_poll',['poll_id'=>$id]);
				$this->fail('should refuse');
			} catch (PollsRefusal $error) {
				$messages[] = $error->getMessage();
			}
		}
		$this->assertSame(['Poll not found.','Poll not found.'],$messages);
		$this->assertSame(4,NativeScene::session()->cleans);
	}

	/** @return iterable<string,array{0:string}> changes to a signed query */
	public static function cursorChanges(): iterable {
		foreach (['offset','actor','limit','version','access','collection'] as $change) {
			yield $change => [$change];
		}
	}

	/**
	 * All scope and public offset fields participate in the MAC.
	 * @param string $change what changes after the first page
	 * @return void
	 */
	#[DataProvider('cursorChanges')]
	public function testCursorCannotCrossScopeOrState(string $change): void {
		$this->rig->poll(1);$this->rig->poll(2);$this->rig->poll(3);
		$page = $this->rig->call(arguments:['limit'=>1]);
		$args = ['limit'=>1,'cursor'=>$page['nextCursor']];$actor = 'alice';
		switch ($change) {
			case 'offset': $parts=explode('.',$args['cursor']);$parts[1]=rtrim(base64_encode('{"o":2}'),'=');$args['cursor']=implode('.',$parts);break;
			case 'actor': $this->rig->uid='bob';$actor='bob';break;
			case 'limit': $args['limit']=2;break;
			case 'version': $this->rig->version='9.3.0';break;
			case 'access': NativeScene::$polls[1]['access']='open';break;
			case 'collection': unset(NativeScene::$polls[1]);break;
		}
		$this->expectException(PollsRefusal::class);
		$this->expectExceptionMessage('changed');
		$this->rig->call(arguments:$args,actor:$actor);
	}

	/** @return void cut text is marked even when the last authorized field becomes empty */
	public function testPageTextBudgetAndControlSanitizationAreExplicit(): void {
		$this->rig->poll(1,'alice',['title'=>'<b>Title</b>' . "\x01",'description'=>str_repeat('é',26000)]);
		for ($i=1;$i<=6;$i++) {
			$this->rig->option($i,1,['text'=>str_repeat('文',26000)]);
		}
		$page=$this->rig->call('polls_read_poll',['poll_id'=>1]);
		$this->assertSame('<b>Title</b>',$page['poll']['title']);
		$this->assertTrue($page['truncated']);
		$this->assertSame('', $page['options'][5]['text']);
		$this->assertSame(182012,$page['totalChars']);
		$this->assertSame('options.5.text',end($page['truncatedFields'])['field']);
		$this->assertSame(26000,end($page['truncatedFields'])['totalChars']);
	}

	/** @return void the cursor cannot emit a next offset beyond what it accepts */
	public function testOffsetCeilingKeepsHasMoreHonest(): void {
		$cursor = new PollsCursor(new \OCA\Mcp\Mcp\Confirmation\PlanState((new \OCA\Mcp\Tests\Unit\InMemoryConfig())->mock($this)));
		$bound=['state'=>'test'];
		$token=$cursor->next(100000,'polls_list_polls','alice',$bound);
		$this->assertSame(100000,$cursor->offset($token,'polls_list_polls','alice',$bound));
		$this->assertNull($cursor->next(100001,'polls_list_polls','alice',$bound));
	}

	/** @return iterable<string,array{0:array<string,mixed>,1:string}> malformed public inputs */
	public static function invalidInputs(): iterable {
		foreach ([['limit'=>0],['limit'=>101],['limit'=>'1'],['limit'=>null],['cursor'=>''],['votes'=>true]] as $args) {
			yield json_encode($args) => [$args,'polls_list_polls'];
		}
		foreach ([[],['poll_id'=>0],['poll_id'=>'1'],['poll_id'=>1,'options_limit'=>false],['poll_id'=>1,'options_cursor'=>null]] as $args) {
			yield 'read '.json_encode($args) => [$args,'polls_read_poll'];
		}
	}

	/**
	 * Direct module calls enforce closed/typed inputs too, before resolving any native context.
	 * @param array<string,mixed> $args invalid arguments
	 * @param string $tool tool
	 * @return void
	 */
	#[DataProvider('invalidInputs')]
	public function testInvalidInputIsRefusedBeforeNativeCalls(array $args,string $tool): void {
		$this->expectException(\InvalidArgumentException::class);
		try {
			$this->rig->call($tool,$args);
		} finally {
			$this->assertSame(0,NativeScene::session()->cleans);
		}
	}

	/** @return void direct calls cannot bypass a denied read grant or app availability */
	public function testGrantAndEnablementPrecedeNativeResolution(): void {
		$this->rig->policy->setGrant('alice','polls','read',false);
		$this->expectException(PollsRefusal::class);
		try {
			$this->rig->call();
		} finally {
			$this->assertSame(0,NativeScene::session()->cleans);
		}
	}

	/** @return void native unknown data types are rejected, not silently coerced to a compatible field */
	public function testMalformedNativeOptionIsRefused(): void {
		$this->rig->poll(1);$this->rig->option(1,1,['locked'=>1]);
		$this->expectException(PollsRefusal::class);
		$this->rig->call('polls_read_poll',['poll_id'=>1]);
	}

	/** @return void compatibility signature checking rejects a shape with the wrong parameter type */
	public function testUnknownMethodSurfaceFailsClosed(): void {
		$this->expectException(PollsRefusal::class);
		(new PollsSurface())->assertMethod(PollsCursor::class,'offset',['int','string','string','array'],'int');
	}

	/** @return void changing a native filter policy is bound even when the visible sequence remains identical */
	public function testFilterPolicyChangesInvalidateTheOptionsCursor(): void {
		$this->rig->poll(1);$this->rig->option(1);$this->rig->option(2);
		$page=$this->rig->call('polls_read_poll',['poll_id'=>1,'options_limit'=>1]);
		NativeScene::$polls[1]['hideBookedUp']=true;
		$this->expectException(PollsRefusal::class);
		$this->rig->call('polls_read_poll',['poll_id'=>1,'options_limit'=>1,'options_cursor'=>$page['nextCursor']]);
	}

	/** @return void the native editor exception is preserved instead of filtering options again in MCP */
	public function testEditorsStillSeeBookedNativeOptions(): void {
		$this->rig->poll(1);$this->rig->option(1,1,['hidden'=>true,'locked'=>true]);
		$this->assertSame([1],array_column($this->rig->call('polls_read_poll',['poll_id'=>1])['options'],'id'));
	}

	/** @return iterable<string,array{0:?string,1:string}> divergent and empty endpoint identities */
	public static function badCallerSessions(): iterable {
		yield 'empty actor'=>['alice',''];
		yield 'null session'=>[null,'alice'];
		yield 'other session'=>['bob','alice'];
	}

	/**
	 * Explicit actor is required before resolving a native service.
	 * @param string|null $session endpoint identity
	 * @param string $actor explicit MCP actor
	 * @return void
	 */
	#[DataProvider('badCallerSessions')]
	public function testCallerSessionCannotBeMissingOrAnotherActor(?string $session,string $actor): void {
		$this->rig->uid=$session;
		$this->expectException(PollsRefusal::class);
		try {$this->rig->call(actor:$actor);} finally {$this->assertSame(0,NativeScene::session()->cleans);}
	}

	/** @return void failed cleanup retains the prior native class, without logging either message or response data */
	public function testDiagnosticKeepsPriorFailureWhenFinalCleanupAlsoFails(): void {
		$this->rig->poll(1);NativeScene::session()->failFinally=true;
		NativeScene::$onGet=static function (int $id): void {throw new \DomainException('PRIVATE-original-token');};
		$this->expectException(PollsRefusal::class);
		try {$this->rig->call();} finally {
			$this->assertSame('DomainException',$this->rig->logs[0]['exception_class']);
			$this->assertSame('RuntimeException',$this->rig->logs[1]['exception_class']);
			$this->assertSame('DomainException',$this->rig->logs[1]['prior_exception_class']);
			$this->assertSame([
				'MCP Polls read failed: DomainException (code 0)',
				'MCP Polls read failed: RuntimeException (code 0); previous: DomainException',
			], $this->rig->logMessages);
			$this->assertStringNotContainsString('PRIVATE',json_encode($this->rig->logs));
		}
	}
}
