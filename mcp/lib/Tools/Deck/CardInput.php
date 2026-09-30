<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck;

use InvalidArgumentException;

/**
 * Semantic validation of the card fields the Deck tools accept.
 *
 * The registry already checks types, required keys, lengths and `additionalProperties`; what is
 * left here is what JSON Schema cannot express, such as "is this string a real calendar date".
 */
final class CardInput {
	/** A description longer than this is refused instead of silently truncated. */
	public const MAX_DESCRIPTION_LENGTH = 100000;

	/** Format accepted for `duedate`, matching the Deck `duedate` column. */
	private const DATE_FORMAT = 'Y-m-d';

	/**
	 * Validates a card title.
	 *
	 * @param string $title Raw title as sent by the client.
	 * @return string The title, trimmed.
	 * @throws InvalidArgumentException When the title is empty once trimmed.
	 */
	public static function requireTitle(string $title): string {
		$trimmed = trim($title);
		if ($trimmed === '') {
			throw new InvalidArgumentException(DeckMessages::ERROR_TITLE_REQUIRED);
		}

		return $trimmed;
	}

	/**
	 * Validates a card description.
	 *
	 * @param string $description Raw description as sent by the client.
	 * @return string The description, unchanged.
	 * @throws InvalidArgumentException When it is longer than {@see self::MAX_DESCRIPTION_LENGTH}.
	 */
	public static function requireDescription(string $description): string {
		if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
			throw new InvalidArgumentException(DeckMessages::ERROR_DESCRIPTION_TOO_LONG);
		}

		return $description;
	}

	/**
	 * Validates a due date.
	 *
	 * @param mixed $value Raw value: a `YYYY-MM-DD` string or null to clear the date.
	 * @return string|null The date, or null when there is none.
	 * @throws InvalidArgumentException When the string is not a real calendar date.
	 */
	public static function duedate(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		$date = \DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, (string)$value);
		if ($date === false || $date->format(self::DATE_FORMAT) !== (string)$value) {
			throw new InvalidArgumentException(DeckMessages::ERROR_INVALID_DUEDATE);
		}

		return (string)$value;
	}
}
