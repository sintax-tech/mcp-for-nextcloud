<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Polls;

use OCA\Mcp\Tests\Integration\Support\ServiceSwitchFixture;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\{LoginFactory,McpClient,Http,Credential};
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

/** Real authenticated requests and native instrumentation in an isolated pinned Polls cell. */
#[Group('polls')]
final class McpPollsHttpTest extends IntegrationTestCase {
	/** @var ServiceSwitchFixture|null Per-test service switch, absent when the opt-in skips setup. */
	private ?ServiceSwitchFixture $serviceSwitch = null;

	/** @var array<string,Credential> test credentials, never printed */
	private static array $credentials=[];

	/**
	 * Runs only in the isolated native Polls cell.
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		if (getenv('MCP_IT_WITH_POLLS')!=='1') {$this->markTestSkipped('Pinned Polls cell only');}
		Server::get(\OCP\App\IAppManager::class)->loadApp('polls');

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
	 * Creates a scoped Basic or Bearer credential once, without exposing its token.
	 * @param string $uid scene actor
	 * @param string $kind credential kind
	 * @return Credential credential
	 */
	private function credential(string $uid,string $kind): Credential {
		return self::$credentials[$uid.':'.$kind]??=($kind==='basic'?LoginFactory::basic($uid):LoginFactory::bearer($uid));
	}

	/**
	 * Uses the production authenticated endpoint.
	 * @param string $uid actor
	 * @param string $kind basic/bearer
	 * @return McpClient client
	 */
	private function client(string $uid,string $kind='basic'): McpClient {
		return new McpClient($this->credential($uid,$kind));
	}

