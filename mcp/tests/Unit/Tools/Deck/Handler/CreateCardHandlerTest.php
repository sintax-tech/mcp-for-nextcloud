<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck\Handler;

use InvalidArgumentException;
use OCA\Deck\NoPermissionException;
use OCA\Deck\StatusException;
use OCA\Mcp\Tools\Deck\CardFormatter;
use OCA\Mcp\Tools\Deck\CardInput;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckMessages;
use OCA\Mcp\Tools\Deck\Handler\CreateCardHandler;
use OCA\Mcp\Tests\Unit\Tools\Deck\DeckTestHelpers;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/../Stubs/deck_stubs.php';

/**
 * Covers `deck_create_card`.
 */
final class CreateCardHandlerTest extends TestCase {
	use DeckTestHelpers;

	/**
	 * @param \Throwable|null $failure Exception the gateway raises, or null for a happy path.
	 * @return CreateCardHandler Handler under test.
	 */
	private function handler(?\Throwable $failure = null): CreateCardHandler {
		$gateway = $this->gatewayOwnedBy('alice');
		$expectation = $gateway->method('createCard');
		if ($failure !== null) {
			$expectation->willThrowException($failure);
		} else {
			$expectation->willReturn($this->card(['id' => 9, 'title' => 'Fechar contrato']));
		}

		return new CreateCardHandler($gateway, $this->createMock(LoggerInterface::class), new CardFormatter());
	}

	public function testHappyPathSendsTheCardOwnedByTheCaller(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->expects(self::once())
			->method('createCard')
			->with('alice', 10, 'Fechar contrato', 'Com o cliente', '2026-03-01')
			->willReturn($this->card(['id' => 9]));
		$handler = new CreateCardHandler($gateway, $this->createMock(LoggerInterface::class), new CardFormatter());

		$payload = $this->payload($handler->handle([
			'stackId' => 10,
			'title' => '  Fechar contrato  ',
			'description' => 'Com o cliente',
			'duedate' => '2026-03-01',
		], 'alice'));

		self::assertSame(9, $payload['id']);
	}

	public function testOptionalFieldsFallBackToEmptyAndNoDate(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->expects(self::once())
			->method('createCard')
			->with('alice', 10, 'Fechar', '', null)
			->willReturn($this->card(['id' => 9]));
		$handler = new CreateCardHandler($gateway, $this->createMock(LoggerInterface::class), new CardFormatter());

		$handler->handle(['stackId' => 10, 'title' => 'Fechar'], 'alice');
	}

	public function testBlankTitleIsAParameterErrorAndNeverReachesDeck(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->expects(self::never())->method('createCard');
		$handler = new CreateCardHandler($gateway, $this->createMock(LoggerInterface::class), new CardFormatter());

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(DeckMessages::ERROR_TITLE_REQUIRED);

		$handler->handle(['stackId' => 10, 'title' => '   '], 'alice');
	}

	public function testImpossibleDateIsAParameterError(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->expects(self::never())->method('createCard');
		$handler = new CreateCardHandler($gateway, $this->createMock(LoggerInterface::class), new CardFormatter());

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(DeckMessages::ERROR_INVALID_DUEDATE);

		$handler->handle(['stackId' => 10, 'title' => 'Fechar', 'duedate' => '2026-02-31'], 'alice');
	}

	public function testOversizedDescriptionIsRefusedInsteadOfTruncated(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->expects(self::never())->method('createCard');
		$handler = new CreateCardHandler($gateway, $this->createMock(LoggerInterface::class), new CardFormatter());

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(DeckMessages::ERROR_DESCRIPTION_TOO_LONG);

		$handler->handle([
			'stackId' => 10,
			'title' => 'Fechar',
			'description' => str_repeat('a', CardInput::MAX_DESCRIPTION_LENGTH + 1),
		], 'alice');
	}

	public function testDeniedAccessBecomesTheGenericMessage(): void {
		$result = $this->handler(new NoPermissionException('Permission denied'))
			->handle(['stackId' => 10, 'title' => 'Fechar'], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame(DeckMessages::ERROR_NOT_FOUND_OR_FORBIDDEN, $this->text($result));
	}

	public function testArchivedBoardBecomesTheNotAllowedMessage(): void {
		$result = $this->handler(new StatusException('Operation not allowed. This board is archived.'))
			->handle(['stackId' => 10, 'title' => 'Fechar'], 'alice');

		self::assertSame(DeckMessages::ERROR_NOT_ALLOWED, $this->text($result));
	}

	public function testBackendFailureBecomesTheGenericMessage(): void {
		$result = $this->handler(new RuntimeException('foreign key violation'))
			->handle(['stackId' => 10, 'title' => 'Fechar'], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame(DeckMessages::ERROR_GENERIC, $this->text($result));
		self::assertStringNotContainsString('foreign key', $this->text($result));
	}

	public function testSharedBoardWithoutConfirmationCreatesNothing(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->expects(self::once())
			->method('stackOwnership')
			->with('alice', 10)
			->willReturn($this->ownershipOf('pedro', 'Comercial'));
		$gateway->expects(self::never())->method('createCard');
		$handler = new CreateCardHandler($gateway, $this->createMock(LoggerInterface::class), new CardFormatter());

		$this->assertSharedConfirmation($handler->handle([
			'stackId' => 10,
			'title' => 'Fechar contrato',
		], 'alice'));
	}

	public function testSharedBoardWithConfirmationCreatesTheCard(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('stackOwnership')->willReturn($this->ownershipOf('pedro', 'Comercial'));
		$gateway->expects(self::once())
			->method('createCard')
			->with('alice', 10, 'Fechar contrato', '', null)
			->willReturn($this->card(['id' => 9]));
		$handler = new CreateCardHandler($gateway, $this->createMock(LoggerInterface::class), new CardFormatter());

		$payload = $this->payload($handler->handle([
			'stackId' => 10,
			'title' => 'Fechar contrato',
			'confirm_shared' => true,
		], 'alice'));

		self::assertSame(9, $payload['id']);
	}

	public function testOwnershipDeniedIsRefusedBeforeAnythingIsCreated(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('stackOwnership')->willThrowException(new NoPermissionException('Permission denied'));
		$gateway->expects(self::never())->method('createCard');
		$handler = new CreateCardHandler($gateway, $this->createMock(LoggerInterface::class), new CardFormatter());

		$result = $handler->handle(['stackId' => 10, 'title' => 'Fechar contrato'], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame(DeckMessages::ERROR_NOT_FOUND_OR_FORBIDDEN, $this->text($result));
	}
}
