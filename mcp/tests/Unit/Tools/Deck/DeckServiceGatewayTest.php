<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use LogicException;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Assignment;
use OCA\Deck\Db\AssignmentMapper;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\Stack;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Model\OptionalNullableValue;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\PermissionService;
use OCA\Deck\Service\StackService;
use OCA\Mcp\Service\UserTimezone;
use OCA\Mcp\Tools\Deck\CardCriteria;
use OCA\Mcp\Tools\Deck\DeckServiceGateway;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

require_once __DIR__ . '/Stubs/deck_stubs.php';

/**
 * Covers the adapter over the Deck services: user binding, the ACL checks Deck does not do on its
 * own, and the argument order of every Deck v1.17.5 call.
 */
final class DeckServiceGatewayTest extends TestCase {
	use DeckTestHelpers;

	/** @var array<string, object> Services the container hands out. */
	private array $services = [];

	/** @var list<string> Ordered log of the calls the gateway made, to prove check-before-query. */
	private array $calls = [];

	/** @var ContainerInterface&MockObject */
	private ContainerInterface $container;

	/** @var ITimeFactory&MockObject Clock the follow-up reads `overdue` from. */
	private ITimeFactory $time;

	private DeckServiceGateway $gateway;

	protected function setUp(): void {
		$this->services = [
			BoardService::class => $this->createMock(BoardService::class),
			StackService::class => $this->createMock(StackService::class),
			CardService::class => $this->createMock(CardService::class),
			PermissionService::class => $this->createMock(PermissionService::class),
			CardMapper::class => $this->createMock(CardMapper::class),
			StackMapper::class => $this->createMock(StackMapper::class),
			AssignmentMapper::class => $this->createMock(AssignmentMapper::class),
			IUserManager::class => $this->createMock(IUserManager::class),
		];

		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn((new \DateTime('2026-03-05 12:00:00'))->getTimestamp());

		$this->container = $this->createMock(ContainerInterface::class);
		$this->container
			->method('get')
			->willReturnCallback(function (string $id): object {
				$this->calls[] = 'get:' . $id;
				if (!isset($this->services[$id])) {
					throw new LogicException('Unexpected container lookup: ' . $id);
				}

				return $this->services[$id];
			});

		$this->gateway = new DeckServiceGateway($this->container, $this->time);
	}

	public function testListBoardsBindsTheCallerAndHidesArchivedBoards(): void {
		$boards = [$this->boardDouble(1)];
		$this->recordSetUserId();
		$this->services[BoardService::class]
			->expects(self::once())
			->method('findAll')
			->with(-1, false, false)
			->willReturn($boards);

		self::assertSame($boards, $this->gateway->listBoards('alice'));
	}

	public function testListStacksGoesThroughTheDeckServiceThatChecksTheBoard(): void {
		$stacks = [];
		$this->recordSetUserId();
		$this->services[StackService::class]
			->expects(self::once())
			->method('findAll')
			->with(4)
			->willReturn($stacks);

		self::assertSame($stacks, $this->gateway->listStacks('alice', 4));
	}

	public function testListCardsChecksReadPermissionBeforeQueryingTheMapper(): void {
		$this->recordSetUserId();
		$this->services[PermissionService::class]
			->expects(self::once())
			->method('checkPermission')
			->with(self::isInstanceOf(StackMapper::class), 10, Acl::PERMISSION_READ)
			->willReturnCallback(function (): bool {
				$this->calls[] = 'checkPermission';

				return true;
			});
		$this->services[CardMapper::class]
			->expects(self::once())
			->method('findAll')
			->with(10, 25, 50)
			->willReturnCallback(function (): array {
				$this->calls[] = 'findAll';

				return [$this->card(['id' => 1])];
			});
		$this->services[StackMapper::class]
			->method('findBoardId')
			->willReturn(4);

		$page = $this->gateway->listCards('alice', 10, 25, 50);

		self::assertSame(4, $page['boardId']);
		self::assertCount(1, $page['items']);
		self::assertSame(['checkPermission', 'findAll'], $this->record('checkPermission', 'findAll'));
	}

