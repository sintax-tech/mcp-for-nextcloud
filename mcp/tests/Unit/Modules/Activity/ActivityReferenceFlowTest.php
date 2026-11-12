<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Activity;

use OCA\Mcp\Modules\Activity\Reading\ActivityInput;
use OCA\Mcp\Modules\Activity\Reading\ListActivityHandler;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/** End-to-end bounded scan over real reference projection and the effect-denying native Activity fixture. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ActivityReferenceFlowTest extends TestCase {
	/** @return void filtered references consume scan budget and advance the native cursor without hidden counts */
	public function testFilteredReferencePagesAdvanceAndContinue(): void {
		$r=new ActivityProjectionRig($this);$folder=$r->node(2,'/alice/files/folder',$r->root,true,true);$r->aliases[2]=[$folder];$r->aliases[10]=[$r->node(10,'/alice/files/folder/current.txt',$folder)];
		$rows=array_map(static fn($id)=>ActivityNativeFixture::row($id,ActivityProjectionRig::file($id===1?10:99)),range(600,1));$calls=0;
		$native=ActivityNativeFixture::gateway($this,function($collector,$settings,$actor,$since,$limit)use($rows,&$calls){
			$calls++;$eligible=array_values(array_filter($rows,static fn($row)=>$since===0 || $row['activity_id']<$since));
			foreach(array_slice($eligible,0,$limit) as $row)$collector->addActivity($row);
			return ['data'=>$collector->getActivities(),'has_more'=>count($eligible)>$limit];
		});
		$handler=new ListActivityHandler($native,new ActivityInput(),$r->projection);
		$first=json_decode($handler->handle('alice',['folder_id'=>2])['content'][0]['text'],true);
		self::assertSame([],$first['items']);self::assertTrue($first['partial']);self::assertTrue($first['truncated']);self::assertSame(101,$first['next_since']);self::assertSame(10,$calls);
		$last=json_decode($handler->handle('alice',$first['continuation'])['content'][0]['text'],true);
		self::assertSame([1],array_column($last['items'],'id'));self::assertSame(10,$last['items'][0]['references'][0]['id']);self::assertFalse($last['truncated']);
		self::assertArrayNotHasKey('omitted',$last);self::assertArrayNotHasKey('examined',$last);self::assertSame(2,$r->rootReads,'a continuation creates a fresh actor view');
	}
	/** @return void folder refusal precedes the Activity query; no source is read to diagnose why it was refused */
	public function testFolderRefusalHappensBeforeNativeFeedRead(): void {
		$r=new ActivityProjectionRig($this);$calls=0;
		$native=ActivityNativeFixture::gateway($this,function()use(&$calls){$calls++;self::fail('feed read before folder authorization');});
		$reply=(new ListActivityHandler($native,new ActivityInput(),$r->projection))->handle('alice',['folder_id'=>99]);
		self::assertTrue($reply['isError']);self::assertSame('The Activity folder or file reference is unavailable.',$reply['content'][0]['text']);self::assertSame(0,$calls);
	}
}
