<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck\Handler;

use OCA\Deck\BadRequestException;
use OCA\Deck\NoPermissionException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCA\Mcp\Tools\Deck\CardFormatter;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckMessages;
use OCA\Mcp\Tools\Deck\Handler\ReadCardHandler;
use OCA\Mcp\Tests\Unit\Tools\Deck\DeckTestHelpers;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/../Stubs/deck_stubs.php';

/**
 * Covers `deck_read_card`.
 */
final class ReadCardHandlerTest extends TestCase {
	use DeckTestHelpers;

	/**
	 * @param \Throwable|null $failure Exception the gateway raises, or null for a happy path.
	 * @return ReadCardHandler Handler under test.
	 */
	private function handler(?\Throwable $failure = null): ReadCardHandler {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$expectation = $gateway->method('findCard');
		if ($failure !== null) {
			$expectation->willThrowException($failure);
		} else {
			$expectation->willReturn($this->card([
				'id' => 7,
				'title' => 'Fechar contrato',
				'description' => 'Com o cliente',
				'attachmentCount' => 2,
			]));
		}

		return new ReadCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());
	}

	public function testHappyPathForwardsTheCardAndTheCaller(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->expects(self::once())
			->method('findCard')
			->with('alice', 7)
			->willReturn($this->card(['id' => 7, 'title' => 'Fechar contrato']));
		$handler = new ReadCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$payload = $this->payload($handler->handle(['cardId' => 7], 'alice'));

		self::assertSame(7, $payload['id']);
		self::assertSame('Fechar contrato', $payload['title']);
	}

	public function testDescriptionIsReturnedInFull(): void {
		$description = str_repeat('linha\n', 500);

		$payload = $this->payload($this->handlerWithDescription($description)->handle(['cardId' => 7], 'alice'));

		self::assertSame($description, $payload['description']);
	}

	public function testDeniedAccessBecomesTheSameMessageAsAMissingCard(): void {
		$denied = $this->handler(new NoPermissionException('Permission denied'))->handle(['cardId' => 7], 'alice');
		$missing = $this->handler(new DoesNotExistException('gone'))->handle(['cardId' => 7], 'alice');

		self::assertTrue($denied['isError']);
		self::assertSame(DeckMessages::errorNotFoundOrForbidden(), $this->text($denied));
		self::assertSame($this->text($denied), $this->text($missing));
	}

	public function testDeckValidationFailureBecomesTheInvalidMessage(): void {
		$result = $this->handler(new BadRequestException('title too long'))->handle(['cardId' => 7], 'alice');

		self::assertSame(DeckMessages::errorInvalid(), $this->text($result));
	}

	public function testBackendFailureBecomesTheGenericMessage(): void {
		$result = $this->handler(new RuntimeException('boom'))->handle(['cardId' => 7], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame(DeckMessages::errorGeneric(), $this->text($result));
	}

	/**
	 * @param string $description Description the returned card carries.
	 * @return ReadCardHandler Handler under test.
	 */
	private function handlerWithDescription(string $description): ReadCardHandler {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('findCard')->willReturn($this->card(['id' => 7, 'description' => $description]));

		return new ReadCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());
	}
}
