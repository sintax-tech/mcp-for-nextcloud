<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Reading;

use InvalidArgumentException;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Talk\Presentation\Messages;

/**
 * Continuation of one unread page, signed like a plan state and bound to the tool, the actor and the position.
 *
 * The signature is an HMAC of the published position. A cursor that was altered, or that was issued for another
 * account, does not match and is refused. The position is the last row of the previous page, not a frozen mailbox:
 * a conversation whose activity changes can move, and the next page only continues after that row.
 */
final class UnreadCursor {
	/** Longest cursor the schema and the decoder accept, the same bound a signed polls cursor uses. */
	public const MAX_LENGTH = 512;

	/** Longest room token Talk publishes. */
	private const MAX_TOKEN_LENGTH = 30;

	/**
	 * @param PlanState $mac HMAC using the instance secret
	 */
	public function __construct(private PlanState $mac) {
	}

	/**
	 * The position this cursor continues after, or null when the caller is starting.
	 *
	 * @param string|null $cursor opaque cursor, or null to start at the first row
	 * @param string $tool exact tool name
	 * @param string $uid authenticated reader
	 * @return array{activity: int, token: string}|null verified position
	 * @throws InvalidArgumentException when the cursor is not one this tool issued for this reader
	 */
	public function position(?string $cursor, string $tool, string $uid): ?array {
		if ($cursor === null) {
			return null;
		}
		$parts = explode('.', $cursor);
		if ($cursor === '' || strlen($cursor) > self::MAX_LENGTH || count($parts) !== 3 || $parts[0] !== 'v1') {
			throw new InvalidArgumentException(Messages::invalidCursor());
		}
		$raw = base64_decode(strtr($parts[1], '-_', '+/'), true);
		$value = is_string($raw) ? json_decode($raw, true) : null;
		$activity = is_array($value) ? ($value['a'] ?? null) : null;
		$token = is_array($value) ? ($value['t'] ?? null) : null;
		if (!is_array($value) || array_keys($value) !== ['a', 't'] || !is_int($activity) || $activity < 0
			|| !is_string($token) || $token === '' || strlen($token) > self::MAX_TOKEN_LENGTH
			|| !hash_equals($this->sign($activity, $token, $tool, $uid), $parts[2])) {
			throw new InvalidArgumentException(Messages::invalidCursor());
		}
		return ['activity' => $activity, 'token' => $token];
	}

	/**
	 * The cursor that continues after this row for this reader.
	 *
	 * @param int $activity last activity of the last row of this page, or 0 when the room has none
	 * @param string $token token of that row
	 * @param string $tool exact tool name
	 * @param string $uid authenticated reader
	 * @return string signed cursor
	 */
	public function next(int $activity, string $token, string $tool, string $uid): string {
		$public = rtrim(strtr(base64_encode(json_encode(['a' => $activity, 't' => $token], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
		return 'v1.' . $public . '.' . $this->sign($activity, $token, $tool, $uid);
	}

	/**
	 * The MAC covers the position, the format, the tool and the actor.
	 *
	 * @param int $activity published activity
	 * @param string $token published token
	 * @param string $tool tool
	 * @param string $uid actor
	 * @return string instance MAC
	 */
	private function sign(int $activity, string $token, string $tool, string $uid): string {
		return $this->mac->of($tool, $uid, ['format' => 'v1', 'activity' => $activity, 'token' => $token]);
	}
}
