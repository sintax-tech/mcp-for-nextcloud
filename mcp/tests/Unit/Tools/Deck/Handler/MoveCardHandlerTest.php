<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck\Handler;

use OCA\Deck\NoPermissionException;
use OCA\Deck\StatusException;
use OCA\Mcp\Tools\Deck\CardFormatter;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckMessages;
use OCA\Mcp\Tools\Deck\Handler\MoveCardHandler;
use OCA\Mcp\Tests\Unit\Tools\Deck\DeckTestHelpers;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/../Stubs/deck_stubs.php';

/**
 * Covers `deck_move_card`.
 */
final class MoveCardHandlerTest extends TestCase {
	use DeckTestHelpers;

	/**
	 * @param \Throwable|null $failure Exception the gateway raises, or null for a happy path.
	 * @return MoveCardHandler Handler under test.
	 */
	private function handler(?\Throwable $failure = null): MoveCardHandler {
		$gateway = $this->gatewayOwnedBy('alice');
		$expectation = $gateway->method('moveCard');
		if ($failure !== null) {
			$expectation->willThrowException($failure);
		} else {
			$expectation->willReturn($this->card(['id' => 7, 'stackId' => 11]));
		}

		return new MoveCardHandler($gateway, $this->createMock(LoggerInterface::class), new CardFormatter());
	}

	public function testHappyPathWithoutOrderLetsTheGatewayAppend(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->expects(self::once())
			->method('moveCard')
			->with('alice', 7, 11, null)
			->willReturn($this->card(['id' => 7, 'stackId' => 11]));
		$handler = new MoveCardHandler($gateway, $this->createMock(LoggerInterface::class), new CardFormatter());

		$payload = $this->payload($handler->handle(['cardId' => 7, 'stackId' => 11], 'alice'));

		self::assertSame(11, $payload['stackId']);
	}

	public function testExplicitOrderIsForwardedAsZeroToo(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->expects(self::once())
			->method('moveCard')
			->with('alice', 7, 11, 0)
			->willReturn($this->card(['id' => 7, 'stackId' => 11]));
		$handler = new MoveCardHandler($gateway, $this->createMock(LoggerInterface::class), new CardFormatter());

		$handler->handle(['cardId' => 7, 'stackId' => 11, 'order' => 0], 'alice');
	}

	public function testMovingToAnotherBoardIsTheSameCall(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->expects(self::once())
			->method('moveCard')
			->with('alice', 7, 42, 3)
			->willReturn($this->card(['id' => 7, 'stackId' => 42]));
		$handler = new MoveCardHandler($gateway, $this->createMock(LoggerInterface::class), new CardFormatter());

		self::assertSame(42, $this->payload($handler->handle(['cardId' => 7, 'stackId' => 42, 'order' => 3], 'alice'))['stackId']);
	}

	public function testDeniedAccessBecomesTheGenericMessage(): void {
		$result = $this->handler(new NoPermissionException('Permission denied'))
			->handle(['cardId' => 7, 'stackId' => 11], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame(DeckMessages::ERROR_NOT_FOUND_OR_FORBIDDEN, $this->text($result));
		self::assertStringNotContainsString('Permission denied', $this->text($result));
	}

	public function testArchivedCardBecomesTheNotAllowedMessage(): void {
		$result = $this->handler(new StatusException('Operation not allowed. This card is archived.'))
			->handle(['cardId' => 7, 'stackId' => 11], 'alice');

		self::assertSame(DeckMessages::ERROR_NOT_ALLOWED, $this->text($result));
	}

	public function testBackendFailureBecomesTheGenericMessage(): void {
		$result = $this->handler(new RuntimeException('deadlock on deck_cards'))
			->handle(['cardId' => 7, 'stackId' => 11], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame(DeckMessages::ERROR_GENERIC, $this->text($result));
		self::assertStringNotContainsString('deck_cards', $this->text($result));
	}

	public function testSharedOriginBoardWithoutConfirmationMovesNothing(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->expects(self::once())
			->method('cardOwnership')
			->with('alice', 7)
			->willReturn($this->ownershipOf('pedro', 'Comercial'));
		$gateway->expects(self::never())->method('stackOwnership');
		$gateway->expects(self::never())->method('moveCard');
		$handler = new MoveCardHandler($gateway, $this->createMock(LoggerInterface::class), new CardFormatter());

		$this->assertSharedConfirmation($handler->handle(['cardId' => 7, 'stackId' => 11], 'alice'));
	}

	public function testSharedDestinationStackAlsoNeedsTheConfirmation(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('cardOwnership')->willReturn($this->ownershipOf('alice'));
		$gateway->expects(self::once())
			->method('stackOwnership')
			->with('alice', 11)
			->willReturn($this->ownershipOf('pedro', 'Comercial'));
		$gateway->expects(self::never())->method('moveCard');
		$handler = new MoveCardHandler($gateway, $this->createMock(LoggerInterface::class), new CardFormatter());

		$this->assertSharedConfirmation($handler->handle(['cardId' => 7, 'stackId' => 11], 'alice'));
	}

	public function testSharedBoardsWithConfirmationMoveTheCard(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('cardOwnership')->willReturn($this->ownershipOf('pedro', 'Comercial'));
		$gateway->method('stackOwnership')->willReturn($this->ownershipOf('pedro', 'Comercial'));
		$gateway->expects(self::once())
			->method('moveCard')
			->with('alice', 7, 11, null)
			->willReturn($this->card(['id' => 7, 'stackId' => 11]));
		$handler = new MoveCardHandler($gateway, $this->createMock(LoggerInterface::class), new CardFormatter());

		$payload = $this->payload($handler->handle([
			'cardId' => 7,
			'stackId' => 11,
			'confirm_shared' => true,
		], 'alice'));

		self::assertSame(11, $payload['stackId']);
	}

	public function testOwnershipDeniedIsRefusedBeforeTheCardMoves(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('cardOwnership')->willThrowException(new NoPermissionException('Permission denied'));
		$gateway->expects(self::never())->method('stackOwnership');
		$gateway->expects(self::never())->method('moveCard');
		$handler = new MoveCardHandler($gateway, $this->createMock(LoggerInterface::class), new CardFormatter());

		$result = $handler->handle(['cardId' => 7, 'stackId' => 11], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame(DeckMessages::ERROR_NOT_FOUND_OR_FORBIDDEN, $this->text($result));
	}
}