	public function testListCardsStopsWhenTheStackIsNotReadable(): void {
		$this->recordSetUserId();
		$this->services[PermissionService::class]
			->method('checkPermission')
			->willThrowException(new NoPermissionException('Permission denied'));
		$this->services[CardMapper::class]
			->expects(self::never())
			->method('findAll');

		$this->expectException(NoPermissionException::class);

		$this->gateway->listCards('alice', 10, 25, 0);
	}

	public function testListCardsNormalisesAnEmptyMapperResult(): void {
		$this->recordSetUserId();
		$this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
		$this->services[CardMapper::class]->method('findAll')->willReturn([]);
		$this->services[StackMapper::class]->method('findBoardId')->willReturn(4);

		self::assertSame(['items' => [], 'boardId' => 4], $this->gateway->listCards('alice', 10, 25, 0));
	}

	public function testFindCardGoesThroughTheDeckService(): void {
		$card = $this->card();
		$this->recordSetUserId();
		$this->services[CardService::class]
			->expects(self::once())
			->method('find')
			->with(7)
			->willReturn($card);

		self::assertSame($card, $this->gateway->findCard('alice', 7));
	}

	public function testCreateCardIsANoteOwnedByTheCallerAtTheEndOfTheStack(): void {
		$card = $this->card();
		$this->recordSetUserId();
		$this->services[CardService::class]
			->expects(self::once())
			->method('create')
			->with('Fechar', 10, 'note', 99999, 'alice', 'Com o cliente', CardCriteria::localMidnight('2026-03-01', new \DateTimeZone(date_default_timezone_get())))
			->willReturn($card);

		self::assertSame($card, $this->gateway->createCard('alice', 10, 'Fechar', 'Com o cliente', '2026-03-01'));
	}

	public function testDayWrittenByTheToolsIsMidnightInTheCallerTimezone(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn('America/Sao_Paulo');
		$gateway = new DeckServiceGateway($this->container, $this->time, new UserTimezone($config));
		$this->recordSetUserId();
		$this->services[CardService::class]
			->expects(self::once())
			->method('create')
			->with('Fechar', 10, 'note', 99999, 'alice', '', '2026-10-01T00:00:00-03:00')
			->willReturn($this->card());

		$gateway->createCard('alice', 10, 'Fechar', '', '2026-10-01');
	}

	public function testUpdateKeepsAFullTimestampUntouched(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn('America/Sao_Paulo');
		$gateway = new DeckServiceGateway($this->container, $this->time, new UserTimezone($config));
		$card = $this->card(['id' => 7, 'duedate' => new \DateTime('2026-10-01 18:30:00', new \DateTimeZone('UTC'))]);
		$this->recordSetUserId();
		$this->services[CardService::class]
			->expects(self::once())
			->method('update')
			->with(7, 'Card', 10, 'note', 'alice', '', 0, '2026-10-01T18:30:00+00:00')
			->willReturn($card);

		$gateway->updateCard('alice', $card, 'Card', '', '2026-10-01T18:30:00+00:00');
	}

	public function testUpdateCardPreservesEveryFieldTheCallerDidNotChange(): void {
		$card = $this->card([
			'id' => 7,
			'stackId' => 10,
			'type' => 'note',
			'owner' => 'alice',
			'order' => 3,
			'duedate' => new \DateTime('2026-01-05 00:00:00'),
			'done' => new \DateTime('2026-01-02 10:00:00'),
			'archived' => true,
		]);
		$this->recordSetUserId();
		$this->services[CardService::class]
			->expects(self::once())
			->method('update')
			->with(
				7,
				'Fechar',
				10,
				'note',
				'alice',
				'Novo texto',
				3,
				null,
				null,
				null,
				self::callback(static function (mixed $done): bool {
					// Deck v1.17.5 nulls the column when this argument is left out, so it is sent back wrapped.
					return $done instanceof OptionalNullableValue
						&& $done->getValue()?->format('Y-m-d H:i:s') === '2026-01-02 10:00:00';
				}),
			)
			->willReturn($card);

		self::assertSame($card, $this->gateway->updateCard('alice', $card, 'Fechar', 'Novo texto', null));
	}

