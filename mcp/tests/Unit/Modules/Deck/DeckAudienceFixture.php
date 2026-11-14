<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck;

use OCA\Deck\Db\Acl;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\PermissionService;
use OCA\Mcp\Modules\Deck\Integration\DeckBoardAudience;
use OCP\App\IAppManager;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

require_once __DIR__ . '/../../Tools/Deck/Stubs/deck_stubs.php';

/**
 * A {@see DeckBoardAudience} over Deck doubles: the read check, the board of the list and the ACL rows of that board,
 * with display names for users and groups. A test sets the rows, or makes the read check refuse.
 */
final class DeckAudienceFixture {
	/** @var list<Acl> sharing entries of the board */
	public array $acls = [];
	/** The read check refuses the list, as Deck does for a list the actor cannot read. */
	public bool $refused = false;
	/** @var list<int> lists whose read was checked */
	public array $checked = [];

	/**
	 * @param TestCase $test the test that owns the doubles
	 */
	public function __construct(private TestCase $test) {
	}

	/**
	 * @return DeckBoardAudience the reader, its Deck services answered by this fixture
	 */
	public function audience(): DeckBoardAudience {
		$mock = \Closure::bind(fn (string $type): object => $this->createMock($type), $this->test, TestCase::class);
		$permissions = $mock(PermissionService::class);
		$permissions->method('checkPermission')->willReturnCallback(function ($mapper, $id, int $permission): bool {
			$this->checked[] = (int)$id;
			if ($this->refused || $permission !== Acl::PERMISSION_READ) {
				throw new \OCA\Deck\NoPermissionException('Permission denied');
			}
			return true;
		});
		$stacks = $mock(StackMapper::class);
		$stacks->method('findBoardId')->willReturn(1);
		$fixture = $this;
		$aclMapper = new class($fixture) {
			/** @param DeckAudienceFixture $fixture the rows to answer */
			public function __construct(private DeckAudienceFixture $fixture) {
			}

			/** @param int $boardId the board @return list<Acl> its rows */
			public function findAll(int $boardId): array {
				return $this->fixture->acls;
			}
		};
		$services = [BoardService::class => $mock(BoardService::class), PermissionService::class => $permissions, StackMapper::class => $stacks, 'OCA\\Deck\\Db\\AclMapper' => $aclMapper];
		$container = $mock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $services[$id] ?? throw new \LogicException('Unexpected Deck service ' . $id));
		$session = $mock(ISession::class);
		$session->method('get')->willReturn('alice');
		$users = $mock(IUserManager::class);
		$users->method('get')->willReturnCallback(function (string $uid) use ($mock): ?IUser {
			$user = $mock(IUser::class);
			$user->method('getDisplayName')->willReturn(ucfirst($uid));
			return $user;
		});
		$groups = $mock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(function (string $gid) use ($mock): ?IGroup {
			$group = $mock(IGroup::class);
			$group->method('getDisplayName')->willReturn('Group ' . $gid);
			return $group;
		});
		return new DeckBoardAudience($container, $session, $users, $groups, $mock(IAppManager::class));
	}
}
