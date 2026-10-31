<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * The opaque page cursor of the message tools: the stored date every message of the next page is older than, bound to
 * the fingerprint of the filters and folders it was issued for.
 *
 * The Mail cache pages by date only (`sent_at < cursor`), so a cursor never stands between two messages of the same
 * date: {@see MailMessagePager} always ends a page after the last message of a date. The fingerprint makes a cursor
 * of one search refuse to continue another, where the boundary would silently skip or repeat messages.
 */
final class MailCursor {
	/** Version of the cursor format. */
	private const VERSION = 1;

	/** Longest cursor accepted, in characters. */
	public const MAX_LENGTH = 200;

	/**
	 * Holds a decoded cursor.
	 *
	 * @param int $before every message of the next page was sent strictly before this Unix time
	 * @param string $fingerprint fingerprint of the filters and folders the cursor belongs to
	 * @return void
	 */
	public function __construct(
		public readonly int $before,
		public readonly string $fingerprint,
	) {
	}

	/**
	 * The cursor as the tools hand it out: URL-safe base64 of a small JSON object.
	 *
	 * @return string the opaque cursor
	 */
	public function encode(): string {
		$json = json_encode(['v' => self::VERSION, 'b' => $this->before, 'f' => $this->fingerprint], JSON_THROW_ON_ERROR);
		return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
	}

	/**
	 * Reads a cursor the caller sent back, for the search it must belong to.
	 *
	 * @param string $cursor the opaque cursor from nextCursor
	 * @param string $fingerprint fingerprint of the current filters and folders
	 * @return self the decoded cursor
	 * @throws ToolFailure when the cursor is malformed or was issued for other filters or folders
	 */
	public static function decode(string $cursor, string $fingerprint): self {
		$raw = strlen($cursor) <= self::MAX_LENGTH ? base64_decode(strtr($cursor, '-_', '+/'), true) : false;
		$data = is_string($raw) ? json_decode($raw, true) : null;
		if (!is_array($data) || ($data['v'] ?? null) !== self::VERSION || !is_int($data['b'] ?? null) || !is_string($data['f'] ?? null)) {
			throw new ToolFailure(MailMessages::invalidCursor());
		}
		if (!hash_equals($fingerprint, $data['f'])) {
			throw new ToolFailure(MailMessages::foreignCursor());
		}
		return new self($data['b'], $data['f']);
	}
}
