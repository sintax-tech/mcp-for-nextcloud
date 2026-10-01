<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck\Handler;

use OCA\Deck\Db\Board;
use OCA\Deck\NoPermissionException;
use OCA\Deck\StatusException;
use OCA\Mcp\Tools\Deck\CardFormatter;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckMessages;
use OCA\Mcp\Tools\Deck\Handler\ListBoardsHandler;
use OCA\Mcp\Tests\Unit\Tools\Deck\DeckTestHelpers;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/../Stubs/deck_stubs.php';

/**
 * Covers `deck_list_boards`.
 */
final class ListBoardsHandlerTest extends TestCase {
	use DeckTestHelpers;

	/**
	 * @param array<Board> $boards Boards the gateway returns.
	 * @param LoggerInterface|null $logger Logger to inspect.
	 * @return ListBoardsHandler Handler under test.
	 */
	private function handler(array $boards, ?LoggerInterface $logger = null): ListBoardsHandler {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('listBoards')->willReturn($boards);

		return new ListBoardsHandler($gateway, $logger ?? $this->createMock(LoggerInterface::class), $this->cardFormatter());
	}

	public function testHappyPathReturnsTheBoards(): void {
		$result = $this->handler([
			new Board(['id' => 1, 'title' => 'Casa', 'owner' => 'alice', 'lastModified' => 5]),
			new Board(['id' => 2, 'title' => 'Trabalho', 'owner' => 'alice', 'lastModified' => 6]),
		])->handle([], 'alice');

		$payload = $this->payload($result);
		self::assertSame([1, 2], array_column($payload['boards'], 'id'));
		self::assertSame(['Casa', 'Trabalho'], array_column($payload['boards'], 'title'));
		self::assertFalse($payload['truncated']);
	}

	public function testHappyPathForwardsTheAuthenticatedUser(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->expects(self::once())
			->method('listBoards')
			->with('alice')
			->willReturn([]);
		$handler = new ListBoardsHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		self::assertSame(['boards' => [], 'truncated' => false], $this->payload($handler->handle([], 'alice')));
	}

	public function testTruncatesAtTwoHundredBoards(): void {
		$boards = [];
		for ($i = 0; $i < ListBoardsHandler::MAX_BOARDS + 5; $i++) {
			$boards[] = new Board(['id' => $i]);
		}

		$payload = $this->payload($this->handler($boards)->handle([], 'alice'));

		self::assertCount(ListBoardsHandler::MAX_BOARDS, $payload['boards']);
		self::assertTrue($payload['truncated']);
	}

	public function testDeniedAccessBecomesTheGenericMessageWithoutDetails(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('listBoards')->willThrowException(new NoPermissionException('Permission denied'));
		$handler = new ListBoardsHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$result = $handler->handle([], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame(DeckMessages::errorNotFoundOrForbidden(), $this->text($result));
		self::assertStringNotContainsString('Permission denied', $this->text($result));
	}

	public function testArchivedBoardBecomesTheNotAllowedMessage(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('listBoards')->willThrowException(new StatusException('Operation not allowed. This board is archived.'));
		$handler = new ListBoardsHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$result = $handler->handle([], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame(DeckMessages::errorNotAllowed(), $this->text($result));
	}

	public function testBackendFailureIsLoggedByClassOnly(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('error')
			->with(
				self::stringContains('{exception}'),
				self::callback(static function (array $context): bool {
					return $context['tool'] === 'deck_list_boards'
						&& $context['exception'] === RuntimeException::class
						&& !str_contains(json_encode($context), 'connection refused');
				}),
			);

		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('listBoards')->willThrowException(new RuntimeException('connection refused'));
		$handler = new ListBoardsHandler($gateway, $logger, $this->cardFormatter());

		$result = $handler->handle([], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame(DeckMessages::errorGeneric(), $this->text($result));
	}
}
