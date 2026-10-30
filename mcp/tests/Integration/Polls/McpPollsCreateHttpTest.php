<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Polls;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\{LoginFactory,McpClient,Credential};
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

/** Real authenticated creation and authorized native/SQL observations in the disposable official Polls cells. */
#[Group('polls')]
final class McpPollsCreateHttpTest extends IntegrationTestCase {
	/** @var array<string,Credential> fixture credentials, never printed */
	private static array $credentials=[];
	/** @return void separate creation policy from read/protected-read grants */
	protected function setUp(): void {
		parent::setUp();if(getenv('MCP_IT_WITH_POLLS')!=='1'){$this->markTestSkipped('Pinned Polls cell only');}
		Server::get(\OCP\App\IAppManager::class)->loadApp('polls');
		$p=Server::get(GrantPolicy::class);
		foreach(PollsScene::USERS as$uid){$p->setGrant($uid,'polls','create',true);foreach(['read','results','links']as$op){$p->setGrant($uid,'polls',$op,false);}}
		Server::get(\OCA\Polls\Model\Settings\AppSettings::class)->setBooleanSetting('allowPollCreation',true);
	}
	/** @return void restore the read fixtures' expected policy, without deleting native evidence from this cell */
	protected function tearDown(): void {
		if(getenv('MCP_IT_WITH_POLLS')==='1'){
			$p=Server::get(GrantPolicy::class);foreach(PollsScene::USERS as$uid){$p->setGrant($uid,'polls','create',false);$p->setGrant($uid,'polls','read',true);}
			Server::get(\OCA\Polls\Model\Settings\AppSettings::class)->setBooleanSetting('allowPollCreation',true);
		}
		parent::tearDown();
	}
	/**
	 * Authenticated endpoint; credentials are reused only within this cell.
	 * @param string $kind basic/bearer
	 * @param string $uid fixture actor
	 * @return McpClient real client
	 */
	private function client(string $kind='basic',string $uid=PollsScene::OWNER): McpClient {
		$key=$uid.':'.$kind;$credential=self::$credentials[$key]??=($kind==='basic'?LoginFactory::basic($uid):LoginFactory::bearer($uid));return new McpClient($credential);
	}
	/** @return void both HTTP credential paths create canonical native text/date values, not transient defaults */
	public function testBasicBearerPlanNativeReadbackAndPersistence(): void {
		foreach(['basic','bearer']as$kind){foreach(['textPoll','datePoll']as$type){
			$args=['type'=>$type,'title'=>'PO3 '.$kind.' '.$type,'options'=>$type==='textPoll'?[['text'=>'&amp;'],['text'=>'<3 people a<b <=10']]:[['timestamp'=>1800000000,'duration'=>86400],['timestamp'=>1800000000,'duration'=>0]]];
			$client=$this->client($kind);$before=PollsScene::snapshot();$reply=$client->tool('polls_create_poll',$args);
			$this->assertTrue($reply->ok(),$reply->describe());$plan=$reply->structured();$this->assertTrue($plan['requiresConfirmation']);
			$this->assertSame($before,PollsScene::snapshot(),'Preview emits no Polls row/event writes');
			$done=$client->tool('polls_create_poll',$args+['confirm'=>true,'plan_state'=>$plan['plan_state']]);
			$this->assertTrue($done->ok(),$done->describe());$out=$done->json();$this->assertSame('created',$out['status'],json_encode($out));
			$this->assertSame(2,$out['completed']);$this->assertSame(0,$out['remaining']);
			$back=PollsCreateActor::run(PollsScene::OWNER,'native',['poll_id'=>$out['pollId']]);
			$this->assertSame(PollsScene::OWNER,$back['owner']);$this->assertSame($type,$back['type']);$this->assertSame($args['title'],$back['title']);
			$this->assertSame(PollsNativeEquality::keys($plan['persistence']['observable']),PollsNativeEquality::keys($back['observable']));
			$this->assertSame(PollsNativeEquality::keys($back['observable']),PollsNativeEquality::keys($out['observedDefaults']));
			$this->assertNull($back['raw']['description']);$this->assertSame('',$back['observable']['description']);$this->assertNull($back['raw']['misc_settings']);
			foreach(['allow_maybe','allow_comment','hide_booked_up','use_no']as$key){$this->assertSame(1,(int)$back['raw'][$key]);}
			if(str_starts_with(getenv('MCP_IT_POLLS_VERSION'),'8.')){$this->assertArrayNotHasKey('timezoneName',$back['observable']);$this->assertArrayNotHasKey('timezone_name',$back['raw']);}
			else{$this->assertNull($back['observable']['timezoneName']);$this->assertNull($back['raw']['timezone_name']);}
			$expected=array_map(static fn(array $r):array=>array_intersect_key($r,array_flip(['text','timestamp','duration'])),$out['options']);
			$actual=array_map(static fn(array $r):array=>array_intersect_key($r,array_flip(['text','timestamp','duration'])),$back['options']);
			$this->assertSame($plan['options'],$expected);$this->assertSame($expected,$actual);
			$after=PollsScene::snapshot();$this->assertSame($before['polls_polls']['count']+1,$after['polls_polls']['count']);$this->assertSame($before['polls_options']['count']+2,$after['polls_options']['count']);
			foreach(['polls_votes','polls_share','polls_comments']as$table){if(isset($before[$table])){$this->assertSame($before[$table],$after[$table]);}}
		}}
	}
	/** @return void zero options is supported and a repeated accepted plan has no exactly-once promise */
	public function testZeroOptionsAndReconfirmationCreateTwoDifferentPolls(): void {
		$c=$this->client();$args=['type'=>'textPoll','title'=>'PO3 empty'];$plan=$c->tool('polls_create_poll',$args)->structured();$ids=[];
		foreach([1,2]as$i){$r=$c->tool('polls_create_poll',$args+['confirm'=>true,'plan_state'=>$plan['plan_state']]);$this->assertTrue($r->ok(),$r->describe());$o=$r->json();$this->assertSame('created',$o['status']);$this->assertSame(0,$o['completed']);$ids[]=$o['pollId'];}
		$this->assertNotSame($ids[0],$ids[1]);
	}
	/** @return void grant/native-policy/input/MAC refusals write no Polls table */
	public function testDenialsAndTamperBeforeAnySink(): void {
		$c=$this->client();$args=['type'=>'textPoll','title'=>'PO3 denied'];$p=Server::get(GrantPolicy::class);$before=PollsScene::snapshot();
		$p->setGrant(PollsScene::OWNER,'polls','create',false);$this->assertFalse($c->tool('polls_create_poll',$args)->ok());$p->setGrant(PollsScene::OWNER,'polls','create',true);
		$s=Server::get(\OCA\Polls\Model\Settings\AppSettings::class);$s->setBooleanSetting('allowPollCreation',false);
		try{$this->assertFalse($c->tool('polls_create_poll',$args)->ok());}finally{$s->setBooleanSetting('allowPollCreation',true);}
		foreach([['options'=>[['text'=>'&amp;'],['text'=>'&']]],['options'=>array_fill(0,101,['text'=>'x'])],['options'=>[['text'=>"bad\u{200e}text"]]]]as$change){$this->assertFalse($c->tool('polls_create_poll',array_replace($args,$change))->ok());}
		$plan=$c->tool('polls_create_poll',$args)->structured();$changed=$c->tool('polls_create_poll',array_replace($args,['title'=>'changed','confirm'=>true,'plan_state'=>$plan['plan_state']]));
		$this->assertTrue($changed->structured()['requiresConfirmation']);$this->assertNotSame($plan['plan_state'],$changed->structured()['plan_state']);$this->assertSame($before,PollsScene::snapshot());
	}
	/** @return void stale public/UID caches are cleaned, while foreign and unauthenticated contexts cannot reach a sink */
	public function testRealGlobalContextAndFinally(): void {
		$args=['type'=>'textPoll','title'=>'PO3 context'];$before=PollsScene::snapshot();
		$ok=PollsCreateActor::run(PollsScene::OWNER,'observe',['arguments'=>$args,'preview'=>true,'warm'=>PollsScene::OUTSIDER]);
		$this->assertArrayHasKey('answer',$ok);$this->assertSame([null,null,null],$ok['residue']);
		foreach([['noLogin'=>true],['session'=>PollsScene::OUTSIDER]]as$poison){$r=PollsCreateActor::run(PollsScene::OWNER,'observe',['arguments'=>$args,'preview'=>true]+$poison);$this->assertArrayHasKey('error_class',$r);}
		$this->assertSame($before,PollsScene::snapshot());
	}
	/** @return void native boolean OR group policy authorizes only the real member, not another user or site admin */
	public function testNativeCreationGroupPolicyUsesActor(): void {
		$manager=Server::get(\OCP\IGroupManager::class);$id='mcp-it-po3-create-561';$group=$manager->createGroup($id);
		$group->addUser(Server::get(\OCP\IUserManager::class)->get(PollsScene::OWNER));
		$settings=Server::get(\OCA\Polls\Model\Settings\AppSettings::class);
		$before=PollsScene::snapshot();$settings->setBooleanSetting('allowPollCreation',false);$settings->setGroupSetting('pollCreationGroups',[$id]);
		try{
			$args=['type'=>'textPoll','title'=>'PO3 group-only preview'];
			$this->assertTrue($this->client('basic',PollsScene::OWNER)->tool('polls_create_poll',$args)->ok());
			$this->assertFalse($this->client('basic',PollsScene::OUTSIDER)->tool('polls_create_poll',$args)->ok());
			$this->assertFalse($this->client('bearer',PollsScene::ADMIN)->tool('polls_create_poll',$args)->ok());
			$this->assertSame($before,PollsScene::snapshot());
		}finally{$settings->setGroupSetting('pollCreationGroups',[]);$settings->setBooleanSetting('allowPollCreation',true);$group->delete();}
	}
	/** @return void a real native update between add and first readback cannot be passed off as the predicted default */
	public function testObservableDriftAtNativeCreatedEventStopsOptions(): void {
		$before=PollsScene::snapshot();$r=PollsCreateActor::run(PollsScene::OWNER,'drift',['arguments'=>['type'=>'textPoll','title'=>'PO3 default drift','options'=>[['text'=>'one']]]]);
		$this->assertSame('uncertain',$r['answer']['status']);$this->assertArrayNotHasKey('pollId',$r['answer']);$this->assertCount(1,$r['eventPollIds']);
		$after=PollsScene::snapshot();$this->assertSame($before['polls_polls']['count']+1,$after['polls_polls']['count']);$this->assertSame($before['polls_options'],$after['polls_options']);
		$back=PollsCreateActor::run(PollsScene::OWNER,'native',['poll_id'=>$r['eventPollIds'][0]]);$this->assertSame(0,$back['observable']['allowMaybe']);$this->assertSame([],$back['options']);
	}
	/** @return void real synchronous listener exceptions after insert are uncertain, with no inferred/recovered receipt id */
	public function testPostInsertNativePollAndOptionEventsAreUncertain(): void {
		foreach(['poll','option']as$kind){
			$before=PollsScene::snapshot();$r=PollsCreateActor::run(PollsScene::OWNER,'event',['event'=>$kind,'arguments'=>['type'=>'textPoll','title'=>'PO3 listener '.$kind,'options'=>[['text'=>'one'],['text'=>'two']]]]);
			$this->assertArrayHasKey('answer',$r);$out=$r['answer'];$this->assertSame('uncertain',$out['status']);$this->assertCount(1,$r['eventPollIds']);
			$this->assertSame([null,null,null],$r['residue']);$after=PollsScene::snapshot();$this->assertSame($before['polls_polls']['count']+1,$after['polls_polls']['count']);
			$this->assertSame($before['polls_options']['count']+($kind==='option'?1:0),$after['polls_options']['count']);
			if($kind==='poll'){$this->assertArrayNotHasKey('pollId',$out);}else{$this->assertSame(0,$out['completed']);}
		}
	}
}
