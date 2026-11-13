<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Notifications;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Mcp\Tools\{ToolRegistry, ToolContext};
use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCA\Mcp\Modules\Notifications\Integration\{NotificationsGateway, NotificationsSurface};
use OCA\Mcp\Modules\Notifications\NotificationsModule;
use OCA\Mcp\Modules\Notifications\Presentation\NotificationsSchema;
use OCA\Mcp\Modules\Notifications\Reading\ListNotificationsHandler;
use OCA\Mcp\Modules\Notifications\Reading\NotificationsInput;
use OCA\Mcp\Modules\Notifications\Tools\ListNotifications;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/** Admission, direct-call validation and lazy failures cannot resolve native effects. */
final class NotificationsAdmissionTest extends TestCase {
	/** @return void malformed limits and invented users/cursors never resolve the native handler or factory */
	public function testValidationPrecedesNativeResolution(): void {
		$container=$this->createMock(ContainerInterface::class); $container->expects(self::never())->method('get');
		$manager=$this->createMock(IManager::class); $manager->expects(self::never())->method('createNotification');
		$tool=new ListNotifications(new ListNotificationsHandler(new NotificationsGateway($container,$manager,new NotificationsSurface('MissingHandler')),new NotificationsInput()));
		foreach ([['limit'=>0],['limit'=>201],['limit'=>'50'],['limit'=>50.0],['limit'=>null],['limit'=>true],['user'=>'bob'],['since'=>3],['after_id'=>3]] as $args) {
			self::assertTrue($tool->execute($args,new ToolContext('alice'))['isError']);
		}
		self::assertSame('read',ListNotifications::definition()->operation);
		self::assertFalse(ListNotifications::definition()->inputSchema['additionalProperties']);
		self::assertSame([NotificationsSchema::NOTE, NotificationsSchema::DISMISS],(new NotificationsModule($container))->guideNotes());
	}
	/** @return void unavailable shape refuses even on direct work-class invocation */
	public function testMissingNativeShapeRefusesBeforeFactory(): void {
		$container=$this->createMock(ContainerInterface::class); $container->expects(self::never())->method('get');
		$manager=$this->createMock(IManager::class); $manager->expects(self::never())->method('createNotification');
		$this->expectException(PolicyUnavailable::class);
		(new NotificationsGateway($container,$manager,new NotificationsSurface('MissingHandler')))->latest('alice',50);
	}
	/** @return void grants, user-app enablement, Guests and capability all hide discovery and reject direct calls lazily */
	public function testRegistryAdmissionIsOptInAndLazy(): void {
		foreach ([[false,true,true,true],[true,false,true,true],[true,true,false,true],[true,true,true,false],[true,true,true,true]] as [$grant,$enabled,$capable,$guestAllowed]) {
			$container=$this->createMock(ContainerInterface::class); $container->expects(self::never())->method('get');
			$policy=$this->createMock(GrantPolicy::class);
			$policy->method('grantedAny')->willReturnCallback(static function (string $uid, string $module, array $operations) use ($grant): bool {
				return $grant && $uid === 'alice' && $module === 'notifications' && in_array($operations, [['read'], ['dismiss']], true);
			});
			$apps=$this->createMock(IAppManager::class); $apps->method('isEnabledForUser')->willReturn($enabled);
			$users=$this->createMock(IUserManager::class); $users->method('get')->willReturn($this->createMock(IUser::class));
			$guests=$this->createMock(GuestRestrictions::class); $guests->method('allowsApp')->willReturn($guestAllowed);
			$registry=new ToolRegistry([new NotificationsModule($container)],$policy,$apps,$users,new NullLogger(),$guests,new CapabilityRegistry([new NotificationsDiscoveryProbe($capable)],new NullLogger()));
			$allowed=$grant && $enabled && $capable && $guestAllowed;
			self::assertCount($allowed?1:0,array_filter($registry->list('alice'),static fn($t)=>$t['name']==='notifications_list'));
			self::assertCount($allowed?1:0,array_filter($registry->list('alice'),static fn($t)=>$t['name']==='notifications_dismiss'));
			if (!$allowed) {
				try { $registry->call('notifications_list',[],'alice'); self::fail('Unavailable tool admitted'); }
				catch (\InvalidArgumentException) { self::assertTrue(true); }
			}
		}
		self::assertContains('notifications',GrantPolicy::DENIED_READS);
		$policy=$this->createMock(GrantPolicy::class); $policy->method('grantedAny')->willReturn(true);
		$apps=$this->createMock(IAppManager::class); $apps->method('isEnabledForUser')->willReturn(true);
		$users=$this->createMock(IUserManager::class); $users->method('get')->willReturn($this->createMock(IUser::class));
		$registry=new ToolRegistry([new NotificationsModule($this->createMock(ContainerInterface::class))],$policy,$apps,$users,new NullLogger(),null,new CapabilityRegistry([new NotificationsDiscoveryProbe(true,false)],new NullLogger()));
		$names=array_column($registry->list('alice'),'name');
		self::assertContains('notifications_list',$names); self::assertNotContains('notifications_dismiss',$names);
	}
}
