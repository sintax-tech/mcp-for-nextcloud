<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use OCA\Deck\ArchivedItemException;
use OCA\Deck\BadRequestException;
use OCA\Deck\Exceptions\ConflictException;
use OCA\Deck\NoPermissionException;
use OCA\Deck\StatusException;
use OCA\Mcp\Tools\Deck\DeckConflictException;
use OCA\Mcp\Tools\Deck\DeckErrors;
use OCA\Mcp\Tools\Deck\DeckMessages;
use OCA\Mcp\Tools\Deck\DeckSessionException;
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
			'no permission' => [new NoPermissionException('Permission denied'), DeckMessages::errorNotFoundOrForbidden()],
			'does not exist' => [new DoesNotExistException('gone'), DeckMessages::errorNotFoundOrForbidden()],
			'multiple objects' => [new MultipleObjectsReturnedException('ambiguous'), DeckMessages::errorNotFoundOrForbidden()],
			'archived status' => [new StatusException('board archived'), DeckMessages::errorNotAllowed()],
			'archived item' => [new ArchivedItemException('card archived'), DeckMessages::errorNotAllowed()],
			'bad request' => [new BadRequestException('too long'), DeckMessages::errorInvalid()],
			'conflict' => [new ConflictException('stale'), DeckMessages::errorConflict()],
			'own conflict' => [new DeckConflictException(DeckMessages::errorConflict()), DeckMessages::errorConflict()],
			'session not bound' => [new DeckSessionException('session user differs'), DeckMessages::errorSessionNotBound()],
			'unexpected' => [new RuntimeException('connection to 10.0.0.5 refused'), DeckMessages::errorGeneric()],
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

		self::assertSame(DeckMessages::errorGeneric(), $message);
		self::assertStringNotContainsString('deck_cards', $message);
		self::assertStringNotContainsString('/srv/data', $message);
	}
}
