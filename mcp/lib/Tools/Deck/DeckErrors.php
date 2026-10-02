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
	/** @var list<array{list<string>, string}> Pairs of exception classes and the name of the {@see DeckMessages} method that words them. */
	private const MAPPING = [
		[
			[DeckConflictException::class],
			'errorConflict',
		],
		[
			[DeckSessionException::class],
			'errorSessionNotBound',
		],
		[
			['OCA\Deck\NoPermissionException', 'OCP\AppFramework\Db\DoesNotExistException', 'OCP\AppFramework\Db\IMapperException'],
			'errorNotFoundOrForbidden',
		],
		// Before StatusException, which Deck's ConflictException extends; it lives in OCA\Deck\Exceptions.
		[
			['OCA\Deck\Exceptions\ConflictException'],
			'errorConflict',
		],
		[
			['OCA\Deck\StatusException', 'OCA\Deck\ArchivedItemException'],
			'errorNotAllowed',
		],
		[
			['OCA\Deck\BadRequestException'],
			'errorInvalid',
		],
	];

	/**
	 * Resolves the caller-safe message for an exception.
	 *
	 * "Not found" and "no permission" deliberately share one message: the Deck itself answers
	 * `NoPermissionException('Permission denied')` in both cases to avoid leaking existence.
	 *
	 * @param \Throwable $exception Exception raised by the Deck gateway or by the handler.
	 * @return string One of the error messages of {@see DeckMessages}, in the language of the current user.
	 */
	public static function messageFor(\Throwable $exception): string {
		// A refusal of this module carries its own, already translated and Deck-free message.
		if ($exception instanceof DeckRefusalException) {
			return $exception->getMessage();
		}
		if ($exception instanceof DeckUnconfirmedException) {
			return DeckMessages::writeUnconfirmed($exception->readWith);
		}

		foreach (self::MAPPING as [$classes, $method]) {
			foreach ($classes as $class) {
				if (is_a($exception, $class)) {
					return [DeckMessages::class, $method]();
				}
			}
		}

		return DeckMessages::errorGeneric();
	}
}