	/** Basic/Bearer catalogue and identical closed read shapes; whitelist and no Polls database writes. @return void */
	public function testHttpCatalogueAclAndReadOnlyDatabase(): void {
		$before=PollsScene::snapshot();$ids=PollsScene::ids();
		// Only the seeded polls are configured with showResults never; polls created by other tests of the cell keep the native default.
		$scene=array_values(array_intersect_key($ids,array_flip(['private','shared','open','hidden-options','long-text'])));
		foreach (['basic','bearer'] as $kind) {
			foreach (PollsScene::USERS as $actor) {
				$response=Http::request('POST',Http::BASE.'/index.php/apps/mcp/',[
					'Authorization: '.$this->credential($actor,$kind)->authorization(),'Content-Type: application/json','Accept: application/json, text/event-stream',
				],json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/list'],JSON_THROW_ON_ERROR));
				$this->assertSame(200,$response['status']);
				$tools=json_decode($response['body'],true,512,JSON_THROW_ON_ERROR)['result']['tools'];
				$polls=array_values(array_filter($tools,static fn ($t)=>str_starts_with($t['name'],'polls_')));
				$this->assertSame(['polls_list_polls','polls_read_poll'],array_column($polls,'name'));
				foreach ($polls as $tool) {$this->assertTrue($tool['annotations']['readOnlyHint']);}
				$reply=$this->client($actor,$kind)->tool('polls_list_polls');$this->assertTrue($reply->ok(),$reply->describe());
				$body=$reply->json();$this->assertTrue($body['untrustedPollsData']);
				$native=PollsActor::run($actor,'native');
				$this->assertEqualsCanonicalizing($native['polls'],array_column($body['polls'],'id'),$actor.' '.$kind);
				if ($actor!==PollsScene::OWNER) {$this->assertNotContains($ids['private'],array_column($body['polls'],'id'));}
				foreach ($body['polls'] as $poll) {
					$this->assertSame(['id','created','url','type','votingVariant','title','description','access','showResults','expire','isAnonymous','isRealAnonymous','isArchived','isExpired','permissions'],array_keys($poll));
					$this->assertStringContainsString('/apps/polls/vote/'.$poll['id'],$poll['url']);
					if (in_array($poll['id'],$scene,true)) {$this->assertSame('never',$poll['showResults']);}
				}
			}
		}
		$this->assertSame($before,PollsScene::snapshot(),'All Polls tables unchanged, including native votes/shares/options');
	}

	/** Native access denies private data to outsiders and site admins while delegation grants edit/read. @return void */
	public function testForeignAbsentAndDelegated(): void {
		$ids=PollsScene::ids();
		foreach (['basic','bearer'] as $kind) {
			foreach ([PollsScene::OUTSIDER,PollsScene::ADMIN] as $actor) {
				$client=$this->client($actor,$kind);
				$foreign=$client->tool('polls_read_poll',['poll_id'=>$ids['private']]);
				$absent=$client->tool('polls_read_poll',['poll_id'=>2147483647]);
				$this->assertTrue($foreign->isError(),$foreign->describe());$this->assertTrue($absent->isError());
				$this->assertSame($foreign->text(),$absent->text());
			}
			$reply=$this->client(PollsScene::DELEGATE,$kind)->tool('polls_read_poll',['poll_id'=>$ids['shared']]);
			$this->assertTrue($reply->ok(),$reply->describe());$this->assertTrue($reply->json()['poll']['permissions']['edit']);
		}
	}

	/** List paging compares every returned id with native ACCESS and rejects altered MAC/actor/limit. @return void */
	public function testHttpCursorAndNativeDetailCost(): void {
		$client=$this->client(PollsScene::OWNER,'bearer');$all=[];$cursor=null;
		do {
			$args=['limit'=>1];if ($cursor!==null) {$args['cursor']=$cursor;}
			$reply=$client->tool('polls_list_polls',$args);$this->assertTrue($reply->ok(),$reply->describe());$body=$reply->json();
			$this->assertCount(1,$body['polls']);$all[]=$body['polls'][0]['id'];$cursor=$body['nextCursor'];
		} while ($cursor!==null);
		$this->assertCount(count(array_unique($all)),$all);
		$this->assertEqualsCanonicalizing(PollsActor::run(PollsScene::OWNER,'native')['polls'],$all);
		$first=$client->tool('polls_list_polls',['limit'=>1])->json()['nextCursor'];$this->assertIsString($first);
		foreach ([['limit'=>2,'cursor'=>$first],['limit'=>1,'cursor'=>$first.'x']] as $args) {$this->assertTrue($client->tool('polls_list_polls',$args)->isError());}
		$this->assertTrue($this->client(PollsScene::VIEWER)->tool('polls_list_polls',['limit'=>1,'cursor'=>$first])->isError());
		foreach ([PollsScene::OWNER,PollsScene::ADMIN] as $actor) {
			$probe=PollsActor::run($actor,'observe',['arguments'=>['limit'=>1]]);
			$this->assertArrayHasKey('answer',$probe);$this->assertLessThanOrEqual(2,$probe['nativeGets']);$this->assertGreaterThan(0,$probe['nativeGets']);
		}
	}

	/** Native cache warmed under another actor/token is cleaned, both identities re-established, then cleaned on every exit. @return void */
	public function testNativeContextCleanupAndSameIdFreshOptions(): void {
		$before=PollsScene::snapshot();
		$ids=PollsScene::ids();
		foreach ([[],['warm'=>PollsScene::OWNER,'stale'=>true],['warmNull'=>true]] as $context) {
			$probe=PollsActor::run(PollsScene::VIEWER,'observe',$context+['tool'=>'polls_read_poll','arguments'=>['poll_id'=>$ids['shared']]]);
			$this->assertArrayHasKey('answer',$probe,json_encode($probe));$this->assertSame([null,null,null],$probe['residue']);
		}
		foreach ([['poll_id'=>$ids['private']],['poll_id'=>2147483647],['poll_id'=>$ids['shared'],'options_cursor'=>'v1.invalid.invalid']] as $args) {
			$probe=PollsActor::run(PollsScene::VIEWER,'observe',['warm'=>PollsScene::OWNER,'stale'=>true,'tool'=>'polls_read_poll','arguments'=>$args]);
			$this->assertArrayHasKey('error',$probe);$this->assertSame([null,null,null],$probe['residue']);
		}
		$probe=PollsActor::run(PollsScene::VIEWER,'observe',['session'=>PollsScene::OUTSIDER,'stale'=>true]);
		$this->assertArrayHasKey('error',$probe);$this->assertSame(0,$probe['nativeGets']);
		$repeated=PollsActor::run(PollsScene::OWNER,'repeat',['poll_id'=>$ids['hidden-options']]);
		$this->assertCount(3,$repeated[PollsScene::OWNER]['options']);$this->assertCount(2,$repeated[PollsScene::VIEWER]['options']);
		$this->assertSame($before,PollsScene::snapshot());
	}

	/** Native booked/deleted filtering, closed option shape, and raw text budget apply on real HTTP replies. @return void */
	public function testOptionsAndTextBudget(): void {
		$before=PollsScene::snapshot();
		$ids=PollsScene::ids();$client=$this->client(PollsScene::VIEWER,'bearer');
		foreach (['shared','hidden-options'] as $name) {
			$reply=$client->tool('polls_read_poll',['poll_id'=>$ids[$name]]);$this->assertTrue($reply->ok(),$reply->describe());$body=$reply->json();
			$native=PollsActor::run(PollsScene::VIEWER,'native',['poll_id'=>$ids[$name]]);
			$this->assertEqualsCanonicalizing($native['options'],array_column($body['options'],'id'));
			foreach ($body['options'] as $option) {
				$this->assertSame(['id','pollId','text','locked','timestamp','duration','order','confirmed','isoTimestamp','isoDuration'],array_keys($option));
			}
			$this->assertNotContains($ids['removed'],array_column($body['options'],'id'));
		}
		$reply=$client->tool('polls_read_poll',['poll_id'=>$ids['long-text']]);$this->assertTrue($reply->ok(),$reply->describe());$body=$reply->json();
		$this->assertTrue($body['truncated']);$this->assertGreaterThan(20000,$body['totalChars']);
		$this->assertSame(20000,mb_strlen($body['poll']['description'],'UTF-8'));
		$this->assertSame(201,mb_strlen($body['options'][0]['text'],'UTF-8'));
		$this->assertSame($before,PollsScene::snapshot());
	}

	/** Policy changes under the same id invalidate option cursors, even when no option becomes hidden. @return void */
	public function testSamePollPolicyChangeInvalidatesCursor(): void {
		$ids=PollsScene::ids();$client=$this->client(PollsScene::VIEWER);
		$first=$client->tool('polls_read_poll',['poll_id'=>$ids['shared'],'options_limit'=>1]);$this->assertTrue($first->ok(),$first->describe());
		$cursor=$first->json()['nextCursor'];$this->assertIsString($cursor);
		$change=PollsActor::run(PollsScene::OWNER,'configure',['poll_id'=>$ids['shared'],'config'=>['hideBookedUp'=>true]]);
		$this->assertTrue($change['after']);
		$this->assertFalse($change['before']);
		try {
			$stale=$client->tool('polls_read_poll',['poll_id'=>$ids['shared'],'options_limit'=>1,'options_cursor'=>$cursor]);
			$this->assertTrue($stale->isError(),$stale->describe());
		} finally {PollsActor::run(PollsScene::OWNER,'configure',['poll_id'=>$ids['shared'],'config'=>['hideBookedUp'=>false]]);}
	}

	/** Explicit denial hides the catalogue and blocks calls; a reader receives no future results/write operations. @return void */
	public function testExplicitReadDenial(): void {
		$policy=Server::get(GrantPolicy::class);$policy->setGrant(PollsScene::VIEWER,'polls','read',false);
		try {
			$reply=$this->client(PollsScene::VIEWER)->tool('polls_read_poll',['poll_id'=>PollsScene::ids()['shared']]);
			$this->assertFalse($reply->ok(),$reply->describe());
			$this->assertSame('Unknown tool',$reply->body['error']['message']??null);
		} finally {$policy->setGrant(PollsScene::VIEWER,'polls','read',true);}
	}
}