	public function testMoveCardWithoutOrderChecksTheDestinationStackBeforeReadingIt(): void {
		$this->recordSetUserId();
		$this->services[PermissionService::class]
			->expects(self::once())
			->method('checkPermission')
			->with(self::isInstanceOf(StackMapper::class), 11, Acl::PERMISSION_READ)
			->willReturnCallback(function (): bool {
				$this->calls[] = 'checkPermission';

				return true;
			});
		$this->services[CardMapper::class]
			->expects(self::once())
			->method('findAll')
			->with(11)
			->willReturnCallback(function (): array {
				$this->calls[] = 'findAll';

				return [
					$this->card(['id' => 1, 'order' => 0]),
					$this->card(['id' => 2, 'order' => 5]),
					$this->card(['id' => 3, 'order' => 2]),
				];
			});
		$this->services[CardService::class]
			->expects(self::once())
			->method('reorder')
			->with(7, 11, 6)
			->willReturn([$this->card(['id' => 7, 'stackId' => 11, 'order' => 6])]);

		$moved = $this->gateway->moveCard('alice', 7, 11, null);

		self::assertSame(11, $moved->getStackId());
		self::assertSame(['checkPermission', 'findAll'], $this->record('checkPermission', 'findAll'));
	}

	public function testMoveCardWithExplicitOrderNeverReadsTheDestinationStack(): void {
		$this->recordSetUserId();
		$this->services[PermissionService::class]
			->expects(self::never())
			->method('checkPermission');
		$this->services[CardMapper::class]
			->expects(self::never())
			->method('findAll');
		$this->services[CardService::class]
			->expects(self::once())
			->method('reorder')
			->with(7, 11, 2)
			->willReturn([$this->card(['id' => 7, 'stackId' => 11])]);

		self::assertSame(11, $this->gateway->moveCard('alice', 7, 11, 2)->getStackId());
	}

	public function testMoveCardFallsBackToReadingTheCardWhenReorderDoesNotReturnIt(): void {
		$this->recordSetUserId();
		$this->services[CardService::class]
			->method('reorder')
			->willReturn([$this->card(['id' => 99, 'stackId' => 11])]);
		$this->services[CardService::class]
			->expects(self::once())
			->method('find')
			->with(7)
			->willReturn($this->card(['id' => 7, 'stackId' => 11]));

		self::assertSame(7, $this->gateway->moveCard('alice', 7, 11, 2)->getId());
	}

	public function testDeleteCardUsesTheDeckSoftDelete(): void {
		$card = $this->card(['deletedAt' => 1_700_000_900]);
		$this->recordSetUserId();
		$this->services[CardService::class]
			->expects(self::once())
			->method('delete')
			->with(7)
			->willReturn($card);

		self::assertSame($card, $this->gateway->deleteCard('alice', 7));
	}

	public function testUserIsBoundOnEveryCall(): void {
		$this->recordSetUserId();
		$this->services[CardService::class]->method('find')->willReturn($this->card());

		$this->gateway->findCard('bob', 7);
		$this->gateway->findCard('carol', 7);

		self::assertSame(
			['setUserId:bob', 'setUserId:carol'],
			$this->record('setUserId'),
		);
	}

	public function testUnresolvedDeckServiceSurfacesAsAnExceptionForTheCallerToMap(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willThrowException(new RuntimeException('Service not found'));
		$gateway = new DeckServiceGateway($this->container, $this->time);

		$this->expectException(RuntimeException::class);

		$gateway->findCard('alice', 7);
	}

	public function testDeckServicesAreResolvedOncePerRequest(): void {
		$this->recordSetUserId();
		$this->services[CardService::class]->method('find')->willReturn($this->card());

		$this->gateway->findCard('alice', 7);
		$this->gateway->findCard('alice', 8);

		self::assertCount(1, $this->record('get:' . CardService::class));
	}

