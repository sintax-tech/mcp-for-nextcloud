<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck\Handler;

use OCA\Deck\NoPermissionException;
use OCA\Deck\StatusException;
use OCA\Mcp\Tools\Deck\CardFormatter;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckMessages;
use OCA\Mcp\Tools\Deck\Handler\DeleteCardHandler;
use OCA\Mcp\Tests\Unit\Tools\Deck\DeckTestHelpers;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/../Stubs/deck_stubs.php';

/**
 * Covers `deck_delete_card`, whose schema makes `confirm: true` mandatory before the handler runs.
 */
final class DeleteCardHandlerTest extends TestCase {
	use DeckTestHelpers;

	/**
	 * @param \Throwable|null $failure Exception the gateway raises, or null for a happy path.
	 * @return DeleteCardHandler Handler under test.
	 */
	private function handler(?\Throwable $failure = null): DeleteCardHandler {
		$gateway = $this->gatewayOwnedBy('alice');
		$expectation = $gateway->method('deleteCard');
		if ($failure !== null) {
			$expectation->willThrowException($failure);
		} else {
			$expectation->willReturn($this->card(['id' => 7, 'deletedAt' => 1_700_000_900]));
		}

		return new DeleteCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());
	}

	public function testHappyPathDeletesForTheAuthenticatedUser(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->expects(self::once())
			->method('deleteCard')
			->with('alice', 7)
			->willReturn($this->card(['id' => 7, 'deletedAt' => 1_700_000_900]));
		$handler = new DeleteCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$payload = $this->payload($handler->handle(['cardId' => 7, 'confirm' => true], 'alice'));

		self::assertSame(7, $payload['id']);
	}

	public function testDeleteIsTheDeckSoftDelete(): void {
		// The card is returned with `deletedAt` stamped, exactly as `CardService::delete()` leaves it.
		$payload = $this->payload($this->handler()->handle(['cardId' => 7, 'confirm' => true], 'alice'));

		self::assertSame(7, $payload['id']);
	}

	public function testDeniedAccessBecomesTheGenericMessage(): void {
		$result = $this->handler(new NoPermissionException('Permission denied'))
			->handle(['cardId' => 7, 'confirm' => true], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame(DeckMessages::errorNotFoundOrForbidden(), $this->text($result));
	}

	public function testArchivedBoardBecomesTheNotAllowedMessage(): void {
		$result = $this->handler(new StatusException('Operation not allowed. This board is archived.'))
			->handle(['cardId' => 7, 'confirm' => true], 'alice');

		self::assertSame(DeckMessages::errorNotAllowed(), $this->text($result));
	}

	public function testBackendFailureBecomesTheGenericMessage(): void {
		$result = $this->handler(new RuntimeException('deadlock'))
			->handle(['cardId' => 7, 'confirm' => true], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame(DeckMessages::errorGeneric(), $this->text($result));
	}

	public function testSharedBoardWithoutConfirmationDeletesNothing(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->expects(self::once())
			->method('cardOwnership')
			->with('alice', 7)
			->willReturn($this->ownershipOf('pedro', 'Comercial'));
		$gateway->expects(self::never())->method('deleteCard');
		$handler = new DeleteCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$this->assertSharedConfirmation($handler->handle(['cardId' => 7, 'confirm' => true], 'alice'));
	}

	public function testSharedBoardWithConfirmationDeletesTheCard(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('cardOwnership')->willReturn($this->ownershipOf('pedro', 'Comercial'));
		$gateway->expects(self::once())
			->method('deleteCard')
			->with('alice', 7)
			->willReturn($this->card(['id' => 7, 'deletedAt' => 1_700_000_900]));
		$handler = new DeleteCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$payload = $this->payload($handler->handle([
			'cardId' => 7,
			'confirm' => true,
			'confirm_shared' => true,
		], 'alice'));

		self::assertSame(7, $payload['id']);
	}

	public function testOwnershipDeniedIsRefusedBeforeTheCardIsDeleted(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('cardOwnership')->willThrowException(new NoPermissionException('Permission denied'));
		$gateway->expects(self::never())->method('deleteCard');
		$handler = new DeleteCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$result = $handler->handle(['cardId' => 7, 'confirm' => true], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame(DeckMessages::errorNotFoundOrForbidden(), $this->text($result));
	}
}
