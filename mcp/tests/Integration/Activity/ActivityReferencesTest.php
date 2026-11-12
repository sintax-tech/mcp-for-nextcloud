<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Activity;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\PolicyWriter;
use OCA\Mcp\Access\ResourceScope\ScopedCalendarLookup;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Modules\Calendar\Presentation\EventMapper;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\Credential;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCA\Mcp\Tests\Integration\Support\NativeActorContext;
use OCP\Activity\IManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\User\Events\UserFirstTimeLoggedInEvent;

/** Existing NC34/35 cells: Activity references use current Files/DAV authorization, with no read-side mutation. */
final class ActivityReferencesTest extends IntegrationTestCase {
	/** @var list<IUser> owned synthetic accounts */
	private array $actors = [];
	/** @var list<string> tags owned by this test */
	private array $tags = [];
	/** @var list<int|string>|null previous exclusion list */
	private ?array $hidden = null;
	/** Previous MCP service switch. */
	private ?string $service = null;
	/** @return void restore global switches and remove only owned fixture accounts/tags */
	protected function tearDown(): void {
		if($this->hidden!==null)Server::get(VisibilityGuard::class)->setHiddenTagIds($this->hidden);
		if($this->tags!==[])Server::get(ISystemTagManager::class)->deleteTags($this->tags);
		foreach($this->actors as $actor)$actor->delete();
		if($this->service!==null){$c=Server::get(IConfig::class);$this->service===''?$c->deleteAppValue('mcp',GrantPolicy::SERVICE_KEY):$c->setAppValue('mcp',GrantPolicy::SERVICE_KEY,$this->service);}
		parent::tearDown();
	}
	/** @return void current file names/ancestry replace old paths; hidden/missing references never return */
	public function testCurrentFilesAndFolderFilterWithoutMutation(): void {
		[$uid,$client,$credential]=$this->actor();
		[$folder,$file,$hidden,$outside]=NativeActorContext::run($uid,function()use($uid){
			$root=Server::get(IRootFolder::class)->getUserFolder($uid);$folder=$root->newFolder('current-folder');
			return [$folder,$folder->newFile('current.txt','body stays private'),$folder->newFile('hidden.txt','hidden body'),$root->newFile('outside.txt','outside body')];
		});
		$tag=Server::get(ISystemTagManager::class)->createTag('mcp-it-activity-'.bin2hex(random_bytes(4)),true,true);$this->tags[]=(string)$tag->getId();
		$guard=Server::get(VisibilityGuard::class);$this->hidden=$guard->getHiddenTagIds();$guard->setHiddenTagIds([...$this->hidden,$tag->getId()]);
		Server::get(ISystemTagObjectMapper::class)->assignTags((string)$hidden->getId(),'files',[(string)$tag->getId()]);
		foreach([(int)$file->getId(),(int)$hidden->getId(),(int)$outside->getId(),(int)$file->getId()+1000000000] as $i=>$id){
			$this->event($uid,'files','files',$id,'changed_self',[[$id=>'/historical-secret']],1800000000+$i);
		}
		$args=['app'=>'files','from'=>'2027-01-15T08:00:00Z','to'=>'2027-01-15T08:01:00Z','folder_id'=>(int)$folder->getId()];
		$headers=['Authorization: '.$credential->authorization()];$path=Http::BASE.'/remote.php/dav/files/'.rawurlencode($uid).'/current-folder/current.txt';
		$bodyBefore=Http::request('GET',$path,$headers);self::assertSame(200,$bodyBefore['status']);
		$client->tool('activity_list_events',['app'=>'no_matching_app']); // Complete Basic-token login before the strict preference snapshot.
		$before=$this->snapshot($uid);$reply=$client->tool('activity_list_events',$args);self::assertTrue($reply->ok(),$reply->describe());
		self::assertCount(1,$reply->json()['items']);$ref=$reply->json()['items'][0]['references'][0];
		self::assertSame((int)$file->getId(),$ref['id']);self::assertSame('current.txt',$ref['name']);self::assertSame('/current-folder/current.txt',$ref['path']);
		self::assertStringNotContainsString('historical-secret',$reply->fingerprint());self::assertStringNotContainsString('hidden.txt',$reply->fingerprint());self::assertStringNotContainsString('body stays private',$reply->fingerprint());
		self::assertSame($before,$this->snapshot($uid),'source authorization and projection do not update Activity, notifications or read preferences');
		$bodyAfter=Http::request('GET',$path,$headers);self::assertSame($bodyBefore['body'],$bodyAfter['body']);
		Server::get(GrantPolicy::class)->setGrant($uid,'files','read',false);
		$denied=$client->tool('activity_list_events',array_diff_key($args,['folder_id'=>true]));self::assertTrue($denied->ok(),$denied->describe());self::assertSame([],$denied->json()['items']);
		self::assertTrue($client->tool('activity_list_events',$args)->isError(),'folder preflight needs its own Files read grant');
		Server::get(GrantPolicy::class)->setGrant($uid,'files','read',true);
		Server::get(PolicyWriter::class)->setResourceScope(Server::get(PolicyStateMapper::class)->state()['revision'],'admin',$uid,'files',[]);
		self::assertTrue($client->tool('activity_list_events',$args)->isError(),'scope-excluded folder is uniformly unavailable');
	}
	/** @return void existing scoped Calendar event reader provides current classified text and never historical summary */
	public function testCalendarReferencesReapplyCurrentCollectionScope(): void {
		[$uid,$client,$credential]=$this->actor();Server::get(GrantPolicy::class)->setGrant($uid,'calendar','read',true);
		$headers=['Authorization: '.$credential->authorization()];$path='/remote.php/dav/calendars/'.rawurlencode($uid).'/personal/activity-reference.ics';
		$ics="BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//MCP//Activity reference proof//EN\r\nBEGIN:VEVENT\r\nUID:mcp-activity-reference\r\nDTSTAMP:20270101T000000Z\r\nDTSTART:20270601T120000Z\r\nDTEND:20270601T130000Z\r\nSUMMARY:Current authorized summary\r\nCLASS:PRIVATE\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
		$put=Http::request('PUT',Http::BASE.$path,[...$headers,'Content-Type: text/calendar'],$ics);self::assertContains($put['status'],[201,204]);
		$calendars=Server::get(GrantPolicy::class)->within($uid,fn()=>Server::get(ScopedCalendarLookup::class)->visible($uid));
		$calendar=array_values(array_filter($calendars,static fn($cal)=>$cal->uri==='personal'))[0];
		$this->event($uid,'dav','calendar',$calendar->id,'object_update_event_self',['actor'=>$uid,'calendar'=>['id'=>$calendar->id,'uri'=>'stale-uri','name'=>'Historical calendar secret'],
			'object'=>['id'=>'mcp-activity-reference','name'=>'Historical summary secret','classified'=>false]],1800000000);
		$args=['app'=>'dav','from'=>'2027-01-15T08:00:00Z','to'=>'2027-01-15T08:01:00Z'];
		$client->tool('activity_list_events',['app'=>'no_matching_app']);$before=$this->snapshot($uid);$eventBefore=Http::request('GET',Http::BASE.$path,$headers);
		$reply=$client->tool('activity_list_events',$args);self::assertTrue($reply->ok(),$reply->describe());self::assertCount(1,$reply->json()['items']);
		self::assertSame('Current authorized summary',$reply->json()['items'][0]['references'][0]['name']);self::assertStringNotContainsString('Historical',$reply->fingerprint());
		self::assertSame($before,$this->snapshot($uid));$after=Http::request('GET',Http::BASE.$path,$headers);self::assertSame($eventBefore['body'],$after['body']);
		Server::get(PolicyWriter::class)->setResourceScope(Server::get(PolicyStateMapper::class)->state()['revision'],'admin',$uid,'calendar',[]);
		$omitted=$client->tool('activity_list_events',$args);self::assertTrue($omitted->ok(),$omitted->describe());self::assertSame([],$omitted->json()['items']);
	}
	/** @return void FilesHooks keeps the destination folder first for a move without rename; both current references must pass */
	public function testNativeMoveWithoutRenameShowsCurrentFileAndDestination(): void {
		[$uid,$client]=$this->actor();
		[$folder,$file]=NativeActorContext::run($uid,function()use($uid){
			$folder=Server::get(IRootFolder::class)->getUserFolder($uid)->newFolder('current-destination');return [$folder,$folder->newFile('moved.txt','moved body')];
		});
		$this->event($uid,'files','files',(int)$file->getId(),'moved_self',[[(int)$folder->getId()=>'/historical-secret/'],[(int)$file->getId()=>'/historical-secret/moved.txt']],1800000000);
		$reply=$client->tool('activity_list_events',['app'=>'files','from'=>'2027-01-15T08:00:00Z','to'=>'2027-01-15T08:01:00Z']);self::assertTrue($reply->ok(),$reply->describe());
		self::assertSame([(int)$file->getId(),(int)$folder->getId()],array_column($reply->json()['items'][0]['references'],'id'));
		self::assertSame(['/current-destination/moved.txt','/current-destination'],array_column($reply->json()['items'][0]['references'],'path'));
		self::assertStringNotContainsString('historical-secret',$reply->fingerprint());
	}
	/** @return void a sharee sees CONFIDENTIAL only as busy and never a PRIVATE event, whatever the stored Activity text says */
	public function testCalendarClassificationForNonOwner(): void {
		[$reader,$client]=$this->actor();[$owner,,$ownerCredential]=$this->actor();
		Server::get(GrantPolicy::class)->setGrant($reader,'calendar','read',true);
		$headers=['Authorization: '.$ownerCredential->authorization()];$base=Http::BASE.'/remote.php/dav/calendars/'.rawurlencode($owner).'/personal/';
		foreach(['CONFIDENTIAL'=>'mcp-activity-confidential','PRIVATE'=>'mcp-activity-private'] as $class=>$eventUid){
			$ics="BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//MCP//Activity classification proof//EN\r\nBEGIN:VEVENT\r\nUID:$eventUid\r\nDTSTAMP:20270101T000000Z\r\nDTSTART:20270601T120000Z\r\nDTEND:20270601T130000Z\r\nSUMMARY:Owner $class secret summary\r\nCLASS:$class\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
			$put=Http::request('PUT',$base.$eventUid.'.ics',[...$headers,'Content-Type: text/calendar'],$ics);self::assertContains($put['status'],[201,204]);
		}
		$share='<?xml version="1.0" encoding="utf-8"?><o:share xmlns:d="DAV:" xmlns:o="http://owncloud.org/ns"><o:set><d:href>principal:principals/users/'.$reader.'</d:href><o:read-write/></o:set></o:share>';
		self::assertContains(Http::request('POST',$base,[...$headers,'Content-Type: application/xml; charset=utf-8'],$share)['status'],[200,204]);
		$calendars=Server::get(GrantPolicy::class)->within($reader,fn()=>Server::get(ScopedCalendarLookup::class)->visible($reader));
		$calendar=array_values(array_filter($calendars,static fn($cal)=>$cal->uri==='personal_shared_by_'.$owner))[0];
		foreach(['mcp-activity-confidential'=>true,'mcp-activity-private'=>false] as $eventUid=>$classified){
			$this->event($reader,'dav','calendar',$calendar->id,'object_update_event',['actor'=>$owner,'calendar'=>['id'=>$calendar->id,'uri'=>'personal','name'=>'Historical calendar'],
				'object'=>['id'=>$eventUid,'name'=>'Historical name','classified'=>$classified]],1800000000+($classified?1:0));
		}
		$reply=$client->tool('activity_list_events',['app'=>'dav','from'=>'2027-01-15T08:00:00Z','to'=>'2027-01-15T08:01:00Z']);self::assertTrue($reply->ok(),$reply->describe());
		self::assertCount(1,$reply->json()['items'],'the PRIVATE event of another owner is omitted entirely');
		self::assertSame(EventMapper::BUSY_SUMMARY,$reply->json()['items'][0]['references'][0]['name']);
		self::assertSame('mcp-activity-confidential',$reply->json()['items'][0]['references'][0]['uid']);
		self::assertStringNotContainsString('secret summary',$reply->fingerprint());self::assertStringNotContainsString('Historical',$reply->fingerprint());
	}
	/** @return array{string,McpClient,Credential} actor, client and prepared login */
	private function actor(): array {
		$uid='mcp-it-activity-ref-'.bin2hex(random_bytes(4));$actor=Server::get(IUserManager::class)->createUser($uid,bin2hex(random_bytes(24)));self::assertInstanceOf(IUser::class,$actor);$this->actors[]=$actor;
		Server::get(IEventDispatcher::class)->dispatchTyped(new UserFirstTimeLoggedInEvent($actor));
		$config=Server::get(IConfig::class);$this->service??=$config->getAppValue('mcp',GrantPolicy::SERVICE_KEY,'');$policy=Server::get(GrantPolicy::class);
		$policy->setGlobalEnabled(true);$policy->setEligible($uid,true);$policy->setConnected($uid,true);$policy->setGrant($uid,'activity','read',true);
		$credential=LoginFactory::basic($uid);return [$uid,new McpClient($credential),$credential];
	}
	/**
	 * @param string $uid owner
	 * @param string $app producer
	 * @param string $type object type
	 * @param int $id object ID
	 * @param string $subject known producer subject
	 * @param array $params producer schema
	 * @param int $time period fixture
	 * @return void seed through native Activity, never during the tool read
	 */
	private function event(string $uid,string $app,string $type,int $id,string $subject,array $params,int $time): void {
		$event=Server::get(IManager::class)->generateEvent()->setAffectedUser($uid)->setAuthor($uid)->setApp($app)->setType('mcp_reference_probe')->setObject($type,$id,'/historical-secret')->setSubject($subject,$params)->setTimestamp($time);
		Server::get(\OCA\Activity\Data::class)->send($event);
	}
	/**
	 * @param string $uid owned actor
	 * @return array<string,array> fresh read-only database evidence
	 */
	private function snapshot(string $uid): array {
		$out=[];
		foreach(['activity'=>'affecteduser','activity_mq'=>'amq_affecteduser','notifications'=>'user','preferences'=>'userid'] as $table=>$column){
			$q=Server::get(IDBConnection::class)->getQueryBuilder();$q->select('*')->from($table)->where($q->expr()->eq($column,$q->createNamedParameter($uid)));
			$out[$table]=$q->executeQuery()->fetchAllAssociative();
		}
		return $out;
	}
}
