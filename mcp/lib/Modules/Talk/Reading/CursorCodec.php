<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Reading;

/**
 * The opaque shape shared by the signed Talk cursors: `v1.<base64url JSON position>.<MAC>`.
 *
 * Only the envelope lives here. Each cursor checks its own fields and its own MAC, so a position one tool issued is
 * never accepted by another.
 */
final class CursorCodec {
	/** Longest cursor the schemas and the decoder accept, the same bound a signed polls cursor uses. */
	public const MAX_LENGTH = 512;

	/** Static envelope utility only. */
	private function __construct() {
	}

	/**
	 * Splits a cursor into its published position and its MAC, without trusting either yet.
	 *
	 * @param string $cursor opaque cursor received from the caller
	 * @return array{0: array<mixed>, 1: string}|null the decoded position and the MAC, or null when the shape is wrong
	 */
	public static function unpack(string $cursor): ?array {
		$parts = explode('.', $cursor);
		if ($cursor === '' || strlen($cursor) > self::MAX_LENGTH || count($parts) !== 3 || $parts[0] !== 'v1') {
			return null;
		}
		$raw = base64_decode(strtr($parts[1], '-_', '+/'), true);
		$value = is_string($raw) ? json_decode($raw, true) : null;
		return is_array($value) ? [$value, $parts[2]] : null;
	}

	/**
	 * Wraps a position and its MAC into the published cursor.
	 *
	 * @param array<string, int|string> $position public position, in the key order the matching decoder expects
	 * @param string $mac signature of that position
	 * @return string opaque cursor
	 */
	public static function pack(array $position, string $mac): string {
		$public = rtrim(strtr(base64_encode(json_encode($position, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
		return 'v1.' . $public . '.' . $mac;
	}
}
