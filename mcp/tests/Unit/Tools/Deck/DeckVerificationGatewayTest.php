<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use LogicException;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Assignment;
use OCA\Deck\Db\AssignmentMapper;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\BoardMapper;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\Stack;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\PermissionService;
use OCA\Mcp\Tools\Deck\DeckServiceGateway;
use OCA\Mcp\Tools\Deck\DeckSessionException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ISession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

require_once __DIR__ . '/Stubs/deck_stubs.php';

/**
 * The reads a write tool runs after an exception of the write call, to answer with what Deck really holds. Each one
 * reads the database again (no Deck entity cache), checks the access first, and never writes.
 */
final class DeckVerificationGatewayTest extends TestCase {
	use DeckTestHelpers;

	private const NOW = 1_790_000_000;

	/** @var array<string, object&MockObject> Services the container hands out. */
	private array $services = [];

	/** @var list<string> Ordered log of the calls, to prove check-before-read. */
	private array $calls = [];

	private DeckServiceGateway $gateway;

	protected function setUp(): void {
		foreach ([BoardService::class, PermissionService::class, CardMapper::class, StackMapper::class, BoardMapper::class, AssignmentMapper::class] as $class) {
			$this->services[$class] = $this->createMock($class);
		}
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id): object => $this->services[$id] ?? throw new LogicException('Unexpected container lookup: ' . $id));
		$this->services[PermissionService::class]->method('checkPermission')->willReturnCallback(function ($mapper, $id, int $permission, $userId = null, bool $allowDeletedCard = false): bool {
			$this->calls[] = 'check:' . match (true) { $mapper === null => 'board', $mapper instanceof CardMapper => CardMapper::class, $mapper instanceof StackMapper => StackMapper::class, default => 'other' } . ':' . $id . ':' . $permission . ($allowDeletedCard ? ':deleted' : '');

			return true;
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$this->gateway = new DeckServiceGateway($container, $time);
	}

	/** @return list<string> the calls without the binding */
	private function checks(): array {
		return array_values(array_filter($this->calls, static fn (string $c): bool => str_starts_with($c, 'check:') || str_starts_with($c, 'read')));
	}

	public function testCardStateReadsTheRowAgainDeletedIncludedAfterTheReadCheck(): void {
		$row = $this->card(['id' => 7, 'deletedAt' => 1_700_000_500]);
		$this->services[CardMapper::class]->expects(self::once())->method('find')->with(7, false)
			->willReturnCallback(function () use ($row) {
				$this->calls[] = 'read';

				return $row;
			});

		self::assertSame($row, $this->gateway->cardState('alice', 7));
		self::assertSame(['check:' . CardMapper::class . ':7:' . Acl::PERMISSION_READ . ':deleted', 'read'], $this->checks());
	}

	public function testCardStateIsRefusedWithoutAccess(): void {
		$this->services[PermissionService::class] = $this->createMock(PermissionService::class);
		$this->services[PermissionService::class]->method('checkPermission')->willThrowException(new NoPermissionException('Permission denied'));
		$this->services[CardMapper::class]->expects(self::never())->method('find');

		$this->expectException(NoPermissionException::class);
		$this->gateway->cardState('alice', 7);
	}

	/** The rule of the ticket: a card of the caller, same title, created in the last two minutes, newest first. */
	public function testFindCreatedCardIsTheNewestRecentCardOfTheCallerWithTheTitle(): void {
		$this->services[CardMapper::class]->expects(self::once())->method('findAll')->with(10)->willReturn([
			$this->card(['id' => 1, 'title' => 'Fechar', 'owner' => 'alice', 'createdAt' => self::NOW - 600]),
			$this->card(['id' => 2, 'title' => 'Fechar', 'owner' => 'pedro', 'createdAt' => self::NOW - 5]),
			$this->card(['id' => 3, 'title' => 'Outro', 'owner' => 'alice', 'createdAt' => self::NOW - 5]),
			$this->card(['id' => 4, 'title' => 'Fechar', 'owner' => 'alice', 'createdAt' => self::NOW - 100]),
			$this->card(['id' => 5, 'title' => 'Fechar', 'owner' => 'alice', 'createdAt' => self::NOW - 3]),
			$this->card(['id' => 6, 'title' => 'Fechar', 'owner' => 'alice', 'createdAt' => self::NOW - 2]),
		]);

		self::assertSame(5, $this->gateway->findCreatedCard('alice', 10, 'Fechar', [6])?->getId(), 'o 6 já foi contado por esta chamada');
		self::assertSame(['check:' . StackMapper::class . ':10:' . Acl::PERMISSION_READ], $this->checks());
	}

	public function testFindCreatedCardWithoutAMatchIsNull(): void {
		$this->services[CardMapper::class]->method('findAll')->willReturn([$this->card(['id' => 1, 'title' => 'Fechar', 'owner' => 'alice', 'createdAt' => self::NOW - 121])]);

		self::assertNull($this->gateway->findCreatedCard('alice', 10, 'Fechar'));
	}

	public function testCardAssignmentsAreReadAgainAfterTheReadCheck(): void {
		$assignment = new Assignment(['cardId' => 9, 'participant' => 'pedro', 'type' => 0]);
		$this->services[AssignmentMapper::class]->expects(self::once())->method('findIn')->with([9])->willReturn([$assignment]);

		self::assertSame([$assignment], $this->gateway->cardAssignments('alice', 9));
		self::assertSame(['check:' . CardMapper::class . ':9:' . Acl::PERMISSION_READ], $this->checks());
	}

	public function testStacksOfAreTheActiveListsAfterTheReadCheck(): void {
		$stack = new Stack(['id' => 12]);
		$this->services[StackMapper::class]->expects(self::once())->method('findAll')->with(4)->willReturn([$stack]);

		self::assertSame([$stack], $this->gateway->stacksOf('alice', 4));
		self::assertSame(['check:board:4:' . Acl::PERMISSION_READ], $this->checks());
	}

	public function testDeletedStackIsTheListFoundInTheTrash(): void {
		$deleted = new Stack(['id' => 10, 'boardId' => 4]);
		$this->services[StackMapper::class]->method('findBoardId')->with(10)->willReturn(4);
		$this->services[StackMapper::class]->method('findDeleted')->with(4)->willReturn([new Stack(['id' => 9]), $deleted]);

		self::assertSame($deleted, $this->gateway->deletedStack('alice', 10));
		self::assertSame(['check:board:4:' . Acl::PERMISSION_READ], $this->checks());
	}

	public function testAListStillActiveIsNotDeleted(): void {
		$this->services[StackMapper::class]->method('findBoardId')->willReturn(4);
		$this->services[StackMapper::class]->method('findDeleted')->willReturn([]);
		$this->services[StackMapper::class]->method('findAll')->with(4)->willReturn([new Stack(['id' => 10])]);

		self::assertNull($this->gateway->deletedStack('alice', 10));
	}

	/** Neither in the trash nor among the lists: the state cannot be told, and saying "not deleted" would invite a retry. */
	public function testAListFoundNowhereCannotBeTold(): void {
		$this->services[StackMapper::class]->method('findBoardId')->willReturn(4);
		$this->services[StackMapper::class]->method('findDeleted')->willReturn([]);
		$this->services[StackMapper::class]->method('findAll')->willReturn([]);

		$this->expectException(\RuntimeException::class);
		$this->gateway->deletedStack('alice', 10);
	}

	public function testOwnedBoardsAreReadAgainDeletedIncluded(): void {
		$boards = [new Board(['id' => 3]), new Board(['id' => 4, 'deletedAt' => 1])];
		$this->services[BoardMapper::class]->expects(self::once())->method('findAllByOwner')->with('alice')->willReturn($boards);

		self::assertSame($boards, $this->gateway->ownedBoards('alice'));
	}

	public function testNoVerificationReadReachesTheDeckWithoutTheCallerInTheSession(): void {
		$session = $this->createMock(ISession::class);
		$session->method('get')->willReturn(null);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::never())->method('get');
		$gateway = new DeckServiceGateway($container, $this->createMock(ITimeFactory::class), null, $session);

		foreach ([
			fn () => $gateway->cardState('alice', 7),
			fn () => $gateway->findCreatedCard('alice', 10, 'X'),
			fn () => $gateway->cardAssignments('alice', 7),
			fn () => $gateway->stacksOf('alice', 4),
			fn () => $gateway->deletedStack('alice', 10),
			fn () => $gateway->ownedBoards('alice'),
		] as $read) {
			try {
				$read();
				self::fail('a verification read reached the Deck without the caller in the session');
			} catch (DeckSessionException) {
			}
		}
	}
}
