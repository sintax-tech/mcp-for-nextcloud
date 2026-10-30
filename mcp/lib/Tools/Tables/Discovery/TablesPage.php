<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tables\Discovery;

/**
 * One page of a complete, ordered list, with how many there are and where the next page starts, so an answer always
 * says whether more remain.
 */
final class TablesPage {
	/** Default page size. */
	public const DEFAULT_LIMIT = 50;

	/** Largest page size. */
	public const MAX_LIMIT = 200;

	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * The page.
	 *
	 * @template T
	 * @param list<T> $items the complete, ordered list
	 * @param int $limit page size
	 * @param int $offset items to skip
	 * @return array{items:list<T>, total:int, hasMore:bool, nextOffset:int|null} the page
	 */
	public static function of(array $items, int $limit, int $offset): array {
		$page = array_slice($items, $offset, $limit);
		$next = $offset + count($page);
		$more = $next < count($items);
		return ['items' => $page, 'total' => count($items), 'hasMore' => $more, 'nextOffset' => $more ? $next : null];
	}
}
