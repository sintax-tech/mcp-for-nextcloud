<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck\Handler;

use InvalidArgumentException;
use OCA\Deck\BadRequestException;
use OCA\Deck\NoPermissionException;
use OCA\Deck\StatusException;
use OCA\Mcp\Tools\Deck\CardFormatter;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckMessages;
use OCA\Mcp\Tools\Deck\Handler\EditCardHandler;
use OCA\Mcp\Tests\Unit\Tools\Deck\DeckTestHelpers;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/../Stubs/deck_stubs.php';

/**
 * Covers `deck_edit_card`, including the optimistic lock and the preservation of untouched fields.
 */
final class EditCardHandlerTest extends TestCase {
	use DeckTestHelpers;

	/**
	 * @param array<string, mixed> $current Current card values.
	 * @param \Throwable|null $failure Exception `updateCard` raises, or null for a happy path.
	 * @return EditCardHandler Handler under test.
	 */
	private function handler(array $current = [], ?\Throwable $failure = null): EditCardHandler {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('findCard')->willReturn($this->card($current + [
			'title' => 'Título atual',
			'description' => 'Descrição atual',
		]));
		$expectation = $gateway->method('updateCard');
		if ($failure !== null) {
			$expectation->willThrowException($failure);
		} else {
			$expectation->willReturn($this->card($current + ['id' => 7]));
		}

		return new EditCardHandler($gateway, $this->createMock(LoggerInterface::class), new CardFormatter());
	}

	public function testHappyPathSendsTheMergedForm(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$current = $this->card([
			'id' => 7,
			'title' => 'Título atual',
			'description' => 'Descrição atual',
			'duedate' => new \DateTime('2026-01-05 00:00:00'),
			'type' => 'note',
			'owner' => 'alice',
			'order' => 3,
		]);
		$gateway->method('findCard')->willReturn($current);
		$gateway->expects(self::once())
			->method('updateCard')
			->with('alice', $current, 'Novo título', 'Descrição atual', '2026-01-05')
			->willReturn($current);
		$handler = new EditCardHandler($gateway, $this->createMock(LoggerInterface::class), new CardFormatter());

		$payload = $this->payload($handler->handle(['cardId' => 7, 'title' => 'Novo título'], 'alice'));

		self::assertSame(7, $payload['id']);
	}

	public function testUntouchedFieldsAreNotResentAsEdits(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$current = $this->card(['id' => 7, 'description' => 'Só isso']);
		$gateway->method('findCard')->willReturn($current);
		$gateway->expects(self::once())
			->method('updateCard')
			->with('alice', $current, 'Card', 'Só isso', null)
			->willReturn($current);
		$handler = new EditCardHandler($gateway, $this->createMock(LoggerInterface::class), new CardFormatter());

		$handler->handle(['cardId' => 7, 'description' => 'Só isso'], 'alice');
	}

	public function testExplicitNullClearsTheDateAndOmissionKeepsIt(): void {
		$current = $this->card(['id' => 7, 'duedate' => new \DateTime('2026-01-05 00:00:00')]);

		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('findCard')->willReturn($current);
		$gateway->expects(self::once())
			->method('updateCard')
			->with('alice', $current, 'Card', '', null)
			->willReturn($current);
		(new EditCardHandler($gateway, $this->createMock(LoggerInterface::class), new CardFormatter()))
			->handle(['cardId' => 7, 'duedate' => null], 'alice');

		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('findCard')->willReturn($current);
		$gateway->expects(self::once())
			->method('updateCard')
			->with('alice', $current, 'Card', '', '2026-01-05')
			->willReturn($current);
		(new EditCardHandler($gateway, $this->createMock(LoggerInterface::class), new CardFormatter()))
			->handle(['cardId' => 7, 'title' => 'Card', 'description' => ''], 'alice');
	}

	public function testStaleLastModifiedIsAConflictAndNothingIsWritten(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('findCard')->willReturn($this->card(['id' => 7, 'lastModified' => 1_700_000_000]));
		$gateway->expects(self::never())->method('updateCard');
		$handler = new EditCardHandler($gateway, $this->createMock(LoggerInterface::class), new CardFormatter());

		$result = $handler->handle([
			'cardId' => 7,
			'title' => 'Novo título',
			'lastModified' => 1_699_999_999,
		], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame(DeckMessages::ERROR_CONFLICT, $this->text($result));
	}

	public function testMatchingLastModifiedProceeds(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$current = $this->card(['id' => 7, 'lastModified' => 1_700_000_000]);
		$gateway->method('findCard')->willReturn($current);
		$gateway->expects(self::once())->method('updateCard')->willReturn($current);
		$handler = new EditCardHandler($gateway, $this->createMock(LoggerInterface::class), new CardFormatter());

		$result = $handler->handle([
			'cardId' => 7,
			'title' => 'Novo título',
			'lastModified' => 1_700_000_000,
		], 'alice');

		self::assertArrayNotHasKey('isError', $result);
	}

	public function testCallWithoutAnyEditableFieldIsAParameterError(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->expects(self::never())->method('findCard');
		$gateway->expects(self::never())->method('updateCard');
		$handler = new EditCardHandler($gateway, $this->createMock(LoggerInterface::class), new CardFormatter());

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(DeckMessages::ERROR_NO_FIELD_TO_EDIT);

		$handler->handle(['cardId' => 7], 'alice');
	}

	public function testLastModifiedAloneIsNotAnEdit(): void {
		$handler = $this->handler();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(DeckMessages::ERROR_NO_FIELD_TO_EDIT);

		$handler->handle(['cardId' => 7, 'lastModified' => 1_700_000_000], 'alice');
	}

	public function testInvalidDateIsAParameterError(): void {
		$handler = $this->handler();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(DeckMessages::ERROR_INVALID_DUEDATE);

		$handler->handle(['cardId' => 7, 'duedate' => '31/12/2026'], 'alice');
	}

	public function testDeniedAccessBecomesTheGenericMessage(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('findCard')->willThrowException(new NoPermissionException('Permission denied'));
		$handler = new EditCardHandler($gateway, $this->createMock(LoggerInterface::class), new CardFormatter());

		$result = $handler->handle(['cardId' => 7, 'title' => 'Novo'], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame(DeckMessages::ERROR_NOT_FOUND_OR_FORBIDDEN, $this->text($result));
	}

	public function testArchivedBoardBecomesTheNotAllowedMessage(): void {
		$result = $this->handler([], new StatusException('Operation not allowed. This board is archived.'))
			->handle(['cardId' => 7, 'title' => 'Novo'], 'alice');

		self::assertSame(DeckMessages::ERROR_NOT_ALLOWED, $this->text($result));
	}

	public function testDeckRejectionBecomesTheInvalidMessage(): void {
		$result = $this->handler([], new BadRequestException('title'))
			->handle(['cardId' => 7, 'title' => 'Novo'], 'alice');

		self::assertSame(DeckMessages::ERROR_INVALID, $this->text($result));
	}

	public function testBackendFailureBecomesTheGenericMessage(): void {
		$result = $this->handler([], new RuntimeException('lock wait timeout'))
			->handle(['cardId' => 7, 'title' => 'Novo'], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame(DeckMessages::ERROR_GENERIC, $this->text($result));
	}
}
