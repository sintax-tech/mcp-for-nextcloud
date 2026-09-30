<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use OCA\Deck\ArchivedItemException;
use OCA\Deck\BadRequestException;
use OCA\Deck\ConflictException;
use OCA\Deck\NoPermissionException;
use OCA\Deck\StatusException;
use OCA\Mcp\Tools\Deck\DeckConflictException;
use OCA\Mcp\Tools\Deck\DeckErrors;
use OCA\Mcp\Tools\Deck\DeckMessages;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/Stubs/deck_stubs.php';

/**
 * Covers the exception to message mapping that keeps Deck internals out of the answer.
 */
final class DeckErrorsTest extends TestCase {
	/**
	 * @return array<string, array{\Throwable, string}>
	 */
	public static function mappingProvider(): array {
		return [
			'no permission' => [new NoPermissionException('Permission denied'), DeckMessages::ERROR_NOT_FOUND_OR_FORBIDDEN],
			'does not exist' => [new DoesNotExistException('gone'), DeckMessages::ERROR_NOT_FOUND_OR_FORBIDDEN],
			'multiple objects' => [new MultipleObjectsReturnedException('ambiguous'), DeckMessages::ERROR_NOT_FOUND_OR_FORBIDDEN],
			'archived status' => [new StatusException('board archived'), DeckMessages::ERROR_NOT_ALLOWED],
			'archived item' => [new ArchivedItemException('card archived'), DeckMessages::ERROR_NOT_ALLOWED],
			'bad request' => [new BadRequestException('too long'), DeckMessages::ERROR_INVALID],
			'conflict' => [new ConflictException('stale'), DeckMessages::ERROR_CONFLICT],
			'own conflict' => [new DeckConflictException(DeckMessages::ERROR_CONFLICT), DeckMessages::ERROR_CONFLICT],
			'unexpected' => [new RuntimeException('connection to 10.0.0.5 refused'), DeckMessages::ERROR_GENERIC],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('mappingProvider')]
	public function testMessageFor(\Throwable $exception, string $expected): void {
		self::assertSame($expected, DeckErrors::messageFor($exception));
	}

	public function testNotFoundAndNoPermissionShareOneMessage(): void {
		self::assertSame(
			DeckErrors::messageFor(new NoPermissionException('Permission denied')),
			DeckErrors::messageFor(new DoesNotExistException('gone')),
		);
	}

	public function testGenericMessageLeaksNoDetail(): void {
		$message = DeckErrors::messageFor(new RuntimeException('SELECT * FROM deck_cards failed at /srv/data'));

		self::assertSame(DeckMessages::ERROR_GENERIC, $message);
		self::assertStringNotContainsString('deck_cards', $message);
		self::assertStringNotContainsString('/srv/data', $message);
	}
}
