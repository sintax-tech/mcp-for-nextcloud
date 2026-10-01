<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\ArgumentValidationException;

/**
 * Reads the arguments of `deck_create_board` into the structure that is planned and then written.
 *
 * The plan and the execution both go through {@see self::parse()}, so what the user approves is exactly what
 * gets built, and everything that can be refused is refused before the first write. The registry has already
 * checked types and lengths against the schema; what is left here is what the schema cannot say: titles that
 * are blank once trimmed, a real calendar date, a colour, the ceiling on the whole call and who may be assigned
 * on a board that does not exist yet.
 */
final class BoardBlueprint {
	/** Colour Deck shows a board in when none is asked for (the Nextcloud blue), six digits without `#`. */
	public const DEFAULT_COLOR = '0082c9';

	/** Lists a single call may create. */
	public const MAX_STACKS = 20;

	/** Cards, in all lists together, a single call may create. */
	public const MAX_CARDS = 100;

	/** Longest board or list title Deck accepts. */
	public const MAX_TITLE_LENGTH = 100;

	private function __construct() {
	}

	/**
	 * @param array<string, mixed> $arguments Arguments of `deck_create_board`, already passed against the schema.
	 * @param string $userId UID of the caller, the owner of the board and the only account with access to it.
	 * @return array{title: string, color: string, colorGiven: bool, stacks: list<array{title: string, cards: list<array{title: string, description: string, duedate: string|null, assignees: list<string>}>}>, cardCount: int}
	 * @throws ArgumentValidationException Naming the field when anything of the structure is refused.
	 */
	public static function parse(array $arguments, string $userId): array {
		$title = self::title((string)($arguments['title'] ?? ''), 'title');
		$colorGiven = array_key_exists('color', $arguments) && $arguments['color'] !== null;
		$color = $colorGiven ? self::color((string)$arguments['color']) : self::DEFAULT_COLOR;

		$rawStacks = $arguments['stacks'] ?? [];
		if (!is_array($rawStacks) || !array_is_list($rawStacks) || count($rawStacks) > self::MAX_STACKS) {
			throw new ArgumentValidationException('Invalid argument: stacks', 'stacks',
				Translator::t('expected a list of at most %s lists', [self::MAX_STACKS]));
		}

		$stacks = [];
		$cardCount = 0;
		foreach ($rawStacks as $s => $rawStack) {
			$path = 'stacks[' . $s . ']';
			$cards = [];
			foreach (is_array($rawStack['cards'] ?? null) ? $rawStack['cards'] : [] as $c => $rawCard) {
				$cards[] = self::card($rawCard, $path . '.cards[' . $c . ']', $userId);
			}
			$cardCount += count($cards);
			$stacks[] = ['title' => self::title((string)($rawStack['title'] ?? ''), $path . '.title'), 'cards' => $cards];
		}

		if ($cardCount > self::MAX_CARDS) {
			throw new ArgumentValidationException('Invalid argument: stacks', 'stacks',
				Translator::t('at most %s cards in total', [self::MAX_CARDS]));
		}

		return ['title' => $title, 'color' => $color, 'colorGiven' => $colorGiven, 'stacks' => $stacks, 'cardCount' => $cardCount];
	}

	/**
	 * @param mixed $raw One element of `cards`.
	 * @param string $path Path of that element, named in a refusal.
	 * @param string $userId UID of the caller.
	 * @return array{title: string, description: string, duedate: string|null, assignees: list<string>}
	 * @throws ArgumentValidationException When any field of the card is refused.
	 */
	private static function card(mixed $raw, string $path, string $userId): array {
		$raw = is_array($raw) ? $raw : [];
		$title = trim((string)($raw['title'] ?? ''));
		if ($title === '' || mb_strlen($title) > 255) {
			throw new ArgumentValidationException('Invalid argument: ' . $path . '.title', $path . '.title',
				Translator::t('expected a title of 1 to 255 characters'));
		}
		$description = (string)($raw['description'] ?? '');
		if (mb_strlen($description) > CardInput::MAX_DESCRIPTION_LENGTH) {
			throw new ArgumentValidationException('Invalid argument: ' . $path . '.description', $path . '.description',
				Translator::t('maximum length %s', [CardInput::MAX_DESCRIPTION_LENGTH]));
		}
		try {
			$duedate = CardInput::duedate($raw['duedate'] ?? null);
		} catch (\InvalidArgumentException) {
			throw new ArgumentValidationException('Invalid argument: ' . $path . '.duedate', $path . '.duedate',
				Translator::t('expected a valid date in YYYY-MM-DD format'));
		}
		$assignees = CardInput::assignees($raw['assignees'] ?? [], $path . '.assignees');
		foreach ($assignees as $uid) {
			// A board that does not exist yet is open to its owner alone, so only the owner can be responsible
			// for a card on it; anybody else has to be made a member first, and that needs the board.
			if ($uid !== $userId) {
				throw new ArgumentValidationException('Invalid argument: ' . $path . '.assignees', $path . '.assignees',
					Translator::t('a new board is open only to you; share the board first, then assign other accounts with deck_edit_card'));
			}
		}

		return ['title' => $title, 'description' => $description, 'duedate' => $duedate, 'assignees' => $assignees];
	}

	/**
	 * Validates a board or list title, which Deck limits to {@see self::MAX_TITLE_LENGTH} characters.
	 *
	 * @param string $raw Title as sent.
	 * @param string $field Field named in a refusal.
	 * @return string The title, trimmed.
	 * @throws ArgumentValidationException When it is blank once trimmed or longer than Deck accepts.
	 */
	public static function title(string $raw, string $field): string {
		$title = trim($raw);
		if ($title === '' || mb_strlen($title) > self::MAX_TITLE_LENGTH) {
			throw new ArgumentValidationException('Invalid argument: ' . $field, $field,
				Translator::t('expected a title of 1 to %s characters', [self::MAX_TITLE_LENGTH]));
		}

		return $title;
	}

	/**
	 * @param string $raw Colour as sent, six hexadecimal digits with or without `#`.
	 * @return string The six digits in lower case, as Deck stores them.
	 * @throws ArgumentValidationException When it is not six hexadecimal digits.
	 */
	private static function color(string $raw): string {
		$color = ltrim($raw, '#');
		if (preg_match('/^[0-9a-fA-F]{6}$/', $color) !== 1 || substr_count($raw, '#') > 1) {
			throw new ArgumentValidationException('Invalid argument: color', 'color',
				Translator::t('expected six hexadecimal digits, for example 0082c9'));
		}

		return strtolower($color);
	}
}