	public function testStackOwnershipChecksEditAccessBeforeReadingTheBoard(): void {
		$this->recordSetUserId();
		$this->recordUser('pedro', 'Pedro Almeida');
		$this->services[PermissionService::class]
			->expects(self::once())
			->method('checkPermission')
			->with(self::isInstanceOf(StackMapper::class), 10, Acl::PERMISSION_EDIT)
			->willReturnCallback(function (): bool {
				$this->calls[] = 'checkPermission';

				return true;
			});
		$this->services[StackMapper::class]
			->expects(self::once())
			->method('findBoardId')
			->with(10)
			->willReturnCallback(function (): int {
				$this->calls[] = 'findBoardId';

				return 4;
			});
		$this->services[BoardService::class]
			->expects(self::once())
			->method('find')
			->with(4, false, true)
			->willReturn($this->boardDouble(4, 'Comercial', 'pedro'));

		self::assertSame(
			['owner' => 'pedro', 'ownerDisplayName' => 'Pedro Almeida', 'name' => 'Comercial'],
			$this->gateway->stackOwnership('alice', 10),
		);
		self::assertSame(['checkPermission', 'findBoardId'], $this->record('checkPermission', 'findBoardId'));
	}

	public function testCardOwnershipRunsTheSameCheckTheWriteRuns(): void {
		$this->recordSetUserId();
		$this->recordUser('pedro', 'Pedro Almeida');
		$this->services[PermissionService::class]
			->expects(self::once())
			->method('checkPermission')
			->with(self::isInstanceOf(CardMapper::class), 7, Acl::PERMISSION_EDIT)
			->willReturn(true);
		$this->services[CardMapper::class]
			->expects(self::once())
			->method('findBoardId')
			->with(7)
			->willReturn(4);
		$this->services[BoardService::class]
			->expects(self::once())
			->method('find')
			->with(4, false, true)
			->willReturn($this->boardDouble(4, 'Comercial', 'pedro'));

		self::assertSame(
			['owner' => 'pedro', 'ownerDisplayName' => 'Pedro Almeida', 'name' => 'Comercial'],
			$this->gateway->cardOwnership('alice', 7),
		);
	}

	public function testOwnershipIsRefusedBeforeAnyOwnerIsReadWhenAccessIsDenied(): void {
		$this->recordSetUserId();
		$this->services[PermissionService::class]
			->method('checkPermission')
			->willThrowException(new NoPermissionException('Permission denied'));
		$this->services[StackMapper::class]->expects(self::never())->method('findBoardId');
		$this->services[BoardService::class]->expects(self::never())->method('find');

		$this->expectException(NoPermissionException::class);

		$this->gateway->stackOwnership('alice', 10);
	}

	public function testOwnershipShowsTheUidWhenTheOwnerAccountIsGone(): void {
		$this->recordSetUserId();
		$this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
		$this->services[StackMapper::class]->method('findBoardId')->willReturn(4);
		$this->services[BoardService::class]
			->method('find')
			->willReturn($this->boardDouble(4, 'Comercial', 'ex-usuario'));
		$this->services[IUserManager::class]->method('get')->with('ex-usuario')->willReturn(null);

		self::assertSame(
			['owner' => 'ex-usuario', 'ownerDisplayName' => 'ex-usuario', 'name' => 'Comercial'],
			$this->gateway->stackOwnership('alice', 10),
		);
	}

	public function testFollowupScansOnlyTheBoardsTheCallerMayManage(): void {
		$this->recordSetUserId();
		$this->services[BoardService::class]
			->method('findAll')
			->willReturn([$this->boardDouble(1), $this->boardDouble(2)]);
		$checked = [];
		$this->services[PermissionService::class]
			->expects(self::exactly(2))
			->method('getPermissions')
			->willReturnCallback(function (int $boardId, string $userId) use (&$checked): array {
				$checked[] = $boardId . ':' . $userId;

				return $boardId === 1
					? [Acl::PERMISSION_MANAGE => true]
					: [Acl::PERMISSION_READ => true, Acl::PERMISSION_MANAGE => false];
			});
		$this->services[StackMapper::class]
			->expects(self::once())
			->method('findAll')
			->with(1)
			->willReturn([new Stack(['id' => 10, 'boardId' => 1])]);
		$this->services[CardMapper::class]
			->method('findAllForStacks')
			->willReturn([10 => [$this->card(['id' => 11, 'stackId' => 10, 'duedate' => new \DateTime('2026-03-01 00:00:00')])]]);

		$page = $this->gateway->followupCards('alice', 'overdue', null, null, null, 10);

		self::assertSame(['1:alice', '2:alice'], $checked);
		self::assertSame([11], array_map(static fn (array $item): int => $item['card']->getId(), $page['items']));
		self::assertSame([1], array_map(static fn (array $item): int => $item['boardId'], $page['items']));
		self::assertFalse($page['truncated']);
		self::assertSame(['setUserId:alice'], $this->record('setUserId'));
	}

