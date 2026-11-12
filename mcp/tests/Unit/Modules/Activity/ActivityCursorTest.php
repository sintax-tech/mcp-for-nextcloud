<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Activity;

use InvalidArgumentException;
use OCA\Mcp\Modules\Activity\Integration\ActivityGateway;
use OCA\Mcp\Modules\Activity\Reading\ActivityInput;
use OCA\Mcp\Modules\Activity\Reading\ListActivityHandler;
use OCP\Activity\IEvent;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/** Missing, expired and foreign IDs must never be distinguished through native fallback behaviour. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ActivityCursorTest extends TestCase {
	/** @return void both the native exception and the public refusal are byte-identical for all invalid cursor cases */
	public function testMissingForeignAndRestartedCursorsHaveTheSameRefusal(): void {
		$exceptions=$replies=[];
		foreach ([['missing',null,PHP_INT_MAX],['foreign','bob',7],['newer','alice',7],['equal','alice',7],['native rejection','alice',7]] as [$case,$owner,$since]) {
			$event=$this->createMock(IEvent::class); $event->method('getAffectedUser')->willReturn($owner ?? '');
			$calls=0;
			$native=ActivityNativeFixture::gateway($this, function () use ($case,&$calls): array {
				$calls++;
				self::assertNotContains($case,['missing','foreign'],'an invalid lookup must not read or restart the stream');
				if ($case==='native rejection') throw new \OutOfBoundsException('foreign owner details');
				return ['data'=>[ActivityNativeFixture::row($case==='equal'?7:8)],'has_more'=>false];
			},static fn ()=>$owner===null?null:$event);
			try { $native->page('alice',$since,50); self::fail('Invalid cursor accepted'); }
			catch (InvalidArgumentException $error) { $exceptions[]=[get_class($error),$error->getMessage()]; }
			self::assertSame(in_array($case,['missing','foreign'],true)?0:1,$calls);
			$reply=(new ListActivityHandler($native,new ActivityInput()))->handle('alice',['since'=>$since]);
			self::assertTrue($reply['isError']); $replies[]=$reply;
		}
		foreach ($exceptions as $exception) self::assertSame([InvalidArgumentException::class,ActivityGateway::INVALID_CURSOR],$exception);
		foreach ($replies as $reply) self::assertSame($replies[0],$reply);
	}
	/** @return void zero still starts a fresh read and does not invoke a cursor lookup */
	public function testFreshFeedDoesNotLookUpACursor(): void {
		$native=ActivityNativeFixture::gateway($this,static fn()=>['data'=>[],'has_more'=>false],static fn()=>self::fail('since=0 has no cursor'));
		self::assertSame(['data'=>[],'has_more'=>false],$native->page('alice',0,50));
	}
}
