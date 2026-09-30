<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use LogicException;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Model\OptionalNullableValue;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\PermissionService;
use OCA\Deck\Service\StackService;
use OCA\Mcp\Tools\Deck\DeckServiceGateway;
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

	private DeckServiceGateway $gateway;

	protected function setUp(): void {
		$this->services = [
			BoardService::class => $this->createMock(BoardService::class),
			StackService::class => $this->createMock(StackService::class),
			CardService::class => $this->createMock(CardService::class),
			PermissionService::class => $this->createMock(PermissionService::class),
			CardMapper::class => $this->createMock(CardMapper::class),
			StackMapper::class => $this->createMock(StackMapper::class),
		];

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

		$this->gateway = new DeckServiceGateway($this->container);
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
			->with('Fechar', 10, 'note', 99999, 'alice', 'Com o cliente', '2026-03-01')
			->willReturn($card);

		self::assertSame($card, $this->gateway->createCard('alice', 10, 'Fechar', 'Com o cliente', '2026-03-01'));
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
		$gateway = new DeckServiceGateway($this->container);

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
	 * @return \OCA\Deck\Db\Board Board double.
	 */
	private function boardDouble(int $id): \OCA\Deck\Db\Board {
		return new \OCA\Deck\Db\Board(['id' => $id, 'title' => 'Board ' . $id]);
	}
}