	public function testFollowupClassifiesCardsWithTheInjectedClock(): void {
		$late = $this->card(['id' => 1, 'stackId' => 10, 'duedate' => new \DateTime('2026-03-01 00:00:00')]);
		$today = $this->card(['id' => 2, 'stackId' => 10, 'duedate' => new \DateTime('2026-03-05 00:00:00')]);
		$future = $this->card(['id' => 3, 'stackId' => 10, 'duedate' => new \DateTime('2026-03-06 00:00:00')]);
		$undated = $this->card(['id' => 4, 'stackId' => 10]);
		$done = $this->card(['id' => 5, 'stackId' => 10, 'duedate' => new \DateTime('2026-03-01 00:00:00'), 'done' => new \DateTime('2026-03-02 09:00:00')]);
		$archived = $this->card(['id' => 6, 'stackId' => 10, 'duedate' => new \DateTime('2026-03-01 00:00:00'), 'archived' => true]);
		$this->managedBoardWith([$late, $today, $future, $undated, $done, $archived]);

		$ids = fn (string $status): array => array_map(
			static fn (array $item): int => $item['card']->getId(),
			$this->gateway->followupCards('alice', $status, null, null, null, 100)['items'],
		);

		// The clock of the test is 2026-03-05 noon, so "today" is not late yet.
		self::assertSame([1], $ids('overdue'));
		self::assertSame([1, 2, 3, 4], $ids('open'));
		self::assertSame([5], $ids('done'));
		self::assertSame([1, 2, 3, 4, 5, 6], $ids('all'));
	}

	public function testFollowupKeepsOnlyTheCardsOfTheAskedAssignee(): void {
		// Deck stores the assignments apart from the card, so the gateway reads them per board.
		$this->services[AssignmentMapper::class]
			->method('findIn')
			->willReturn([
				new Assignment(['cardId' => 1, 'participant' => 'pedro', 'type' => Acl::PERMISSION_TYPE_USER]),
				new Assignment(['cardId' => 2, 'participant' => 'vendas', 'type' => Acl::PERMISSION_TYPE_GROUP]),
			]);
		$this->managedBoardWith([
			$this->card(['id' => 1, 'stackId' => 10, 'duedate' => new \DateTime('2026-03-01 00:00:00')]),
			$this->card(['id' => 2, 'stackId' => 10, 'duedate' => new \DateTime('2026-03-01 00:00:00')]),
		]);

		$ids = fn (?string $assignee): array => array_map(
			static fn (array $item): int => $item['card']->getId(),
			$this->gateway->followupCards('alice', 'overdue', null, $assignee, null, 100)['items'],
		);

		self::assertSame([1], $ids('pedro'));
		// A group names no person, so a UID filter never answers a card assigned only to one.
		self::assertSame([], $ids('alice'));
		self::assertSame([1, 2], $ids(null));
	}

	public function testFollowupAppliesTheDueDateCeilingOnlyToCardsThatHaveADate(): void {
		$this->managedBoardWith([
			$this->card(['id' => 1, 'stackId' => 10, 'duedate' => new \DateTime('2026-03-01 00:00:00')]),
			$this->card(['id' => 2, 'stackId' => 10, 'duedate' => new \DateTime('2026-03-10 00:00:00')]),
			$this->card(['id' => 3, 'stackId' => 10]),
		]);

		$page = $this->gateway->followupCards('alice', 'all', null, null, '2026-03-05', 100);

		// Cards without a due date cannot be "until a date", so they stay out of the ceiling.
		self::assertSame([1], array_map(static fn (array $item): int => $item['card']->getId(), $page['items']));
	}

