<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Discovery;

use OCA\Mcp\Tools\PlanState;

/** Authenticates format, offset, actor, Forms version, page size and reauthorized list state; not a snapshot. */
final class FormListCursor {
	/** Version of the format. */
	public const FORMAT = 'v1';

	/** Longest cursor accepted. */
	public const MAX_LENGTH = 512;

	/** Largest offset, by default. */
	public const MAX_OFFSET = 100000;

	/**
	 * Receives the MAC and what it binds besides the offset.
	 *
	 * @param PlanState $mac the instance MAC
	 * @param string $tool the tool
	 * @param string $userId authenticated user UID
	 * @param int $maxOffset largest offset made or read
	 * @param array<string, mixed> $bound the Forms version, page size and authorized candidate digest
	 * @return void
	 */
	public function __construct(
		private PlanState $mac,
		private string $tool,
		private string $userId,
		private int $maxOffset,
		private array $bound,
	) {
	}

	/**
	 * The cursor of a page starting at an offset.
	 *
	 * @param int $offset the offset, at most the largest one
	 * @return string the cursor
	 * @throws \LogicException for an offset this cursor would refuse to read
	 */
	public function encode(int $offset): string {
		if ($offset < 0 || $offset > $this->maxOffset) {
			throw new \LogicException('Cursor offset out of range');
		}
		$public = rtrim(strtr(base64_encode(json_encode(['o' => $offset], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
		return self::FORMAT . '.' . $public . '.' . $this->macOf(self::FORMAT, $offset);
	}

	/**
	 * The offset of a cursor, once it is checked.
	 *
	 * @param string|null $cursor the cursor, null for the first page
	 * @return int the offset
	 * @throws \OCA\Mcp\Tools\ToolFailure when the cursor is not one of this query, user and state
	 */
	public function offset(?string $cursor): int {
		if ($cursor === null) {
			return 0;
		}
		$parts = explode('.', $cursor);
		if (strlen($cursor) > self::MAX_LENGTH || count($parts) !== 3 || $parts[0] !== self::FORMAT) {
			throw new \OCA\Mcp\Tools\ToolFailure(\OCA\Mcp\Tools\Forms\FormsMessages::stale());
		}
		$json = base64_decode(strtr($parts[1], '-_', '+/'), true);
		$public = is_string($json) ? json_decode($json, true) : null;
		$offset = is_array($public) && array_keys($public) === ['o'] ? $public['o'] : null;
		if (!is_int($offset) || $offset < 0 || $offset > $this->maxOffset || !hash_equals($this->macOf($parts[0], $offset), $parts[2])) {
			throw new \OCA\Mcp\Tools\ToolFailure(\OCA\Mcp\Tools\Forms\FormsMessages::stale());
		}
		return $offset;
	}

	/**
	 * The MAC of a format version and an offset with everything else bound.
	 *
	 * @param string $format the format version
	 * @param int $offset the offset
	 * @return string the MAC
	 */
	private function macOf(string $format, int $offset): string {
		return $this->mac->of($this->tool, $this->userId, ['format' => $format, 'offset' => $offset] + $this->bound);
	}
}
