<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Reading;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Locating\PageTreeLoader;
use OCA\Mcp\Modules\Collectives\Integration\ContentSearch;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesMessages;

/**
 * Backs `collectives_search`. A title search matches the admitted tree the way Collectives does (case-insensitive
 * substring) without its cached `findAll`. A content search asks the Collectives index for at most
 * {@see self::BUDGET} results, then keeps only the ids of admitted pages, so a hit never names a page the actor may
 * not see; the answer says the index may lag and when the native answer may have stopped at the budget.
 */
class SearchPagesHandler {
	/** Most native content results asked for; the listing never pages past them. */
	public const BUDGET = 100;
	/** Tool name, part of the cursor signature. */
	private const TOOL = 'collectives_search';

	/**
	 * @param PageTreeLoader $trees the page tree, read now
	 * @param ContentSearch $content the native content search, either proven shape
	 * @param PageCursor $cursors listing cursors
	 */
	public function __construct(
		private PageTreeLoader $trees,
		private ContentSearch $content,
		private PageCursor $cursors,
	) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @param array<string, mixed> $arguments validated collectives_search arguments
	 * @return array<string, mixed> one slice of the hits, the next cursor and the notices of a content search
	 * @throws ToolFailure when the collective is unknown, the call is not admitted, the cursor is not valid or the
	 *   version has no proven content search
	 */
	public function handle(string $uid, array $arguments): array {
		$slice = $this->cursors->slice($arguments, $uid, self::TOOL);
		$tree = $this->trees->load((int)$arguments['collective_id'], $uid, 'read');
		$query = (string)$arguments['query'];
		$notices = [];
		if (($arguments['scope'] ?? 'title') === 'content') {
			$found = $this->content->search($tree->collectiveId, $query, $uid, self::BUDGET);
			$hits = array_values(array_filter($found['ids'], static fn (int $id): bool => $tree->has($id)));
			$notices[] = CollectivesMessages::searchIndexNotice();
			if ($found['capped']) {
				$notices[] = CollectivesMessages::searchCapped(self::BUDGET);
			}
		} else {
			$hits = array_map(static fn (array $page): int => (int)$page['id'], array_values(array_filter($tree->ordered(),
				static fn (array $page): bool => stripos((string)$page['title'], $query) !== false)));
		}
		$page = $this->cursors->page(array_map(static fn (int $id): array => $tree->visible($tree->page($id)), $hits),
			$slice, $arguments, $uid, self::TOOL);
		return ['collective_id' => $tree->collectiveId, 'pages' => $page['items'], 'next_cursor' => $page['next_cursor']]
			+ ($notices === [] ? [] : ['notices' => $notices]);
	}
}
