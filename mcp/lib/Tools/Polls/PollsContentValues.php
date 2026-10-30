<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Polls;

/** Strict native scalars; author text alone is repaired before the digest, never IDs or credentials. */
final class PollsContentValues {
	/**
	 * Reads an exact integer.
	 * @param array<string,mixed> $row native values
	 * @param string $key required field
	 * @param int $minimum allowed lower bound
	 * @return int value
	 * @throws PollsRefusal for unsupported values
	 */
	public static function integer(array $row, string $key, int $minimum = 0): int {
		$value = $row[$key] ?? null;
		if (!is_int($value) || $value < $minimum) {
			throw PollsRefusal::unavailable();
		}
		return $value;
	}

	/**
	 * Reads a string, repairing only author text when requested.
	 * @param array<string,mixed> $row native values
	 * @param string $key required field
	 * @param bool $text whether this is untrusted author text
	 * @return string value
	 * @throws PollsRefusal for malformed structural data
	 */
	public static function string(array $row, string $key, bool $text = false): string {
		$value = $row[$key] ?? null;
		if (!is_string($value) || (!$text && !mb_check_encoding($value, 'UTF-8'))) {
			throw PollsRefusal::unavailable();
		}
		return $text ? mb_scrub($value, 'UTF-8') : $value;
	}

	/**
	 * Checks a native generated URL without following it, resolving a recipient or truncating a credential.
	 * @param string $url native URL
	 * @return string unchanged URL
	 * @throws PollsRefusal for unsupported URLs
	 */
	public static function url(string $url): string {
		$parts = parse_url($url);
		if (!mb_check_encoding($url, 'UTF-8') || strlen($url) > 8192 || preg_match('/[\x00-\x20\x7f]/', $url)
			|| !is_array($parts) || !in_array($parts['scheme'] ?? null, ['http', 'https'], true) || empty($parts['host'])
			|| isset($parts['user']) || isset($parts['pass'])) {
			throw PollsRefusal::unavailable();
		}
		return $url;
	}
}
