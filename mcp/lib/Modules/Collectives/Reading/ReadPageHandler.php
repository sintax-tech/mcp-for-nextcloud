<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Reading;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Locating\PageTreeLoader;
use OCA\Mcp\Modules\Collectives\Integration\CollectivesMarkdownPolicy;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesMessages;

/** Backs `collectives_read`: one admitted page, whole, with its etag. */
class ReadPageHandler {
	/**
	 * @param PageTreeLoader $trees the page tree, read now
	 */
	public function __construct(
		private PageTreeLoader $trees,
	) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @param array<string, mixed> $arguments validated collectives_read arguments
	 * @return array{collective_id:int, page:array<string, mixed>, etag:string, content:string} the page and its Markdown
	 * @throws ToolFailure when the page is unknown, hidden or over the read limit
	 */
	public function handle(string $uid, array $arguments): array {
		$tree = $this->trees->load((int)$arguments['collective_id'], $uid, 'read');
		$id = (int)$arguments['page_id'];
		$file = $this->trees->file($tree, $id, $uid, 'read');
		if ($file->getSize() > CollectivesMarkdownPolicy::MAX_BYTES) {
			throw new ToolFailure(CollectivesMessages::pageTooLarge(CollectivesMarkdownPolicy::MAX_BYTES));
		}
		$content = (string)$file->getContent();
		if (strlen($content) > CollectivesMarkdownPolicy::MAX_BYTES) {
			throw new ToolFailure(CollectivesMessages::pageTooLarge(CollectivesMarkdownPolicy::MAX_BYTES));
		}
		return ['collective_id' => $tree->collectiveId, 'page' => $tree->visible($tree->page($id)),
			'etag' => (string)$file->getEtag(), 'content' => $content];
	}
}
