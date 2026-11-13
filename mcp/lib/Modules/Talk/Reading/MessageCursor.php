<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Reading;

use InvalidArgumentException;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Talk\Presentation\PageMessages;

/**
 * Continuation of one read of unread messages, signed like a plan state and bound to the tool, the actor, the
 * conversation and the position.
 *
 * The position is the id of the last message already returned, not the read marker. Another client that moves the
 * marker while this read is paging cannot make the next page skip or repeat a message. The cursor also carries the
 * marker the read started from, so every page reports the same starting point.
 */
final class MessageCursor {
	/** Longest room token Talk publishes. */
	private const MAX_TOKEN_LENGTH = 30;

	/**
	 * @param PlanState $mac HMAC using the instance secret
	 */
	public function __construct(private PlanState $mac) {
	}

	/**
	 * The position this cursor continues after, or null when the read is starting.
	 *
	 * @param string|null $cursor opaque cursor, or null to start at the read marker
	 * @param string $tool exact tool name
	 * @param string $uid authenticated reader
	 * @param string $token conversation the caller is reading
	 * @return array{message: int, baseline: int}|null last message returned and the marker the read started from
	 * @throws InvalidArgumentException when the cursor was not issued by this tool, for this reader and conversation
	 */
	public function position(?string $cursor, string $tool, string $uid, string $token): ?array {
		if ($cursor === null) {
			return null;
		}
		[$value, $mac] = CursorCodec::unpack($cursor) ?? [null, ''];
		$room = is_array($value) ? ($value['t'] ?? null) : null;
		$message = is_array($value) ? ($value['m'] ?? null) : null;
		$baseline = is_array($value) ? ($value['b'] ?? null) : null;
		if (!is_array($value) || array_keys($value) !== ['t', 'm', 'b']
			|| !is_string($room) || $room === '' || strlen($room) > self::MAX_TOKEN_LENGTH || $room !== $token
			|| !is_int($message) || $message < 1 || !is_int($baseline) || $baseline < 0 || $baseline > $message
			|| !hash_equals($this->sign($room, $message, $baseline, $tool, $uid), $mac)) {
			throw new InvalidArgumentException(PageMessages::invalidCursor());
		}
		return ['message' => $message, 'baseline' => $baseline];
	}

	/**
	 * The cursor that continues after this message for this reader and conversation.
	 *
	 * @param string $token conversation token
	 * @param int $message id of the last message of this page
	 * @param int $baseline read marker the read started from
	 * @param string $tool exact tool name
	 * @param string $uid authenticated reader
	 * @return string signed cursor
	 */
	public function next(string $token, int $message, int $baseline, string $tool, string $uid): string {
		return CursorCodec::pack(['t' => $token, 'm' => $message, 'b' => $baseline], $this->sign($token, $message, $baseline, $tool, $uid));
	}

	/**
	 * The MAC covers the conversation, the position, the starting marker, the format, the tool and the actor.
	 *
	 * @param string $token conversation token
	 * @param int $message last message returned
	 * @param int $baseline starting marker
	 * @param string $tool tool
	 * @param string $uid actor
	 * @return string instance MAC
	 */
	private function sign(string $token, int $message, int $baseline, string $tool, string $uid): string {
		return $this->mac->of($tool, $uid, ['format' => 'v1', 'token' => $token, 'message' => $message, 'baseline' => $baseline]);
	}
}
