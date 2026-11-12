<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Activity;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Modules\Activity\ActivityReferenceUnavailable;
use OCA\Mcp\Platform\Dav\Calendar;
use OCA\Mcp\Platform\References\ReferenceRead;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Current-origin authorization for every emitted reference, independent of historical Activity payload text. */
final class ActivityProjectionTest extends TestCase {
	/** @return void all aliases pass first; canonical output contains only fresh authorized metadata */
	public function testCurrentFileProjectionDiscardsHistoricalTextAndLinks(): void {
		$r=new ActivityProjectionRig($this); $folder=$r->node(2,'/alice/files/project',$r->root,true,true);
		$r->aliases[10]=[$r->node(10,'/alice/files/project/current.txt',$folder),$r->node(10,'/alice/files/other-alias.txt',$r->root)];
		$view=$r->projection->begin('alice',null); $out=$r->projection->project($view,$r::file());
		self::assertSame(['action'=>'changed','references'=>[['type'=>'file','id'=>10,'name'=>'other-alias.txt','path'=>'/other-alias.txt','url'=>'https://own.invalid/f/10']]],$out);
		self::assertCount(2,$r->guarded); self::assertSame(1,$r->rootReads); self::assertSame(1,$r->scopeReads);
		self::assertStringNotContainsString('secret',json_encode($out)); self::assertStringNotContainsString('evil.invalid',json_encode($out));
	}
	/** @return iterable<string,array{string}> all denials uniformly omit, without falling back to stored names */
	public static function denied(): iterable {
		foreach(['grant','guests','session','missing','unreadable','hidden alias','scope alias','backup alias','foreign tree'] as $reason)yield $reason=>[$reason];
	}
	/**
	 * @param string $reason refusal source
	 * @return void no reason, hidden count or stale path is returned
	 */
	#[DataProvider('denied')]
	public function testEveryFileGateOmitsTheEntireEvent(string $reason): void {
		$r=new ActivityProjectionRig($this); $r->aliases[10]=[$r->node(10,'/alice/files/clean.txt',$r->root)];
		if($reason==='grant')$r->filesRead=false;
		if($reason==='guests')$r->guests=false;
		if($reason==='session')$r->sessionUid='bob';
		if($reason==='missing')$r->aliases=[];
		if($reason==='unreadable')$r->aliases[10][]=$r->node(10,'/alice/files/no-read.txt',$r->root,false);
		if(in_array($reason,['hidden alias','scope alias','backup alias'],true)){
			$path='/alice/files/'.$reason.'.txt';$r->aliases[10][]=$r->node(10,$path,$r->root);$r->deniedPaths[]=$path;
		}
		if($reason==='foreign tree')$r->aliases[10][]=$r->node(10,'/bob/files/secret.txt',$r->root);
		self::assertNull($r->projection->project($r->projection->begin('alice',null),$r::file()));
		if(in_array($reason,['grant','guests','session'],true))self::assertSame(0,$r->rootReads);
	}
	/** @return void folder selection follows current ancestor IDs, while aliases outside it still receive every gate */
	public function testFolderUsesCurrentAncestryAndAllAliases(): void {
		$r=new ActivityProjectionRig($this); $folder=$r->node(2,'/alice/files/renamed-folder',$r->root,true,true);$r->aliases[2]=[$folder];
		$r->aliases[10]=[$r->node(10,'/alice/files/renamed-folder/current.txt',$folder),$r->node(10,'/alice/files/another-mount.txt',$r->root)];
		$view=$r->projection->begin('alice',2);$out=$r->projection->project($view,$r::file());
		self::assertSame('/renamed-folder/current.txt',$out['references'][0]['path']);
		self::assertContains('/alice/files/another-mount.txt',$r->guarded);
		$r->aliases[10]=[$r->node(10,'/alice/files/moved-away.txt',$r->root)];
		self::assertNull($r->projection->project($view,$r::file()));
		self::assertNull($r->projection->project($view,['app'=>'mcp','object_id'=>0]));
		self::assertNull($r->projection->project($view,$r::calendar()));
		self::assertNull($r->projection->project($view,$r::file(2)),'folder itself is not a strict descendant');
	}
	/** @return void unknown or refused explicit folder is uniformly unavailable before any feed query */
	public function testFolderPreflightAndUnreadableScope(): void {
		$r=new ActivityProjectionRig($this);
		try{$r->projection->begin('alice',99);self::fail('missing folder accepted');}catch(ActivityReferenceUnavailable){self::assertSame([99],$r->lookups);}
		$r->scopeUnavailable=true;
		$this->expectException(PolicyUnavailable::class);$r->projection->project($r->projection->begin('alice',null),$r::file());
	}
	/** @return void both rename/move references need current authorization, not just the primary object */
	public function testAllRecognizedParameterReferencesMustBeAuthorized(): void {
		$r=new ActivityProjectionRig($this); $r->aliases[10]=[$r->node(10,'/alice/files/new.txt',$r->root)];
		$row=$r::file(10,[[10=>'/old-hidden-path'],[11=>'/another-secret']],'moved_self');
		self::assertNull($r->projection->project($r->projection->begin('alice',null),$row));
		$r->aliases[11]=[$r->node(11,'/alice/files/other-current.txt',$r->root)];
		$out=$r->projection->project($r->projection->begin('alice',null),$row);self::assertSame([10,11],array_column($out['references'],'id'));
		self::assertStringNotContainsString('secret',json_encode($out));
		$unknown=$r::file(10,[[10=>'/old'],['board'=>77]],'changed_self');self::assertNull($r->projection->project($r->projection->begin('alice',null),$unknown));
		$unknown=$r::file();$unknown['messageparams']='["hidden-reference"]';self::assertNull($r->projection->project($r->projection->begin('alice',null),$unknown));
	}
	/** @return iterable<string,array{string,array}> FilesHooks::generateMoveActivities shapes, destination folder first when the name is kept */
	public static function moves(): iterable {
		yield 'moved_self kept name'=>['moved_self',[[2=>'/old-destination/'],[10=>'/old-secret/current.txt']]];
		yield 'moved_by kept name'=>['moved_by',[[2=>'/old-destination/'],'bob',[10=>'/old-secret/current.txt']]];
	}
	/**
	 * @param string $subject native move subject
	 * @param array $params native producer parameters
	 * @return void file and destination folder are both authorized and shown only with current metadata
	 */
	#[DataProvider('moves')]
	public function testMoveWithoutRenameAuthorizesFileAndDestination(string $subject,array $params): void {
		$r=new ActivityProjectionRig($this);$folder=$r->node(2,'/alice/files/renamed-destination',$r->root,true,true);$r->aliases[2]=[$folder];
		$r->aliases[10]=[$r->node(10,'/alice/files/renamed-destination/current.txt',$folder)];
		$out=$r->projection->project($r->projection->begin('alice',null),$r::file(10,$params,$subject));
		self::assertSame('moved',$out['action']);self::assertSame([10,2],array_column($out['references'],'id'));
		self::assertSame(['/renamed-destination/current.txt','/renamed-destination'],array_column($out['references'],'path'));
		self::assertStringNotContainsString('old-',json_encode($out));
		$selected=$r->projection->project($r->projection->begin('alice',2),$r::file(10,$params,$subject));
		self::assertSame([10,2],array_column($selected['references'],'id'),'moving into the selected folder matches by the file object');
		$r->deniedPaths[]='/alice/files/renamed-destination';
		self::assertNull($r->projection->project($r->projection->begin('alice',null),$r::file(10,$params,$subject)),'a denied destination omits the entire event');
	}
	/** @return void a move with rename keeps a single file reference, while an unrelated last map is never accepted */
	public function testMoveWithRenameAndMismatchedObject(): void {
		$r=new ActivityProjectionRig($this);$r->aliases[10]=[$r->node(10,'/alice/files/new.txt',$r->root)];$r->aliases[2]=[$r->node(2,'/alice/files/dest',$r->root,true,true)];
		$out=$r->projection->project($r->projection->begin('alice',null),$r::file(10,[[10=>'/new.txt'],[10=>'/old-secret.txt']],'moved_self'));
		self::assertSame([10],array_column($out['references'],'id'));self::assertStringNotContainsString('secret',json_encode($out));
		self::assertNull($r->projection->project($r->projection->begin('alice',null),$r::file(10,[[2=>'/dest/'],[11=>'/other.txt']],'moved_self')));
		self::assertNull($r->projection->project($r->projection->begin('alice',null),$r::file(10,[[2=>'/dest/'],[10=>'/x.txt']],'renamed_self')),'only moves carry a destination folder');
	}
	/** @return void alias or ancestry ambiguity never becomes a path-based bypass */
	public function testAliasCapAndCyclicAncestryOmit(): void {
		$r=new ActivityProjectionRig($this);$r->aliases[10]=array_fill(0,51,$r->node(10,'/alice/files/current.txt',$r->root));
		self::assertNull($r->projection->project($r->projection->begin('alice',null),$r::file()));
		$r->aliases[2]=[$r->node(2,'/alice/files/selected',$r->root,true,true)];
		$cycle=$r->node(3,'/alice/files/cycle',null,true,true);$cycle->method('getParent')->willReturn($cycle);
		$r->aliases[10]=[$r->node(10,'/alice/files/cycle/current.txt',$cycle)];
		self::assertNull($r->projection->project($r->projection->begin('alice',2),$r::file()));
	}
	/** @return void an unrecognized nested Calendar link prevents every source lookup */
	public function testUnknownCalendarParameterReferencesOmitBeforeLookup(): void {
		$r=new ActivityProjectionRig($this);$row=$r::calendar();$params=json_decode($row['subjectparams'],true);
		$params['object']['link']['file_id']=99;$row['subjectparams']=json_encode($params);
		self::assertNull($r->projection->project($r->projection->begin('alice',null),$row));
		self::assertSame(0,$r->calendarLists);
	}

