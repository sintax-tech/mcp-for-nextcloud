<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck\Handler;

use OCA\Deck\NoPermissionException;
use OCA\Mcp\Tools\Deck\CardFormatter;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckMessages;
use OCA\Mcp\Tools\Deck\Handler\ListCardsHandler;
use OCA\Mcp\Tests\Unit\Tools\Deck\DeckTestHelpers;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/../Stubs/deck_stubs.php';

/**
 * Covers `deck_list_cards`, including the guarantee that a deleted card never reaches the client.
 */
final class ListCardsHandlerTest extends TestCase {
	use DeckTestHelpers;

	/**
	 * @param array<string, mixed> $items Cards the gateway returns.
	 * @param int $boardId Board reported by the gateway.
	 * @return ListCardsHandler Handler under test.
	 */
	private function handler(array $items, int $boardId = 4): ListCardsHandler {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('listCards')->willReturn(['items' => $items, 'boardId' => $boardId]);

		return new ListCardsHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());
	}

	public function testHappyPathForwardsStackAndPagination(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->expects(self::once())
			->method('listCards')
			->with('alice', 10, 25, 50)
			->willReturn(['items' => [$this->card(['id' => 1])], 'boardId' => 4]);
		$handler = new ListCardsHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$payload = $this->payload($handler->handle(['stackId' => 10, 'limit' => 25, 'offset' => 50], 'alice'));

		self::assertSame(1, $payload['cards'][0]['id']);
		self::assertSame(4, $payload['cards'][0]['boardId']);
		self::assertSame(25, $payload['limit']);
		self::assertSame(50, $payload['offset']);
	}

	public function testDefaultsAreAppliedWhenTheClientOmitsPagination(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->expects(self::once())
			->method('listCards')
			->with('alice', 10, ListCardsHandler::DEFAULT_LIMIT, 0)
			->willReturn(['items' => [], 'boardId' => 4]);
		$handler = new ListCardsHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		self::assertSame(ListCardsHandler::DEFAULT_LIMIT, $this->payload($handler->handle(['stackId' => 10], 'alice'))['limit']);
	}

	public function testDeletedCardNeverReachesTheClient(): void {
		$payload = $this->payload($this->handler([
			$this->card(['id' => 1]),
			$this->card(['id' => 2, 'deletedAt' => 1_700_000_900]),
		])->handle(['stackId' => 10], 'alice'));

		self::assertSame([1], array_column($payload['cards'], 'id'));
	}

	public function testArchivedCardNeverReachesTheClient(): void {
		$payload = $this->payload($this->handler([
			$this->card(['id' => 1, 'archived' => true]),
		])->handle(['stackId' => 10], 'alice'));

		self::assertSame([], $payload['cards']);
	}

	public function testTotalIsOnlyClaimedWhenThePageCameBackShort(): void {
		$short = $this->payload($this->handler([$this->card()])->handle(['stackId' => 10, 'limit' => 50], 'alice'));
		$full = $this->payload($this->handler([
			$this->card(['id' => 1]),
			$this->card(['id' => 2]),
		])->handle(['stackId' => 10, 'limit' => 2], 'alice'));

		self::assertSame(1, $short['total']);
		self::assertNull($full['total']);
	}

	public function testDeniedAccessBecomesTheGenericMessage(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('listCards')->willThrowException(new NoPermissionException('Permission denied'));
		$handler = new ListCardsHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$result = $handler->handle(['stackId' => 10], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame(DeckMessages::errorNotFoundOrForbidden(), $this->text($result));
	}

	public function testBackendFailureBecomesTheGenericMessage(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('listCards')->willThrowException(new RuntimeException('deadlock'));
		$handler = new ListCardsHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$result = $handler->handle(['stackId' => 10], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame(DeckMessages::errorGeneric(), $this->text($result));
	}
}
