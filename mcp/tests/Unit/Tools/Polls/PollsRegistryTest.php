<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Polls;

use OCA\Mcp\Service\GuestRestrictions;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Registry gates, real read-only annotations, and no exposure from denied/app-ineligible entry points. */
final class PollsRegistryTest extends TestCase {
	/**
	 * Availability scenarios, including Guests app restrictions independently of ordinary account identity.
	 * @return iterable<string,array{0:bool,1:string,2:bool,3:bool,4:bool}> enabled, version, grant, guest, visible
	 */
	public static function availability(): iterable {
		yield 'supported8' => [true,'8.6.3',true,true,true];
		yield 'supported9' => [true,'9.3.0',true,true,true];
		yield 'unpinned shape checked on call' => [true,'9.4.0',true,true,true];
		yield 'old' => [true,'8.6.2',true,true,false];
		yield 'off' => [false,'9.3.0',true,true,false];
		yield 'denied' => [true,'9.3.0',false,true,false];
		yield 'guest disallowed' => [true,'9.3.0',true,false,false];
	}

	/**
	 * Catalog exposure follows app/grants/Guests; a visible read has no confirm and cannot publish future operations.
	 * @param bool $enabled enabled for this user
	 * @param string $version installed release
	 * @param bool $grant explicit read grant
	 * @param bool $guest app allowed by Guests
	 * @param bool $visible expected tool availability
	 * @return void
	 */
	#[DataProvider('availability')]
	public function testRegistryAvailability(bool $enabled,string $version,bool $grant,bool $guest,bool $visible): void {
		$rig=new PollsTestRig($this);
		$rig->enabled=$enabled;$rig->version=$version;
		$rig->policy->setGrant('alice','polls','read',$grant);
		$apps=$this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn($enabled);
		$apps->method('getAppVersion')->willReturn($version);
		$user=$this->createMock(IUser::class);$user->method('getUID')->willReturn('alice');
		$users=$this->createMock(IUserManager::class);$users->method('get')->willReturn($user);
		$guests=$this->createMock(GuestRestrictions::class);$guests->method('allowsApp')->willReturn($guest);
		$registry=new ToolRegistry([$rig->module],$rig->policy,$apps,$users,$this->createMock(LoggerInterface::class),$guests);
		$tools=array_values(array_filter($registry->list('alice'),static fn (array $tool): bool => str_starts_with($tool['name'],'polls_')));
		$this->assertCount($visible?2:0,$tools);
		if (!$visible) {
			$this->expectException(\InvalidArgumentException::class);
			$registry->call('polls_list_polls',[],'alice');
			return;
		}
		foreach ($tools as $tool) {
			$this->assertTrue($tool['annotations']['readOnlyHint']);
			$this->assertFalse($tool['annotations']['destructiveHint']);
			$this->assertArrayNotHasKey('confirm',$tool['inputSchema']['properties']);
		}
		$rig->poll(1);
		$result=$registry->call('polls_list_polls',[],'alice');
		$this->assertArrayNotHasKey('isError',$result);
	}
}
