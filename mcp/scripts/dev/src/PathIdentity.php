<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Dev;

/** Matches paired paths and lib-relative inventory identities without proving arbitrary filesystem prefixes. */
final class PathIdentity {
	/**
	 * Splits a matching literal into prefix, exact inventory suffix and lib-relative flag.
	 * Full app paths take precedence over lib-relative aliases, avoiding a spurious `lib/` prefix.
	 *
	 * @param string $value literal path or path#identity/path:identity
	 * @param string $old paired path relative to the app
	 * @return array{string,string,bool}|null prefix, suffix and whether lib/ was omitted
	 */
	public static function match(string $value, string $old): ?array {
		if (!str_contains($value, basename($old))) {
			return null;
		}
		$length = strcspn($value, '#:');
		$base = substr($value, 0, $length);
		$suffix = substr($value, $length);
		$variants = [[$old, false]];
		if (str_starts_with($old, 'lib/')) {
			$variants[] = [substr($old, 4), true];
		}
		foreach ($variants as [$candidate, $relative]) {
			if ($base === $candidate || str_ends_with($base, '/' . $candidate)) {
				return [substr($base, 0, strlen($base) - strlen($candidate)), $suffix, $relative];
			}
		}
		return null;
	}

	/**
	 * Accepts app roots and the explicit repository-relative mcp/ prefix for full app paths.
	 *
	 * @param array{string,string,bool} $match a matched identity
	 * @return bool true for an app root, or mcp/ before a full app-relative path
	 */
	public static function proved(array $match): bool {
		return in_array($match[0], ['', '/'], true) || ($match[0] === 'mcp/' && !$match[2]);
	}
	/**
	 * Recognizes the destination of a pair whose folder nests the old lib-relative path: `lib/Tools/X.php` moved to
	 * `lib/Mcp/Tools/X.php` ends with `/Tools/X.php`, so the old identity matches it with the unproved prefix
	 * `lib/Mcp/`. A literal that is exactly a proved identity of the destination names the destination, not the old file.
	 *
	 * @param string $value literal path or path#identity/path:identity
	 * @param string $old paired path relative to the app
	 * @param string $new destination of the pair, relative to the app
	 * @return bool true when the literal is a proved identity of the destination
	 */
	public static function namesDestination(string $value, string $old, string $new): bool {
		$destination = $old === $new ? null : self::match($value, $new);
		return $destination !== null && self::proved($destination);
	}

	/**
	 * Recognizes basename-relative paths that cannot be associated with a proved app/lib root.
	 *
	 * @param string $value literal value
	 * @param string $old paired file
	 * @return bool whether a matching basename has an unproved location
	 */
	public static function unproved(string $value, string $old): bool {
		if (!str_contains($value, basename($old)) || self::match($value, $old) !== null) {
			return false;
		}
		$base = substr($value, 0, strcspn($value, '#:'));
		return $base === basename($old) || str_ends_with($base, '/' . basename($old));
	}

}
