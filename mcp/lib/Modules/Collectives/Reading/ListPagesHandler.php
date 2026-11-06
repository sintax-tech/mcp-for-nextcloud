<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Reading;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Locating\PageTreeLoader;
use OCA\Mcp\Modules\Collectives\Integration\PageProjection;

/** Backs `collectives_pages`: the admitted pages of a collective, or of one page's subtree, sliced by cursor. */
class ListPagesHandler {
	/** Tool name of the listing, part of the cursor signature. */
	private const TOOL = 'collectives_pages';

	/**
	 * @param PageTreeLoader $trees the page tree, read now
	 * @param PageCursor $cursors listing cursors
	 */
	public function __construct(
		private PageTreeLoader $trees,
		private PageCursor $cursors,
	) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @param array<string, mixed> $arguments validated collectives_pages arguments
	 * @return array{collective_id:int, pages:list<array<string, mixed>>, next_cursor:?string} one slice of the pages
	 * @throws ToolFailure when the collective or the parent is unknown, the call is not admitted or the cursor is not valid
	 */
	public function handle(string $uid, array $arguments): array {
		$slice = $this->cursors->slice($arguments, $uid, self::TOOL);
		$tree = $this->trees->load((int)$arguments['collective_id'], $uid, 'read');
		if (isset($arguments['parent_id'])) {
			$tree->page((int)$arguments['parent_id']);
			$pages = array_map(static fn (int $id): array => $tree->page($id), $tree->descendants((int)$arguments['parent_id']));
		} else {
			$pages = $tree->ordered();
		}
		$page = $this->cursors->page(array_map(static fn (array $page): array => PageProjection::visible($page), $pages),
			$slice, $arguments, $uid, self::TOOL);
		return ['collective_id' => $tree->collectiveId, 'pages' => $page['items'], 'next_cursor' => $page['next_cursor']];
	}
}
