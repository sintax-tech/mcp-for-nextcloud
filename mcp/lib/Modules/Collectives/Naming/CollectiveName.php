<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Naming;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Presentation\FoundingMessages;

/**
 * The name and emoji of a new collective, exactly as Collectives and Teams will store them.
 *
 * Collectives sanitizes the name (`NodeHelper::sanitiseFilename`, `:75-102` in every pinned release) and Teams cleans
 * it again (`CircleService::cleanCircleName`, 33/34 `:702-705`, 35 `:754-757`). Applied to the name the plan shows,
 * both must leave it unchanged, so what the plan signs is what is stored. Deliberate differences, all refusals instead
 * of silent changes: control characters (a dot followed by a tab would lose the dot only on a second pass), a name
 * that sanitizes to nothing (Collectives would name it "New File"), and four-byte characters the database cannot keep
 * (Collectives would drop them). Teams accepts 3 to 127 bytes (35 `:148-152`); the same limit is checked here.
 *
 * The emoji must be one pictographic character, the rule of Collectives 4.5.0 (`EmojiHelper.php`), applied on every
 * release: 4.0.0 and 4.4.0 check nothing, and 4.7.1 also accepts flags and keycaps, which 4.5.0 would refuse only
 * after the team exists.
 */
final class CollectiveName {
	/** Shortest name Teams accepts, in bytes. */
	public const MIN_BYTES = 3;
	/** Longest name Teams accepts, in bytes. */
	public const MAX_BYTES = 127;
	/** Longest emoji Collectives accepts, in characters. */
	public const MAX_EMOJI = 8;
	/** A pictographic character, what every release that checks the emoji accepts. */
	private const EMOJI = '/\p{Extended_Pictographic}/u';

	/** Static helper only; never instantiated. */
	private function __construct() {
	}

	/**
	 * @param string $name the name as given
	 * @param bool $fourBytes whether the database keeps four-byte characters
	 * @return string the name Collectives and Teams will store, unchanged by either
	 * @throws ToolFailure when the name would change on the way, is empty, too short or too long
	 */
	public static function normalize(string $name, bool $fourBytes): string {
		if (!mb_check_encoding($name, 'UTF-8') || preg_match('/\p{Cc}/u', $name) === 1) {
			throw new ToolFailure(FoundingMessages::nameControlCharacters());
		}
		if (!$fourBytes && preg_match('/[\x{10000}-\x{10FFFF}]/u', $name) === 1) {
			throw new ToolFailure(FoundingMessages::nameFourBytes());
		}
		$stored = self::clean(self::sanitize($name));
		if ($stored === '') {
			throw new ToolFailure(FoundingMessages::nameEmpty());
		}
		if (self::sanitize($stored) !== $stored || self::clean($stored) !== $stored) {
			throw new ToolFailure(FoundingMessages::nameUnstable($stored));
		}
		if (strlen($stored) < self::MIN_BYTES || strlen($stored) > self::MAX_BYTES) {
			throw new ToolFailure(FoundingMessages::nameLength(self::MIN_BYTES, self::MAX_BYTES));
		}
		return $stored;
	}

	/**
	 * @param string|null $emoji the emoji as given, null or empty for none
	 * @return string|null the emoji, or null for none
	 * @throws ToolFailure when it is not one emoji every pinned release accepts, or cannot be checked
	 */
	public static function emoji(?string $emoji): ?string {
		if ($emoji === null || $emoji === '') {
			return null;
		}
		if (!mb_check_encoding($emoji, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/u', $emoji) === 1
			|| !function_exists('grapheme_strlen') || grapheme_strlen($emoji) !== 1 || mb_strlen($emoji) > self::MAX_EMOJI
			|| (@preg_match('/\p{Extended_Pictographic}/u', '😀') === 1 && preg_match(self::EMOJI, $emoji) !== 1)) {
			throw new ToolFailure(FoundingMessages::invalidEmoji());
		}
		return $emoji;
	}

	/**
	 * @param string $name a name
	 * @return string what `NodeHelper::sanitiseFilename` makes of it, without the four-byte step and the default name
	 */
	public static function sanitize(string $name): string {
		$name = str_replace('/', '-', $name);
		$name = str_replace(['*', '|', '\\', ':', '"', '<', '>', '?'], '', $name);
		return trim($name, ' .');
	}

	/**
	 * @param string $name a name
	 * @return string what `CircleService::cleanCircleName` makes of it
	 */
	public static function clean(string $name): string {
		return trim((string)preg_replace('/\s+/', ' ', $name));
	}
}
