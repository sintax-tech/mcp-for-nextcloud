<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Polls;

use OCA\Mcp\Mcp\Confirmation\PlanState;

/** RowCursor's MAC format, with Polls-specific safe refusal and symmetric emission/consumption bounds. */
final class PollsCursor {
	/** Largest public offset in either direction. */
	public const MAX_OFFSET = 100000;
	/**
	 * Receives the instance MAC.
	 * @param PlanState $mac HMAC using the instance secret
	 * @return void
	 */
	public function __construct(private PlanState $mac) {
	}

	/**
	 * Checks format, closed public payload, actor, query/state and offset before paging.
	 * @param string|null $cursor supplied cursor
	 * @param string $tool exact tool
	 * @param string $uid authenticated actor
	 * @param array<string,mixed> $bound version, scope, limit, order and digest
	 * @return int verified offset
	 * @throws PollsRefusal for any mismatch
	 */
	public function offset(?string $cursor, string $tool, string $uid, array $bound): int {
		if ($cursor === null) {
			return 0;
		}
		$parts = explode('.', $cursor);
		if (strlen($cursor) > 512 || count($parts) !== 3 || $parts[0] !== 'v1') {
			throw PollsRefusal::changed();
		}
		$raw = base64_decode(strtr($parts[1], '-_', '+/'), true);
		$value = is_string($raw) ? json_decode($raw, true) : null;
		$offset = is_array($value) && array_keys($value) === ['o'] ? $value['o'] : null;
		if (!is_int($offset) || $offset < 0 || $offset > self::MAX_OFFSET || !hash_equals($this->sign($offset, $tool, $uid, $bound), $parts[2])) {
			throw PollsRefusal::changed();
		}
		return $offset;
	}

	/**
	 * Produces the next cursor only if its consumer accepts that offset.
	 * @param int $offset next offset
	 * @param string $tool exact tool
	 * @param string $uid actor
	 * @param array<string,mixed> $bound current scope/state
	 * @return string|null null when the offset ceiling is reached
	 */
	public function next(int $offset, string $tool, string $uid, array $bound): ?string {
		if ($offset < 0 || $offset > self::MAX_OFFSET) {
			return null;
		}
		$public = rtrim(strtr(base64_encode(json_encode(['o' => $offset], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
		return 'v1.' . $public . '.' . $this->sign($offset, $tool, $uid, $bound);
	}

	/**
	 * The MAC includes the offset and format themselves, not just the hidden scope.
	 * @param int $offset public offset
	 * @param string $tool tool
	 * @param string $uid actor
	 * @param array<string,mixed> $bound scope/state
	 * @return string instance MAC
	 */
	private function sign(int $offset, string $tool, string $uid, array $bound): string {
		return $this->mac->of($tool, $uid, ['format' => 'v1', 'offset' => $offset, 'scope' => $bound]);
	}
}
