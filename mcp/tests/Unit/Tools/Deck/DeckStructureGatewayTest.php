<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use LogicException;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\Stack;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\PermissionService;
use OCA\Deck\Service\StackService;
use OCA\Mcp\Tools\Deck\DeckRefusalException;
use OCA\Mcp\Tools\Deck\DeckServiceGateway;
use OCA\Mcp\Tools\Deck\DeckSessionException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ISession;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

require_once __DIR__ . '/Stubs/deck_stubs.php';

/**
 * The gateway side of building a Deck from scratch: creating boards and lists, counting cards and the two
 * deletions that are only allowed on an empty target.
 *
 * Deck v1.17.5 deletes a list or a board without looking at its cards (it only stamps `deleted_at`), so the
 * emptiness rule lives in the gateway, taken again at the moment of the delete.
 */
final class DeckStructureGatewayTest extends TestCase {
	use DeckTestHelpers;

	/** @var array<string, object&MockObject> Services the container hands out. */
	private array $services = [];

	/** @var list<string> Ordered log of the calls, to prove check-before-write and count-before-delete. */
	private array $calls = [];

	private DeckServiceGateway $gateway;

	protected function setUp(): void {
		$this->services = [
			BoardService::class => $this->createMock(BoardService::class),
			StackService::class => $this->createMock(StackService::class),
			CardService::class => $this->createMock(CardService::class),
			PermissionService::class => $this->createMock(PermissionService::class),
			CardMapper::class => $this->createMock(CardMapper::class),
			StackMapper::class => $this->createMock(StackMapper::class),
			IUserManager::class => $this->createMock(IUserManager::class),
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id): object {
			if (!isset($this->services[$id])) {
				throw new LogicException('Unexpected container lookup: ' . $id);
			}

			return $this->services[$id];
		});
		$this->services[BoardService::class]->method('setUserId')->willReturnCallback(function (string $userId): void {
			$this->calls[] = 'setUserId:' . $userId;
		});
		$this->gateway = new DeckServiceGateway($container, $this->createMock(ITimeFactory::class));
	}

	public function testCreateBoardIsOwnedByTheCallerAndBoundFirst(): void {
		$board = new \OCA\Deck\Db\Board(['id' => 3, 'title' => 'Projeto']);
		$this->services[BoardService::class]->expects(self::once())->method('create')
			->with('Projeto', 'alice', '0082c9')
			->willReturnCallback(function () use ($board): \OCA\Deck\Db\Board {
				$this->calls[] = 'create';

				return $board;
			});

		self::assertSame($board, $this->gateway->createBoard('alice', 'Projeto', '0082c9'));
		self::assertSame(['setUserId:alice', 'create'], $this->calls);
	}

	public function testCreateStackWithAnExplicitOrderWritesWithoutReadingTheBoard(): void {
		$stack = new Stack(['id' => 10, 'title' => 'A fazer']);
		$this->services[StackMapper::class]->expects(self::never())->method('findAll');
		$this->services[StackService::class]->expects(self::once())->method('create')
			->with('A fazer', 4, 2)->willReturn($stack);

		self::assertSame($stack, $this->gateway->createStack('alice', 4, 'A fazer', 2));
	}

	/** Without an order the list goes after the last one, and the board is only read after the manage check. */
	public function testCreateStackWithoutOrderGoesAfterTheLastList(): void {
		$stack = new Stack(['id' => 12]);
		$this->services[PermissionService::class]->expects(self::once())->method('checkPermission')
			->with(null, 4, Acl::PERMISSION_MANAGE)
			->willReturnCallback(function (): bool {
				$this->calls[] = 'checkPermission';

				return true;
			});
		$this->services[StackMapper::class]->expects(self::once())->method('findAll')->with(4)
			->willReturnCallback(function (): array {
				$this->calls[] = 'findAll';

				return [new Stack(['id' => 1, 'order' => 0]), new Stack(['id' => 2, 'order' => 5])];
			});
		$this->services[StackService::class]->expects(self::once())->method('create')->with('Feito', 4, 6)->willReturn($stack);

		self::assertSame($stack, $this->gateway->createStack('alice', 4, 'Feito', null));
		self::assertSame(['checkPermission', 'findAll'], array_values(array_filter($this->calls, static fn (string $c): bool => !str_starts_with($c, 'setUserId'))));
	}

	public function testCreateStackOnABoardWithoutListsStartsAtZero(): void {
		$this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
		$this->services[StackMapper::class]->method('findAll')->willReturn([]);
		$this->services[StackService::class]->expects(self::once())->method('create')->with('Primeira', 4, 0)->willReturn(new Stack());

		$this->gateway->createStack('alice', 4, 'Primeira', null);
	}

	public function testBoardOwnershipChecksManageBeforeReadingTheOwner(): void {
		$this->services[PermissionService::class]->expects(self::once())->method('checkPermission')
			->with(null, 4, Acl::PERMISSION_MANAGE)
			->willReturnCallback(function (): bool {
				$this->calls[] = 'checkPermission';

				return true;
			});
		$this->services[BoardService::class]->expects(self::once())->method('find')->with(4, false, true)
			->willReturnCallback(function (): \OCA\Deck\Db\Board {
				$this->calls[] = 'find';

				return new \OCA\Deck\Db\Board(['id' => 4, 'title' => 'Comercial', 'owner' => 'pedro']);
			});
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getDisplayName')->willReturn('Pedro Almeida');
		$this->services[IUserManager::class]->method('get')->with('pedro')->willReturn($user);

		self::assertSame(
			['owner' => 'pedro', 'ownerDisplayName' => 'Pedro Almeida', 'name' => 'Comercial'],
			$this->gateway->boardOwnership('alice', 4),
		);
		self::assertSame(['checkPermission', 'find'], array_values(array_filter($this->calls, static fn (string $c): bool => !str_starts_with($c, 'setUserId'))));
	}

	public function testBoardOwnershipIsRefusedBeforeAnythingIsReadWithoutManageAccess(): void {
		$this->services[PermissionService::class]->method('checkPermission')->willThrowException(new NoPermissionException('Permission denied'));
		$this->services[BoardService::class]->expects(self::never())->method('find');

		$this->expectException(NoPermissionException::class);
		$this->gateway->boardOwnership('alice', 4);
	}

	/** Active and archived cards both count; the trash does not. */
	public function testAStackCountsActiveAndArchivedCards(): void {
		$this->services[PermissionService::class]->expects(self::once())->method('checkPermission')
			->with(self::isInstanceOf(StackMapper::class), 10, Acl::PERMISSION_MANAGE)->willReturn(true);
		$this->services[CardMapper::class]->method('findAll')->with(10)->willReturn([$this->card(['id' => 1]), $this->card(['id' => 2])]);
		$this->services[CardMapper::class]->method('findAllArchived')->with(10)->willReturn([$this->card(['id' => 3, 'archived' => true])]);

		self::assertSame(3, $this->gateway->stackCardCount('alice', 10));
	}

	public function testAStackWithoutCardsCountsZero(): void {
		$this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
		$this->services[CardMapper::class]->method('findAll')->willReturn([]);
		$this->services[CardMapper::class]->method('findAllArchived')->willReturn([]);

		self::assertSame(0, $this->gateway->stackCardCount('alice', 10));
	}

	public function testTheCountIsRefusedBeforeReadingCardsWithoutManageAccess(): void {
		$this->services[PermissionService::class]->method('checkPermission')->willThrowException(new NoPermissionException('Permission denied'));
		$this->services[CardMapper::class]->expects(self::never())->method('findAll');
		$this->services[CardMapper::class]->expects(self::never())->method('findAllArchived');

		$this->expectException(NoPermissionException::class);
		$this->gateway->stackCardCount('alice', 10);
	}

	public function testABoardCountsTheCardsOfEveryList(): void {
		$this->services[PermissionService::class]->expects(self::once())->method('checkPermission')
			->with(null, 4, Acl::PERMISSION_MANAGE)->willReturn(true);
		$this->services[StackMapper::class]->method('findAll')->with(4)->willReturn([new Stack(['id' => 10]), new Stack(['id' => 11])]);
		$this->services[CardMapper::class]->method('findAll')->willReturnCallback(
			fn ($stackId): array => $stackId === 10 ? [$this->card(['id' => 1])] : [],
		);
		$this->services[CardMapper::class]->method('findAllArchived')->willReturnCallback(
			fn ($stackId): array => $stackId === 11 ? [$this->card(['id' => 2]), $this->card(['id' => 3])] : [],
		);

		self::assertSame(3, $this->gateway->boardCardCount('alice', 4));
	}

	public function testDeleteEmptyStackCountsAgainRightBeforeAndAfterDeleting(): void {
		$deleted = new Stack(['id' => 10]);
		$this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
		$this->services[CardMapper::class]->method('findAll')->willReturnCallback(function (): array {
			$this->calls[] = 'count';

			return [];
		});
		$this->services[CardMapper::class]->method('findAllArchived')->willReturn([]);
		$this->services[StackService::class]->expects(self::once())->method('delete')->with(10)
			->willReturnCallback(function () use ($deleted): Stack {
				$this->calls[] = 'delete';

				return $deleted;
			});

		self::assertSame($deleted, $this->gateway->deleteEmptyStack('alice', 10));
		// Counted before the delete and once more after it, to catch a card created in between.
		self::assertSame(['count', 'delete', 'count'], array_values(array_filter($this->calls, static fn (string $c): bool => !str_starts_with($c, 'setUserId'))));
	}

	/** The card appeared between the plan and the confirmation: nothing is deleted and the refusal says how many. */
	public function testDeleteEmptyStackRefusesWhenACardAppearedAndNeverDeletes(): void {
		$this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
		$this->services[CardMapper::class]->method('findAll')->willReturn([$this->card(['id' => 1])]);
		$this->services[CardMapper::class]->method('findAllArchived')->willReturn([$this->card(['id' => 2, 'archived' => true])]);
		$this->services[StackService::class]->expects(self::never())->method('delete');

		try {
			$this->gateway->deleteEmptyStack('alice', 10);
			self::fail('deleted a list that holds cards');
		} catch (DeckRefusalException $e) {
			self::assertSame('The list has 2 cards; move or delete them first.', $e->getMessage());
		}
	}

	/**
	 * A card created between the count and the delete: the list is recounted after the delete, restored through
	 * the Deck update (no undo exists for a list) and the call is refused, so the card is not left in the trash.
	 */
	public function testDeleteEmptyStackRestoresTheListWhenACardArrivedDuringTheDelete(): void {
		$deleted = false;
		$this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
		$this->services[CardMapper::class]->method('findAll')->willReturnCallback(
			function () use (&$deleted): array {
				return $deleted ? [$this->card(['id' => 7])] : [];
			},
		);
		$this->services[CardMapper::class]->method('findAllArchived')->willReturn([]);
		$this->services[StackService::class]->expects(self::once())->method('delete')->with(10)
			->willReturnCallback(function () use (&$deleted): Stack {
				$deleted = true;
				$this->calls[] = 'delete';

				return new Stack(['id' => 10, 'boardId' => 4, 'title' => 'A fazer', 'order' => 2]);
			});
		$this->services[StackService::class]->expects(self::once())->method('update')->with(10, 'A fazer', 4, 2, 0)
			->willReturnCallback(function (): Stack {
				$this->calls[] = 'restore';

				return new Stack(['id' => 10]);
			});

		try {
			$this->gateway->deleteEmptyStack('alice', 10);
			self::fail('left a list that received a card in the trash');
		} catch (DeckRefusalException $e) {
			self::assertSame('The list received cards while it was being deleted; nothing was deleted.', $e->getMessage());
		}
		self::assertSame(['delete', 'restore'], array_values(array_filter($this->calls, static fn (string $c): bool => !str_starts_with($c, 'setUserId'))));
	}

	/** A restore that fails is not hidden behind "nothing was deleted": the person is told where to recover the list. */
	public function testDeleteEmptyStackSaysSoWhenTheRestoreFails(): void {
		$deleted = false;
		$this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
		$this->services[CardMapper::class]->method('findAll')->willReturnCallback(
			function () use (&$deleted): array {
				return $deleted ? [$this->card(['id' => 7])] : [];
			},
		);
		$this->services[CardMapper::class]->method('findAllArchived')->willReturn([]);
		$this->services[StackService::class]->method('delete')->willReturnCallback(function () use (&$deleted): Stack {
			$deleted = true;

			return new Stack(['id' => 10, 'boardId' => 4, 'title' => 'A fazer', 'order' => 2]);
		});
		$this->services[StackService::class]->method('update')->willThrowException(new \RuntimeException('secret detail'));

		try {
			$this->gateway->deleteEmptyStack('alice', 10);
			self::fail('answered success for a list that is in the trash with a card');
		} catch (DeckRefusalException $e) {
			self::assertSame('The list received cards while it was being deleted and could not be restored; recover it from the Deck trash.', $e->getMessage());
			self::assertStringNotContainsString('secret', $e->getMessage());
		}
	}

	public function testDeleteEmptyStackWithoutNewCardsNeverRestores(): void {
		$deleted = new Stack(['id' => 10, 'boardId' => 4]);
		$this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
		$this->services[CardMapper::class]->method('findAll')->willReturn([]);
		$this->services[CardMapper::class]->method('findAllArchived')->willReturn([]);
		$this->services[StackService::class]->method('delete')->willReturn($deleted);
		$this->services[StackService::class]->expects(self::never())->method('update');

		self::assertSame($deleted, $this->gateway->deleteEmptyStack('alice', 10));
	}

	/** A card created between the count and the delete: the board is recounted, brought back with Deck's own undo and refused. */
	public function testDeleteEmptyBoardUndoesTheDeleteWhenACardArrivedDuringIt(): void {
		$deleted = false;
		$this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
		$this->services[BoardService::class]->method('find')->willReturn(new \OCA\Deck\Db\Board(['id' => 4, 'title' => 'Meu', 'owner' => 'alice']));
		$this->services[StackMapper::class]->method('findAll')->willReturn([new Stack(['id' => 10])]);
		$this->services[CardMapper::class]->method('findAll')->willReturnCallback(
			function () use (&$deleted): array {
				return $deleted ? [$this->card(['id' => 7])] : [];
			},
		);
		$this->services[CardMapper::class]->method('findAllArchived')->willReturn([]);
		$this->services[BoardService::class]->expects(self::once())->method('delete')->with(4)
			->willReturnCallback(function () use (&$deleted): \OCA\Deck\Db\Board {
				$deleted = true;
				$this->calls[] = 'delete';

				return new \OCA\Deck\Db\Board(['id' => 4]);
			});
		$this->services[BoardService::class]->expects(self::once())->method('deleteUndo')->with(4)
			->willReturnCallback(function (): \OCA\Deck\Db\Board {
				$this->calls[] = 'undo';

				return new \OCA\Deck\Db\Board(['id' => 4]);
			});

		try {
			$this->gateway->deleteEmptyBoard('alice', 4);
			self::fail('left a board that received a card in the trash');
		} catch (DeckRefusalException $e) {
			self::assertSame('The board received cards while it was being deleted; nothing was deleted.', $e->getMessage());
		}
		self::assertSame(['delete', 'undo'], array_values(array_filter($this->calls, static fn (string $c): bool => !str_starts_with($c, 'setUserId'))));
	}

	public function testDeleteEmptyBoardSaysSoWhenTheUndoFails(): void {
		$deleted = false;
		$this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
		$this->services[BoardService::class]->method('find')->willReturn(new \OCA\Deck\Db\Board(['id' => 4, 'title' => 'Meu', 'owner' => 'alice']));
		$this->services[StackMapper::class]->method('findAll')->willReturn([new Stack(['id' => 10])]);
		$this->services[CardMapper::class]->method('findAll')->willReturnCallback(
			function () use (&$deleted): array {
				return $deleted ? [$this->card(['id' => 7])] : [];
			},
		);
		$this->services[CardMapper::class]->method('findAllArchived')->willReturn([]);
		$this->services[BoardService::class]->method('delete')->willReturnCallback(function () use (&$deleted): \OCA\Deck\Db\Board {
			$deleted = true;

			return new \OCA\Deck\Db\Board(['id' => 4]);
		});
		$this->services[BoardService::class]->method('deleteUndo')->willThrowException(new \RuntimeException('secret detail'));

		try {
			$this->gateway->deleteEmptyBoard('alice', 4);
			self::fail('answered success for a board that is in the trash with a card');
		} catch (DeckRefusalException $e) {
			self::assertSame('The board received cards while it was being deleted and could not be restored; recover it from the Deck trash.', $e->getMessage());
			self::assertStringNotContainsString('secret', $e->getMessage());
		}
	}

	public function testDeleteEmptyBoardIsOnlyForTheOwner(): void {
		$this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
		$this->services[BoardService::class]->method('find')->willReturn(new \OCA\Deck\Db\Board(['id' => 4, 'title' => 'Comercial', 'owner' => 'pedro']));
		$this->services[StackMapper::class]->expects(self::never())->method('findAll');
		$this->services[BoardService::class]->expects(self::never())->method('delete');

		try {
			$this->gateway->deleteEmptyBoard('alice', 4);
			self::fail('deleted a board of somebody else');
		} catch (DeckRefusalException $e) {
			self::assertSame('Only the owner of a board can delete it.', $e->getMessage());
		}
	}

	public function testDeleteEmptyBoardRefusesWhileAnyListHoldsACard(): void {
		$this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
		$this->services[BoardService::class]->method('find')->willReturn(new \OCA\Deck\Db\Board(['id' => 4, 'title' => 'Meu', 'owner' => 'alice']));
		$this->services[StackMapper::class]->method('findAll')->willReturn([new Stack(['id' => 10]), new Stack(['id' => 11])]);
		$this->services[CardMapper::class]->method('findAll')->willReturn([]);
		$this->services[CardMapper::class]->method('findAllArchived')->willReturnCallback(
			fn ($stackId): array => $stackId === 11 ? [$this->card(['id' => 9, 'archived' => true])] : [],
		);
		$this->services[BoardService::class]->expects(self::never())->method('delete');

		try {
			$this->gateway->deleteEmptyBoard('alice', 4);
			self::fail('deleted a board that holds a card');
		} catch (DeckRefusalException $e) {
			self::assertSame('The board has 1 card; move or delete it first.', $e->getMessage());
		}
	}

	public function testDeleteEmptyBoardDeletesTheOwnersEmptyBoard(): void {
		$deleted = new \OCA\Deck\Db\Board(['id' => 4]);
		$this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
		$this->services[BoardService::class]->method('find')->willReturn(new \OCA\Deck\Db\Board(['id' => 4, 'title' => 'Meu', 'owner' => 'alice']));
		$this->services[StackMapper::class]->method('findAll')->willReturn([new Stack(['id' => 10])]);
		$this->services[CardMapper::class]->method('findAll')->willReturn([]);
		$this->services[CardMapper::class]->method('findAllArchived')->willReturn([]);
		$this->services[BoardService::class]->expects(self::once())->method('delete')->with(4)->willReturn($deleted);
		$this->services[BoardService::class]->expects(self::never())->method('deleteUndo');

		self::assertSame($deleted, $this->gateway->deleteEmptyBoard('alice', 4));
	}

	/**
	 * Regression of 0.9.1 for the new writes: with the caller missing from the session Deck would write and then
	 * fail, so nothing of the structure may reach the Deck.
	 */
	public function testNoStructureWriteReachesDeckWithoutTheCallerInTheSession(): void {
		$session = $this->createMock(ISession::class);
		$session->method('get')->willReturn(null);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::never())->method('get');
		$gateway = new DeckServiceGateway($container, $this->createMock(ITimeFactory::class), null, $session);

		foreach ([
			'createBoard' => fn () => $gateway->createBoard('alice', 'X', '0082c9'),
			'createStack' => fn () => $gateway->createStack('alice', 4, 'X', 0),
			'deleteEmptyStack' => fn () => $gateway->deleteEmptyStack('alice', 10),
			'deleteEmptyBoard' => fn () => $gateway->deleteEmptyBoard('alice', 4),
		] as $name => $call) {
			try {
				$call();
				self::fail($name . ' reached the Deck without the caller in the session');
			} catch (DeckSessionException) {
			}
		}
	}

	public function testCreateCardTakesAnExplicitPositionWhenGiven(): void {
		$card = $this->card();
		$this->services[CardService::class]->expects(self::once())->method('create')
			->with('Item', 10, 'note', 3, 'alice', '', null)->willReturn($card);

		self::assertSame($card, $this->gateway->createCard('alice', 10, 'Item', '', null, 3));
	}
}
