<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\Oracle;

/**
 * The only normalization the oracle applies: values that legitimately differ between two runs, or between the v1.2.3
 * and the restructured code without any behaviour change, become stable placeholders before the comparison.
 *
 * A field is volatile only when its KEY is listed in {@see self::KEYS}. Its value becomes `<key#n>`, numbered by first
 * appearance, so two places that showed the same plan_state still show the same placeholder and a changed one shows a
 * different number. Every later occurrence of the same value inside any string (a plan text that quotes its own
 * plan_state or etag) is replaced too, when the value is long enough not to collide with ordinary text. Nothing else
 * is touched: descriptions, messages, order, refusals and every other value are compared literally.
 */
final class VolatileFields {
	/**
	 * Keys whose values are volatile, with the reason. The list is the documented contract of RE-01: adding a key here
	 * weakens the oracle and needs the same review as a golden change.
	 */
	public const KEYS = [
		// HMAC of the shown plan under the instance secret: stable in tests, but a property of the secret, not behaviour.
		'plan_state' => 'plan fingerprint (HMAC under the instance secret)',
		// ETags come from the storage layer (fake or real) and change on every write.
		'etag' => 'storage ETag',
		'ETag' => 'storage ETag',
		// Timestamps generated at the moment of the call.
		'mtime' => 'modification time generated now',
		'created_at' => 'creation time generated now',
		'createdAt' => 'creation time generated now',
		'timestamp' => 'time of the call',
		// Forms stamps the form with time() when the edit lands; the receipt checks it falls inside the write window.
		'lastUpdated' => 'Forms write time, read back after the write',
		// Ids the fakes generate (sequence or random) for an object created by the call.
		'batch_id' => 'id of an undoable batch, random',
		'batchId' => 'id of an undoable batch, random',
	];

	/** Shorter values are replaced only where they are a whole value, never inside text. */
	private const MIN_TEXT_LENGTH = 8;

	/** Static helper only. */
	private function __construct() {
	}

	/**
	 * @param mixed $value decoded JSON (stdClass objects, lists, scalars)
	 * @param array<string, string> $literals extra exact values to replace everywhere, value => placeholder
	 *     (the app version of info.xml, which only a release changes)
	 * @return mixed the same structure with every volatile value replaced
	 */
	public static function normalize(mixed $value, array $literals = []): mixed {
		$seen = [];
		self::collect($value, $seen);
		$map = $literals;
		foreach ($seen as $key => $values) {
			$n = 0;
			foreach (array_keys($values) as $original) {
				$map[(string)$original] ??= '<' . $key . '#' . ++$n . '>';
			}
		}
		return self::replace($value, $map);
	}

	/**
	 * @param mixed $value structure being walked
	 * @param array<string, array<string, true>> $seen volatile values by key, in order of first appearance
	 * @return void
	 */
	private static function collect(mixed $value, array &$seen): void {
		if (!is_array($value) && !$value instanceof \stdClass) {
			return;
		}
		foreach ($value as $key => $child) {
			if (is_string($key) && isset(self::KEYS[$key]) && (is_string($child) || is_int($child))) {
				$seen[$key][(string)$child] = true;
				continue;
			}
			self::collect($child, $seen);
		}
	}

	/**
	 * @param mixed $value structure being rewritten
	 * @param array<string, string> $map original value => placeholder
	 * @return mixed rewritten copy
	 */
	private static function replace(mixed $value, array $map): mixed {
		if ($value instanceof \stdClass) {
			$copy = new \stdClass();
			foreach (get_object_vars($value) as $key => $child) {
				$copy->$key = self::replaceField((string)$key, $child, $map);
			}
			return $copy;
		}
		if (is_array($value)) {
			$copy = [];
			foreach ($value as $key => $child) {
				$copy[$key] = self::replaceField((string)$key, $child, $map);
			}
			return $copy;
		}
		if (is_string($value)) {
			if (isset($map[$value])) {
				return $map[$value];
			}
			$long = array_filter($map, static fn (string $original): bool => strlen($original) >= self::MIN_TEXT_LENGTH, ARRAY_FILTER_USE_KEY);
			return $long === [] ? $value : strtr($value, $long);
		}
		return $value;
	}

	/**
	 * @param string $key key of the field
	 * @param mixed $child its value
	 * @param array<string, string> $map original value => placeholder
	 * @return mixed the rewritten value
	 */
	private static function replaceField(string $key, mixed $child, array $map): mixed {
		if (isset(self::KEYS[$key]) && (is_string($child) || is_int($child))) {
			return $map[(string)$child] ?? $child;
		}
		return self::replace($child, $map);
	}
}
