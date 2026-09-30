<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck\Handler;

use OCA\Deck\Db\Card;
use OCA\Deck\Db\Stack;
use OCA\Deck\NoPermissionException;
use OCA\Deck\StatusException;
use OCA\Mcp\Tools\Deck\CardFormatter;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckMessages;
use OCA\Mcp\Tools\Deck\Handler\ListStacksHandler;
use OCA\Mcp\Tests\Unit\Tools\Deck\DeckTestHelpers;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/../Stubs/deck_stubs.php';

/**
 * Covers `deck_list_stacks`.
 */
final class ListStacksHandlerTest extends TestCase {
	use DeckTestHelpers;

	/**
	 * @param array<Stack> $stacks Stacks the gateway returns.
	 * @return ListStacksHandler Handler under test.
	 */
	private function handler(array $stacks): ListStacksHandler {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('listStacks')->willReturn($stacks);

		return new ListStacksHandler($gateway, $this->createMock(LoggerInterface::class), new CardFormatter());
	}

	public function testHappyPathForwardsTheBoardAndTheCaller(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->expects(self::once())
			->method('listStacks')
			->with('alice', 4)
			->willReturn([new Stack(['id' => 10, 'boardId' => 4, 'title' => 'A fazer', 'order' => 1])]);
		$handler = new ListStacksHandler($gateway, $this->createMock(LoggerInterface::class), new CardFormatter());

		$payload = $this->payload($handler->handle(['boardId' => 4], 'alice'));

		self::assertSame([[
			'id' => 10,
			'boardId' => 4,
			'title' => 'A fazer',
			'order' => 1,
			'cardsCount' => 0,
		]], $payload['stacks']);
		self::assertFalse($payload['truncated']);
	}

	public function testCardsAreCountedAndNotRepeatedInThePayload(): void {
		$stack = new Stack([
			'id' => 10,
			'boardId' => 4,
			'title' => 'A fazer',
			'order' => 1,
			'cards' => [new Card(['id' => 1]), new Card(['id' => 2])],
		]);

		$payload = $this->payload($this->handler([$stack])->handle(['boardId' => 4], 'alice'));

		self::assertSame(2, $payload['stacks'][0]['cardsCount']);
		self::assertStringNotContainsString('"description"', $this->text(
			$this->handler([$stack])->handle(['boardId' => 4], 'alice'),
		));
	}

	public function testTruncatesAtTwoHundredStacks(): void {
		$stacks = [];
		for ($i = 0; $i < ListStacksHandler::MAX_STACKS + 1; $i++) {
			$stacks[] = new Stack(['id' => $i, 'boardId' => 4]);
		}

		$payload = $this->payload($this->handler($stacks)->handle(['boardId' => 4], 'alice'));

		self::assertCount(ListStacksHandler::MAX_STACKS, $payload['stacks']);
		self::assertTrue($payload['truncated']);
	}

	public function testDeniedAccessBecomesTheGenericMessage(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('listStacks')->willThrowException(new NoPermissionException('Permission denied'));
		$handler = new ListStacksHandler($gateway, $this->createMock(LoggerInterface::class), new CardFormatter());

		$result = $handler->handle(['boardId' => 4], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame(DeckMessages::ERROR_NOT_FOUND_OR_FORBIDDEN, $this->text($result));
	}

	public function testArchivedBoardBecomesTheNotAllowedMessage(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('listStacks')->willThrowException(new StatusException('Operation not allowed.'));
		$handler = new ListStacksHandler($gateway, $this->createMock(LoggerInterface::class), new CardFormatter());

		self::assertSame(DeckMessages::ERROR_NOT_ALLOWED, $this->text($handler->handle(['boardId' => 4], 'alice')));
	}

	public function testBackendFailureBecomesTheGenericMessage(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('listStacks')->willThrowException(new RuntimeException('SQLSTATE'));
		$handler = new ListStacksHandler($gateway, $this->createMock(LoggerInterface::class), new CardFormatter());

		$result = $handler->handle(['boardId' => 4], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame(DeckMessages::ERROR_GENERIC, $this->text($result));
	}
}
