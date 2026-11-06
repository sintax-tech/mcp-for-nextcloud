<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Locating;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Integration\CollectivesGateway;
use OCA\Mcp\Modules\Collectives\Integration\PageFiles;
use OCA\Mcp\Modules\Collectives\Integration\PageReads;
use OCP\Files\File;

/**
 * Reads the page tree of a collective now, keeping only the pages {@see PageFiles} admits, and resolves the file of
 * one page against that same tree. Every call asks Collectives again; nothing is cached between calls.
 */
class PageTreeLoader {
	/**
	 * @param CollectivesGateway $gateway native Collectives
	 * @param PageFiles $files which pages MCP may show
	 * @param PageFileWalk $walk the page files, found through Files without asking Collectives to build the tree
	 * @param PageReads $reads the pages of those files, read one by one with PageService::find
	 */
	public function __construct(
		private CollectivesGateway $gateway,
		private PageFiles $files,
		private PageFileWalk $walk,
		private PageReads $reads,
	) {
	}

	/**
	 * @param int $cid collective id
	 * @param string $uid authenticated actor
	 * @param string $operation exact grant of the call
	 * The actor's membership is read again first, without the page service's per-request cache, so a read back
	 * after a write never relies on the authorization the write started with.
	 * @return PageTree the admitted pages, read now
	 * @throws ToolFailure when the collective is unknown to the actor, the call is not admitted, or reading would write
	 */
	public function load(int $cid, string $uid, string $operation): PageTree {
		$this->gateway->collective($cid, $uid, $operation);
		$folder = $this->gateway->folder($cid, $uid, $operation);
		$pages = array_values(array_filter($this->reads->read($cid, $this->walk->pageIds($folder), $uid, $operation),
			fn (array $page): bool => $this->files->shows($folder, $page, $uid)));
		return new PageTree($cid, $folder, $pages);
	}

	/**
	 * @param PageTree $tree the tree the page must belong to
	 * @param int $pageId page id
	 * @param string $uid authenticated actor
	 * @param string $operation exact grant of the call
	 * @return File the page file, checked against its tree entry
	 * @throws ToolFailure not found for a page outside the tree, a template, an attachment or a hidden file
	 */
	public function file(PageTree $tree, int $pageId, string $uid, string $operation): File {
		$page = $tree->page($pageId);
		return $this->files->checked($this->gateway->pageFile($tree->collectiveId, $pageId, $uid, $operation), $page, $uid);
	}
}
