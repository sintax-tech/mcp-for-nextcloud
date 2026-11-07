<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Polls;

use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCP\App\IAppManager;
use OCP\{IUser,IUserManager};
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;

/** Independent create catalog/registry gate, without relaxing any protected-read predicates. */
final class PollsCreateRegistryTest extends TestCase {
	/** @return iterable<string,array{bool,bool,bool,bool}> enabled, grant, guest, visible */
	public static function gates(): iterable {
		yield 'create only'=>[true,true,true,true];yield 'off'=>[false,true,true,false];
		yield 'denied'=>[true,false,true,false];yield 'guest'=>[true,true,false,false];
	}
	/**
	 * The real registry emits exactly the opted-in write and supplies the real plan/confirm path.
	 * @param bool $enabled app enablement
	 * @param bool $grant independent create grant
	 * @param bool $guest Guests policy
	 * @param bool $visible expected catalog
	 * @return void
	 */
	#[DataProvider('gates')]
	public function testCreateCatalogAndConfirmation(bool $enabled,bool $grant,bool $guest,bool $visible): void {
		$r=new PollsCreateRig($this);$r->base->enabled=$enabled;$r->base->policy->setGrant('alice','polls','create',$grant);
		$apps=$this->createMock(IAppManager::class);$apps->method('isEnabledForUser')->willReturn($enabled);$apps->method('getAppVersion')->willReturn('9.3.0');
		$user=$this->createMock(IUser::class);$user->method('getUID')->willReturn('alice');
		$users=$this->createMock(IUserManager::class);$users->method('get')->willReturn($user);
		$guests=$this->createMock(GuestRestrictions::class);$guests->method('allowsApp')->willReturn($guest);
		$registry=new ToolRegistry([$r->base->module],$r->base->policy,$apps,$users,$this->createMock(LoggerInterface::class),$guests);
		$tools=array_values(array_filter($registry->list('alice'),static fn(array $t):bool=>str_starts_with($t['name'],'polls_')));
		self::assertCount($visible?1:0,$tools);
		if(!$visible){$this->expectException(\InvalidArgumentException::class);$registry->call('polls_create_poll',['type'=>'textPoll','title'=>'x'],'alice');return;}
		self::assertSame('polls_create_poll',$tools[0]['name']);self::assertFalse($tools[0]['annotations']['readOnlyHint']);
		self::assertArrayHasKey('confirm',$tools[0]['inputSchema']['properties']);
		$args=['type'=>'textPoll','title'=>'Registry'];$plan=$registry->call('polls_create_poll',$args,'alice');
		self::assertTrue($plan['structuredContent']['requiresConfirmation']);self::assertSame([],NativeCreateScene::$effects);
		$out=$registry->call('polls_create_poll',$args+['confirm'=>true,'plan_state'=>$plan['structuredContent']['plan_state']],'alice');
		self::assertSame('created',json_decode($out['content'][0]['text'],true)['status']);
	}
	/** @return void read and old eligible policies never acquire create/results/links automatically */
	public function testLegacyPoliciesAndReadStayIndependent(): void {
		$r=new PollsTestRig($this);$r->policy->setGrant('alice','polls','read',true);
		self::assertTrue($r->policy->granted('alice','polls','read'));
		foreach(['create','results','links']as$op){self::assertFalse($r->policy->granted('alice','polls',$op));}
	}
}
