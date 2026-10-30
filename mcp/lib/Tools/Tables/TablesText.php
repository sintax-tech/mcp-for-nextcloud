<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tables;

/**
 * Text the users of the Tables app wrote (titles, descriptions, column names, options), made safe to return: valid UTF-8,
 * without control or direction-override characters, cut to a length. It stays untrusted data; the answers carry
 * {@see TablesMessages::UNTRUSTED_NOTICE}.
 */
final class TablesText {
	/** Most characters kept of one value. */
	public const MAX_TEXT = 2000;

	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * The cleaned text.
	 *
	 * @param mixed $value text from the Tables app, or anything else (null becomes '')
	 * @return string the cleaned text
	 */
	public static function clean(mixed $value): string {
		$text = mb_scrub(is_scalar($value) ? (string)$value : '', 'UTF-8');
		$text = (string)preg_replace('/[\p{Cc}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text);
		return mb_substr($text, 0, self::MAX_TEXT);
	}
}
