<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck;

/**
 * Maps Deck and OCP exceptions to the generic messages held by {@see DeckMessages}.
 *
 * Class names are strings so this file never needs the Deck app to be installed and never
 * autoloads a class that may not exist.
 */
final class DeckErrors {
	/** @var list<array{list<string>, string}> Pairs of exception classes and the message they map to. */
	private const MAPPING = [
		[
			[DeckConflictException::class],
			DeckMessages::ERROR_CONFLICT,
		],
		[
			['OCA\Deck\NoPermissionException', 'OCP\AppFramework\Db\DoesNotExistException', 'OCP\AppFramework\Db\IMapperException'],
			DeckMessages::ERROR_NOT_FOUND_OR_FORBIDDEN,
		],
		[
			['OCA\Deck\StatusException', 'OCA\Deck\ArchivedItemException'],
			DeckMessages::ERROR_NOT_ALLOWED,
		],
		[
			['OCA\Deck\BadRequestException'],
			DeckMessages::ERROR_INVALID,
		],
		[
			['OCA\Deck\ConflictException'],
			DeckMessages::ERROR_CONFLICT,
		],
	];

	/**
	 * Resolves the caller-safe message for an exception.
	 *
	 * "Not found" and "no permission" deliberately share one message: the Deck itself answers
	 * `NoPermissionException('Permission denied')` in both cases to avoid leaking existence.
	 *
	 * @param \Throwable $exception Exception raised by the Deck gateway or by the handler.
	 * @return string One of the `ERROR_*` messages of {@see DeckMessages}.
	 */
	public static function messageFor(\Throwable $exception): string {
		foreach (self::MAPPING as [$classes, $message]) {
			foreach ($classes as $class) {
				if (is_a($exception, $class)) {
					return $message;
				}
			}
		}

		return DeckMessages::ERROR_GENERIC;
	}
}
