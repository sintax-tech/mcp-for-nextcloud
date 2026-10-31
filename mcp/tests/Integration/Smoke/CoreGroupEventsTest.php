<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Smoke;

use OC\Group\Database;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\EventProbe;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Group\Events\BeforeGroupDeletedEvent;
use OCP\Group\Events\GroupCreatedEvent;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;

/**
 * Real group events from the real group manager and Database backend reach a listener, including from inside a
 * transaction opened by the caller on the official connection (the situation of contracts §3.3.6).
 */
final class CoreGroupEventsTest extends IntegrationTestCase {
	private EventProbe $probe;
	/** @var list<string> */
	private array $gids = [];

	protected function setUp(): void {
		$this->probe = new EventProbe(Server::get(IEventDispatcher::class), $this->official());
	}

	protected function tearDown(): void {
		$this->probe->detach();
		parent::tearDown();
		foreach ($this->gids as $gid) {
			Server::get(IGroupManager::class)->get($gid)?->delete();
		}
	}

	public function testGroupLifecycleEventsReachAListenerWithTheirPayload(): void {
		foreach ([GroupCreatedEvent::class, UserAddedEvent::class, UserRemovedEvent::class,
			BeforeGroupDeletedEvent::class, GroupDeletedEvent::class] as $class) {
			$this->probe->record($class);
		}
		$alice = $this->alice();

		$group = $this->createGroup();
		$group->addUser($alice);
		$group->removeUser($alice);
		$this->assertTrue($group->delete());

		$this->assertSame([GroupCreatedEvent::class, UserAddedEvent::class, UserRemovedEvent::class,
			BeforeGroupDeletedEvent::class, GroupDeletedEvent::class], $this->probe->classes());
		foreach ($this->probe->seen() as ['event' => $event, 'inTransaction' => $inTransaction]) {
			$this->assertSame($group->getGID(), $event->getGroup()->getGID());
			$this->assertFalse($inTransaction);
		}
		$this->assertSame(Fixtures::ALICE, $this->probe->seen()[1]['event']->getUser()->getUID());
		$this->assertSame(Fixtures::ALICE, $this->probe->seen()[2]['event']->getUser()->getUID());
	}

	public function testAnEventDispatchedInsideTheCallersTransactionFollowsItsRollback(): void {
		$group = $this->createGroup();
		$second = $this->openIndependentConnection();
		$official = $this->official();
		$this->probe->record(UserAddedEvent::class);

		$official->beginTransaction();
		$group->addUser($this->alice());
		$this->assertCount(1, $this->probe->seen());
		$this->assertTrue($this->probe->seen()[0]['inTransaction']);
		$this->assertTrue((new Database($official))->inGroup(Fixtures::ALICE, $group->getGID()));
		$this->assertFalse((new Database($second))->inGroup(Fixtures::ALICE, $group->getGID()));
		$official->rollBack();

		// The event was delivered, yet the membership it announced never existed for anyone.
		$this->assertFalse((new Database($official))->inGroup(Fixtures::ALICE, $group->getGID()));
		$this->assertFalse((new Database($second))->inGroup(Fixtures::ALICE, $group->getGID()));
	}

	public function testAnEventDispatchedInsideTheCallersTransactionFollowsItsCommit(): void {
		$group = $this->createGroup();
		$second = $this->openIndependentConnection();
		$official = $this->official();
		$this->probe->record(UserAddedEvent::class);

		$official->beginTransaction();
		$group->addUser($this->alice());
		$this->assertTrue($this->probe->seen()[0]['inTransaction']);
		$this->assertFalse((new Database($second))->inGroup(Fixtures::ALICE, $group->getGID()));
		$official->commit();

		$this->assertTrue((new Database($second))->inGroup(Fixtures::ALICE, $group->getGID()));
	}

	private function createGroup(): IGroup {
		$gid = 'mcp-it-events-' . bin2hex(random_bytes(6));
		$this->gids[] = $gid;
		$group = Server::get(IGroupManager::class)->createGroup($gid);
		$this->assertInstanceOf(IGroup::class, $group);
		return $group;
	}

	private function alice(): IUser {
		$user = Server::get(IUserManager::class)->get(Fixtures::ALICE);
		$this->assertInstanceOf(IUser::class, $user);
		return $user;
	}
}