	/** @return void Calendar uses current scoped ID/path and the existing full-object classification port */
	public function testCalendarUsesExistingScopedAndClassifiedPorts(): void {
		$r=new ActivityProjectionRig($this);$path='/remote.php/dav/calendars/alice/current-shared/';
		$r->calendars=[new Calendar(20,'current-shared','Current calendar','bob','principals/users/bob',false,$path)];
		$r->events['event-uid']=new ReferenceRead(['title'=>'Busy','path'=>$path,'uri'=>'current.ics']);
		$out=$r->projection->project($r->projection->begin('alice',null),$r::calendar());
		self::assertSame('Busy',$out['references'][0]['name']);self::assertSame([['alice',$path,'event-uid']],$r->eventReads);
		self::assertStringNotContainsString('historical',json_encode($out));self::assertStringNotContainsString('foreign',json_encode($out));
		$r->calendars=[];self::assertNull($r->projection->project($r->projection->begin('alice',null),$r::calendar()));
		self::assertCount(1,$r->eventReads,'outside current collection scope never looks up the UID');
	}
	/** @return void Calendar source grant, Guests/app and current UID each remain independent of Activity permission */
	public function testCalendarDenialsAndUnsupportedTypesNeverFallBackToHistory(): void {
		foreach(['grant','guests','app','missing UID'] as $denial){
			$r=new ActivityProjectionRig($this);if($denial==='grant')$r->calendarRead=false;if($denial==='guests')$r->guests=false;if($denial==='app')$r->calendarApp=false;
			$r->calendars=[new Calendar(20,'cal','Cal','alice','principals/users/alice',true,'/remote.php/dav/calendars/alice/cal/')];
			self::assertNull($r->projection->project($r->projection->begin('alice',null),$r::calendar()));
		}
		$r=new ActivityProjectionRig($this);
		foreach([['app'=>'deck','object_type'=>'deck_card','object_id'=>10],['app'=>'deck','object_type'=>'deck_board','object_id'=>10],array_replace($r::calendar(),['subject'=>'object_move_event']),array_replace($r::calendar(),['subject'=>'object_update_todo'])] as $row)self::assertNull($r->projection->project($r->projection->begin('alice',null),$row));
		self::assertSame(0,$r->calendarLists);self::assertSame(0,$r->rootReads);
	}
}