	public function testFollowupStopsAtTheLimitAndReportsTheTruncation(): void {
		$cards = array_map(
			fn (int $id): Card => $this->card(['id' => $id, 'stackId' => 10, 'duedate' => new \DateTime('2026-03-01 00:00:00')]),
			range(1, 12),
		);
		$this->managedBoardWith($cards);

		$page = $this->gateway->followupCards('alice', 'overdue', null, null, null, 5);

		self::assertCount(5, $page['items']);
		self::assertSame([1, 2, 3, 4, 5], array_map(static fn (array $item): int => $item['card']->getId(), $page['items']));
		self::assertTrue($page['truncated']);
	}

	public function testFollowupStopsReadingBoardsOnceTheLimitIsCovered(): void {
		$boards = array_map(fn (int $id): \OCA\Deck\Db\Board => $this->boardDouble($id), [1, 2, 3]);
		$this->services[BoardService::class]->method('findAll')->willReturn($boards);
		$this->services[PermissionService::class]
			->method('getPermissions')
			->willReturn([Acl::PERMISSION_MANAGE => true]);
		$this->services[StackMapper::class]
			->expects(self::exactly(2))
			->method('findAll')
			->willReturnCallback(static fn (int $boardId): array => [new Stack(['id' => $boardId * 10, 'boardId' => $boardId])]);
		$this->services[CardMapper::class]
			->method('findAllForStacks')
			->willReturnCallback(function (array $stackIds): array {
				$cards = [];
				foreach ($stackIds as $stackId) {
					$cards[$stackId] = [$this->card([
						'id' => $stackId + 1,
						'stackId' => $stackId,
						'duedate' => new \DateTime('2026-03-01 00:00:00'),
					])];
				}

				return $cards;
			});

		$page = $this->gateway->followupCards('alice', 'overdue', null, null, null, 1);

		self::assertCount(1, $page['items']);
		self::assertTrue($page['truncated']);
	}

	public function testFollowupNeverExaminesMoreThanOneHundredBoards(): void {
		$this->services[BoardService::class]
			->method('findAll')
			->willReturn(array_map(fn (int $id): \OCA\Deck\Db\Board => $this->boardDouble($id), range(1, 101)));
		$this->services[PermissionService::class]
			->expects(self::exactly(100))
			->method('getPermissions')
			->willReturn([Acl::PERMISSION_MANAGE => false]);
		$this->services[StackMapper::class]->expects(self::never())->method('findAll');

		$page = $this->gateway->followupCards('alice', 'overdue', null, null, null, 100);

		self::assertSame([], $page['items']);
		self::assertTrue($page['truncated']);
	}

	public function testFollowupScansOnlyTheBoardTheCallerAskedFor(): void {
		$this->services[BoardService::class]
			->method('findAll')
			->willReturn([$this->boardDouble(4), $this->boardDouble(9)]);
		$this->services[PermissionService::class]
			->expects(self::once())
			->method('getPermissions')
			->with(9, 'alice')
			->willReturn([Acl::PERMISSION_MANAGE => true]);
		$this->services[StackMapper::class]
			->expects(self::once())
			->method('findAll')
			->with(9)
			->willReturn([new Stack(['id' => 90, 'boardId' => 9])]);
		$this->services[CardMapper::class]
			->method('findAllForStacks')
			->willReturn([90 => [$this->card(['id' => 91, 'stackId' => 90, 'duedate' => new \DateTime('2026-03-01 00:00:00')])]]);

		$page = $this->gateway->followupCards('alice', 'overdue', 9, null, null, 10);

		self::assertSame([9], array_map(static fn (array $item): int => $item['boardId'], $page['items']));
		self::assertSame([91], array_map(static fn (array $item): int => $item['card']->getId(), $page['items']));
	}

