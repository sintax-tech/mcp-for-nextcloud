<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Activity;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\References\ReadableCalendarEvent;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Access\ResourceScope\ScopedCalendarLookup;
use OCA\Mcp\Modules\Activity\Reading\ActivityCalendarReferences;
use OCA\Mcp\Modules\Activity\Reading\ActivityFileReferences;
use OCA\Mcp\Modules\Activity\Reading\ActivityObjectProjection;
use OCA\Mcp\Modules\Activity\Reading\ActivityObjectShapes;
use OCA\Mcp\Modules\Activity\Reading\ActivityOriginAccess;
use OCA\Mcp\Platform\FileAccess\ActorRoot;
use OCA\Mcp\Platform\References\ReferenceRead;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/** Real Activity projection over effect-denying native OCP doubles; every source gate is independently controllable. */
final class ActivityProjectionRig {
	public bool $filesRead = true;
	public bool $calendarRead = true;
	public bool $guests = true;
	public bool $calendarApp = true;
	public bool $scopeUnavailable = false;
	public string $sessionUid = 'alice';
	/** @var array<int,list<Node>> current actor aliases */
	public array $aliases = [];
	/** @var list<string> exclusions, backup or scope refusals */
	public array $deniedPaths = [];
	/** @var list<int> native actor-relative ID lookups */
	public array $lookups = [];
	/** @var list<string> every alias inspected by the Files guard */
	public array $guarded = [];
	/** @var list<\OCA\Mcp\Platform\Dav\Calendar> scoped current collections */
	public array $calendars = [];
	/** @var array<string,ReferenceRead> existing port's classified outcomes */
	public array $events = [];
	/** @var list<array{string,string,string}> actor/path/UID sent to the event port */
	public array $eventReads = [];
	public int $rootReads = 0;
	public int $scopeReads = 0;
	public int $calendarLists = 0;
	public Folder $root;
	public ActivityObjectProjection $projection;
	/** @param TestCase $test owns strict native mocks */
	public function __construct(private TestCase $test) {
		$this->root=$this->node(1,'/alice/files',null,true,true);
		$this->root->method('getRelativePath')->willReturnCallback(static fn($path)=>$path==='/alice/files'?'':(str_starts_with($path,'/alice/files/')?substr($path,13):null));
		$this->root->method('getById')->willReturnCallback(function($id){$this->lookups[]=$id;return $this->aliases[$id]??[];});
		$roots=$this->mock(IRootFolder::class); $this->neverWrite($roots); $roots->expects($test::never())->method('get');
		$roots->method('getUserFolder')->with('alice')->willReturnCallback(function(){ $this->rootReads++; return $this->root; });
		$roots->method('getMountsIn')->with('/alice/files')->willReturn([]);
		$user=$this->mock(IUser::class); $user->method('getUID')->willReturnCallback(fn()=>$this->sessionUid);
		$session=$this->mock(IUserSession::class); $session->method('getUser')->willReturn($user);
		$policy=$this->mock(GrantPolicy::class); $policy->method('granted')->willReturnCallback(fn($uid,$module,$op)=>$uid==='alice' && $op==='read' && ($module==='files'?$this->filesRead:$this->calendarRead));
		$guests=$this->mock(GuestRestrictions::class); $guests->method('allowsApp')->willReturnCallback(fn()=>$this->guests);
		$apps=$this->mock(IAppManager::class); $apps->method('isEnabledForUser')->willReturnCallback(fn()=>$this->calendarApp);
		$guard=$this->mock(FilesAccessGuard::class);
		$guard->method('assertScopeReadable')->willReturnCallback(function(){ $this->scopeReads++; if($this->scopeUnavailable)throw new PolicyUnavailable('private scope failure'); });
		$guard->method('isVisible')->willReturnCallback(function($node){$this->guarded[]=$node->getPath();return !in_array($node->getPath(),$this->deniedPaths,true);});
		$urls=$this->mock(IURLGenerator::class); $urls->method('linkToRouteAbsolute')->with('files.viewcontroller.showFile')->willReturnCallback(static fn($route,$args)=>'https://own.invalid/f/'.$args['fileid']);
		$urls->method('linkToRoute')->with('calendar.view.index')->willReturn('/apps/calendar/'); $urls->method('getWebroot')->willReturn('/cloud');
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn($path)=>'https://own.invalid'.$path);
		$collections=$this->mock(ScopedCalendarLookup::class); $collections->method('visible')->with('alice','VEVENT')->willReturnCallback(function(){ $this->calendarLists++;if($this->scopeUnavailable)throw new PolicyUnavailable('private scope failure');return $this->calendars; });
		$collections->expects($test::never())->method('resolveWritable');
		$events=$this->mock(ReadableCalendarEvent::class); $events->method('read')->willReturnCallback(function($uid,$path,$id){$this->eventReads[]=[$uid,$path,$id];return $this->events[$id]??new ReferenceRead();});
		$access=new ActivityOriginAccess($policy,$guests,$apps,$session);
		$this->projection=new ActivityObjectProjection(new ActivityObjectShapes(),new ActivityFileReferences($access,new ActorRoot($roots,$session),$guard,$urls),new ActivityCalendarReferences($access,$collections,$events,$urls));
	}
	/**
	 * @param class-string $class native collaborator
	 * @return object test-owned mock
	 */
	private function mock(string $class): object { return $this->test->getMockBuilder($class)->disableOriginalConstructor()->getMock(); }
	/**
	 * @param int $id native identity
	 * @param string $path current actor path
	 * @param Node|null $parent current parent
	 * @param bool $readable native ACL
	 * @param bool $folder native folder type
	 * @return File|Folder no mutator or content read can be called
	 */
	public function node(int $id,string $path,?Node $parent=null,bool $readable=true,bool $folder=false): File|Folder {
		$node=$this->mock($folder?Folder::class:File::class); $this->neverWrite($node);
		$node->method('getId')->willReturn($id); $node->method('getPath')->willReturn($path); $node->method('getName')->willReturn(basename($path));
		$node->method('isReadable')->willReturn($readable);
		if($parent!==null)$node->method('getParent')->willReturn($parent);
		if(!$folder)$node->expects($this->test::never())->method('getContent');
		return $node;
	}
	/**
	 * @param object $node native root/node double
	 * @return void read projection cannot invoke any mutation
	 */
	private function neverWrite(object $node): void {
		foreach(['newFile','newFolder','putContent','delete','move','copy','touch','lock','unlock'] as $method) if(method_exists($node,$method))$node->expects($this->test::never())->method($method);
	}
	/**
	 * @param int $id primary current file ID
	 * @param array|null $params producer params
	 * @param string $subject known subject
	 * @return array native Files event with historical bait
	 */
	public static function file(int $id=10,?array $params=null,string $subject='changed_self'): array {
		return ['app'=>'files','object_type'=>'files','object_id'=>$id,'subject'=>$subject,'subjectparams'=>json_encode($params??[[$id=>'/historical-secret']]),
			'file'=>'/historical-secret','link'=>'https://evil.invalid/old-secret','message'=>'','messageparams'=>'[]'];
	}
	/**
	 * @param string $uid event UID
	 * @param int $id current calendar identity
	 * @return array native one-collection VEVENT with historical bait
	 */
	public static function calendar(string $uid='event-uid',int $id=20): array {
		return ['app'=>'dav','object_type'=>'calendar','object_id'=>$id,'subject'=>'object_update_event_self','message'=>'','messageparams'=>'[]',
			'subjectparams'=>json_encode(['actor'=>'alice','calendar'=>['id'=>$id,'uri'=>'historical-uri','name'=>'historical-calendar-secret'],
			'object'=>['id'=>$uid,'name'=>'historical-summary-secret','classified'=>true,'link'=>['owner'=>'foreign','object_uri'=>'secret.ics','calendar_uri'=>'old']]])];
	}
}
