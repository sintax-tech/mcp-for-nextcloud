<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 Roman Matkivskyy <roman91@live.it>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Labels;

use OCA\Mcp\Modules\Deck\Structure\BoardBlueprint;

/**
 * Titles and colours of board labels, and how a title sent by the model finds a label of the board.
 *
 * Deck checks title uniqueness through its native mapper. This helper finds exact matches first and also identifies
 * possible case-only collisions. Card creation resolves only an exact title or explicit ID; a case-only alternative
 * is refused rather than inventing an identity. Creation conservatively refuses any case-only collision.
 */
final class LabelInput {
	/** Deck's own limit for a label title (LabelServiceValidator max:100). */
	public const MAX_TITLE_LENGTH = 100;

	/** Labels a card may receive in one deck_create_card call. */
	public const MAX_CARD_LABELS = 20;

	/** Colours given to a new label when none is sent: Deck's four default label colours, then four more. */
	public const PALETTE = ['31cc7c', '317ccc', 'ff7a66', 'f1db50', '7c31cc', 'cc317c', '3794ac', '8c8c8c'];

	/**
	 * @param string $raw Title as sent.
	 * @param string $field Argument named by a validation error.
	 * @return string Trimmed title of 1 to 100 characters.
	 * @throws \OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException When it is empty or too long.
	 */
	public static function title(string $raw, string $field = 'title'): string {
		return BoardBlueprint::title($raw, $field);
	}

	/**
	 * @param mixed $raw Colour as sent, six hexadecimal digits with or without `#`, or null.
	 * @return string|null The six digits in lower case, or null when no colour was sent.
	 * @throws \OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException When it is not six hexadecimal digits.
	 */
	public static function color(mixed $raw): ?string {
		return $raw === null ? null : BoardBlueprint::color((string)$raw);
	}

	/**
	 * The same title always gets the same colour, so labels created on different boards look alike.
	 *
	 * @param string $title Validated title.
	 * @return string One colour of {@see self::PALETTE}.
	 */
	public static function defaultColor(string $title): string {
		return self::PALETTE[crc32(mb_strtolower($title)) % count(self::PALETTE)];
	}

	/**
	 * @param list<array{id: int, title: string}> $labels Catalog of one board.
	 * @param string $title Validated title.
	 * @param int|null $except Label to leave out, the one being renamed.
	 * @return list<array{id: int, title: string}> The exact match alone, or else every case-insensitive match.
	 */
	public static function matches(array $labels, string $title, ?int $except = null): array {
		$others = array_values(array_filter($labels, static fn (array $label): bool => $label['id'] !== $except));
		foreach ($others as $label) {
			if ($label['title'] === $title) {
				return [$label];
			}
		}
		$key = mb_strtolower($title);
		return array_values(array_filter($others, static fn (array $label): bool => mb_strtolower($label['title']) === $key));
	}
}