	public function testFollowupReadsTheAssignmentsOfAWholeBoardInOneQuery(): void {
		$this->managedBoardWith([
			$this->card(['id' => 1, 'stackId' => 10, 'duedate' => new \DateTime('2026-03-01 00:00:00')]),
			$this->card(['id' => 2, 'stackId' => 10, 'duedate' => new \DateTime('2026-03-01 00:00:00')]),
		]);
		$this->services[AssignmentMapper::class]
			->expects(self::once())
			->method('findIn')
			->with([1, 2])
			->willReturn([new Assignment(['cardId' => 1, 'participant' => 'alice', 'type' => Acl::PERMISSION_TYPE_USER])]);

		$page = $this->gateway->followupCards('alice', 'overdue', null, null, null, 10);

		self::assertSame(['alice'], CardCriteria::assignedUids($page['items'][0]['card']));
		self::assertSame([], CardCriteria::assignedUids($page['items'][1]['card']));
	}

	public function testListCardsEmbedsTheAssignmentsOfTheWholePageInOneQuery(): void {
		$this->recordSetUserId();
		$this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
		$this->services[StackMapper::class]->method('findBoardId')->willReturn(4);
		$first = $this->card(['id' => 1]);
		$second = $this->card(['id' => 2]);
		$this->services[CardMapper::class]->method('findAll')->willReturn([$first, $second]);
		$this->services[AssignmentMapper::class]
			->expects(self::once())
			->method('findIn')
			->with([1, 2])
			->willReturn([new Assignment(['cardId' => 2, 'participant' => 'alice', 'type' => Acl::PERMISSION_TYPE_USER])]);

		$page = $this->gateway->listCards('alice', 10, 25, 0);

		self::assertSame([], $page['items'][0]->getAssignedUsers());
		self::assertSame('alice', $page['items'][1]->getAssignedUsers()[0]->getParticipant());
	}

	/**
	 * Makes every `setUserId()` call record itself in the call log.
	 *
	 * @return void
	 */
	private function recordSetUserId(): void {
		$this->services[BoardService::class]
			->method('setUserId')
			->willReturnCallback(function (string $userId): void {
				$this->calls[] = 'setUserId:' . $userId;
			});
	}

	/**
	 * Answers a follow-up over one board the caller manages, holding one stack with the given cards.
	 *
	 * @param list<Card> $cards Cards of the single stack of board 4.
	 */
	private function managedBoardWith(array $cards): void {
		$this->services[BoardService::class]->method('findAll')->willReturn([$this->boardDouble(4)]);
		$this->services[PermissionService::class]
			->method('getPermissions')
			->with(4, 'alice')
			->willReturn([Acl::PERMISSION_MANAGE => true]);
		$this->services[StackMapper::class]
			->method('findAll')
			->with(4)
			->willReturn([new Stack(['id' => 10, 'boardId' => 4])]);
		$this->services[CardMapper::class]
			->method('findAllForStacks')
			->with([10])
			->willReturn([10 => $cards]);
	}

	/**
	 * Makes the user manager resolve one account for the display-name lookup.
	 *
	 * @param string $uid UID to resolve.
	 * @param string $displayName Name that account reports.
	 * @return void
	 */
	private function recordUser(string $uid, string $displayName): void {
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn($displayName);
		$this->services[IUserManager::class]->method('get')->with($uid)->willReturn($user);
	}

	/**
	 * Keeps only the entries of the call log that start with one of the given prefixes.
	 *
	 * @param string ...$prefixes Prefixes to keep, for example a class name or a method name.
	 * @return list<string> Matching entries, in call order.
	 */
	private function record(string ...$prefixes): array {
		return array_values(array_filter(
			$this->calls,
			static fn (string $call) => array_any($prefixes, static fn (string $prefix) => str_starts_with($call, $prefix)),
		));
	}

	/**
	 * @param int $id Board id.
	 * @param string|null $title Board title, or null for the default one.
	 * @param string $owner UID of the board owner.
	 * @return \OCA\Deck\Db\Board Board double.
	 */
	private function boardDouble(int $id, ?string $title = null, string $owner = ''): \OCA\Deck\Db\Board {
		return new \OCA\Deck\Db\Board([
			'id' => $id,
			'title' => $title ?? 'Board ' . $id,
			'owner' => $owner,
		]);
	}
}
