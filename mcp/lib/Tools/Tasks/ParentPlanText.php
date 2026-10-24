<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tasks;

use OCA\Mcp\Tools\PlanText;

/**
 * How a plan shows the parent of a task, and whether an edit changes it.
 *
 * The parent always carries its UID, after the title when the caller may read one: two parents can share a title,
 * or read alike once spaces collapse or a long title is cut, and the person must still see that the link moves.
 * Whether the parent changes is decided on the raw UIDs, never on the text shown.
 */
final class ParentPlanText {
	/** Longest title shown; the UID that follows keeps two parents apart whatever the cut. */
	private const TITLE_MAX = 200;

	/** @var array<string, string> titles the plan carries, by parent UID */
	private array $titles = [];

	/**
	 * Keeps the titles of a plan.
	 *
	 * @param mixed $titles raw `parents` value of the plan: title by parent UID
	 * @return void
	 */
	public function __construct(mixed $titles) {
		foreach (is_array($titles) ? $titles : [] as $uid => $title) {
			if (is_string($uid) && is_scalar($title) && trim((string)$title) !== '') {
				$this->titles[$uid] = trim((string)$title);
			}
		}
	}

	/**
	 * The parent as a person reads it.
	 *
	 * @param mixed $value raw plan value of the parent field
	 * @return string|null title and quoted UID, the quoted UID alone, or null for a top-level task
	 */
	public function value(mixed $value): ?string {
		$uid = self::uid($value);
		if ($uid === '') {
			return null;
		}
		$title = $this->titles[$uid] ?? '';
		return $title === '' ? PlanText::quoted($uid) : PlanText::strong($title, self::TITLE_MAX) . ' (' . PlanText::quoted($uid) . ')';
	}

	/**
	 * Whether two parent values are the same link.
	 *
	 * @param mixed $before raw parent before the write
	 * @param mixed $after raw parent after the write
	 * @return bool true when both name the same UID, or both none
	 */
	public static function same(mixed $before, mixed $after): bool {
		return self::uid($before) === self::uid($after);
	}

	/**
	 * A raw parent value as its UID.
	 *
	 * @param mixed $value raw plan value
	 * @return string the UID, '' for none
	 */
	private static function uid(mixed $value): string {
		return is_scalar($value) ? trim((string)$value) : '';
	}
}
