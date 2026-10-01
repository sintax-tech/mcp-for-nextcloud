<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\ArgumentValidationException;
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
	 * Validates account IDs without echoing their values on failure.
	 * @param mixed $value Requested account ID list.
	 * @return list<string> Unique account IDs.
	 * @throws InvalidArgumentException When the list or an account ID is malformed.
	 */
	public static function assignees(mixed $value): array {
		if (!is_array($value) || !array_is_list($value) || count($value) > 100) {
			throw new ArgumentValidationException('Invalid argument: assignees', 'assignees',
				Translator::t('expected a list of at most 100 account IDs'));
		}
		foreach ($value as $uid) {
			if (!is_string($uid) || trim($uid) === '' || mb_strlen($uid) > 255) {
				throw new ArgumentValidationException('Invalid argument: assignees', 'assignees',
					Translator::t('expected non-empty account IDs of at most 255 characters'));
			}
		}
		return array_values(array_unique($value));
	}

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
			throw new InvalidArgumentException(DeckMessages::errorTitleRequired());
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
			throw new InvalidArgumentException(DeckMessages::errorDescriptionTooLong());
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
		return self::date($value, DeckMessages::errorInvalidDuedate());
	}

	/**
	 * Validates the upper due-date bound of the follow-up.
	 *
	 * @param mixed $value Raw value: a `YYYY-MM-DD` string, or null for no bound.
	 * @return string|null The date, or null when there is no bound.
	 * @throws InvalidArgumentException When the string is not a real calendar date.
	 */
	public static function dueBefore(mixed $value): ?string {
		return self::date($value, DeckMessages::errorInvalidDueBefore());
	}

	/**
	 * @param mixed $value Raw value: a `YYYY-MM-DD` string, or null for none.
	 * @param string $error Message the rejection carries, chosen by the caller.
	 * @return string|null The date, or null when there is none.
	 * @throws InvalidArgumentException When the string is not a real calendar date.
	 */
	private static function date(mixed $value, string $error): ?string {
		if ($value === null) {
			return null;
		}

		$date = \DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, (string)$value);
		if ($date === false || $date->format(self::DATE_FORMAT) !== (string)$value) {
			throw new InvalidArgumentException($error);
		}

		return (string)$value;
	}
}
