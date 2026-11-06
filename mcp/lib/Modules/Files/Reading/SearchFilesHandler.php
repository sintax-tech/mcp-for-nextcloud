<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reading;

use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\Files\Folder;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCP\FullTextSearch\IFullTextSearchManager;
use OCP\FullTextSearch\Model\ISearchResult;
use OCA\Mcp\Modules\Files\FilesModule;
use OCA\Mcp\Modules\Files\FilesMessages;
use OCA\Mcp\Modules\Files\FilesCallContext;

/** Preserves the existing Files SearchFilesHandler behavior. */
final class SearchFilesHandler {
	/**
	 * @param FilesCallContext $context existing context collaborator
	 * @param FullTextAvailability $availability existing availability collaborator
	 * @param NameSearch $names existing names collaborator
	 * @param FileEntry $entries existing entries collaborator
	 * @param ?IFullTextSearchManager $ftsManager existing ftsManager collaborator
	 * @param ?FilesAccessGuard $visibilityGuard existing visibilityGuard collaborator
	 */
	public function __construct(
		private FilesCallContext $context,
		private FullTextAvailability $availability,
		private NameSearch $names,
		private FileEntry $entries,
		private ?IFullTextSearchManager $ftsManager = null,
		private ?FilesAccessGuard $visibilityGuard = null,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged tool result
	 */
	public function handle(array $arguments, string $userId): array {
		$confirmed = (bool)($arguments['confirm_shared'] ?? false);
		return $this->context->run($userId, fn (Folder $root): array => ToolResult::json($this->search($root, $userId, $arguments['query'], (int)$arguments['limit'], (string)($arguments['mode'] ?? 'auto'))));
	}

	/**
	 * @return array{search_mode:string, full_text_active:bool, notice?:string, files:list<array{name:string, path:string, isDir:bool, size:int, mtime:string, contentType:string, access:array<string, mixed>, excerpts?:list<string>}>}
	 * @param Folder $root existing operation root
	 * @param string $userId existing operation userId
	 * @param string $query existing operation query
	 * @param int $limit existing operation limit
	 * @param string $mode existing operation mode
	 */
	private function search(Folder $root, string $userId, string $query, int $limit, string $mode = 'auto'): array {
		if ($mode === 'name') {
			return [
				'search_mode' => 'name_only',
				'full_text_active' => $this->availability->available($userId),
				'files' => $this->names->search($root, $userId, $query, $limit),
			];
		}

		if (!$this->availability->available($userId)) {
			return [
				'search_mode' => 'name_only',
				'full_text_active' => false,
				'notice' => FilesMessages::searchNameOnlyNotice(),
				'files' => $this->names->search($root, $userId, $query, $limit),
			];
		}

		try {
			$results = $this->ftsManager->search([
				'search' => $query,
				'providers' => ['files'],
				'size' => $limit + FilesModule::SEARCH_OVERFETCH,
				'page' => 1,
			], $userId);

			$files = [];
			$seen = [];
			foreach ($results as $result) {
				if (!$result instanceof ISearchResult) {
					continue;
				}
				foreach ($result->getDocuments() as $doc) {
					$fileId = (int)$doc->getId();
					if ($fileId <= 0) {
						continue;
					}
					$nodes = $root->getById($fileId);
					if ($nodes === []) {
						continue;
					}
					$visibleNodes = ($this->visibilityGuard?->filter($nodes) ?? array_values($nodes));
					if ($visibleNodes === []) {
						continue;
					}
					$node = $visibleNodes[0];
					if ($node->getPath() === $root->getPath() || !$node->isReadable()) {
						continue;
					}
					if (isset($seen[$node->getId()])) {
						continue;
					}
					$seen[$node->getId()] = true;

					$snippets = [];
					foreach ($doc->getExcerpts() as $item) {
						if (is_array($item) && isset($item['excerpt']) && is_string($item['excerpt'])) {
							$snippets[] = trim(strip_tags($item['excerpt']));
						} elseif (is_string($item)) {
							$snippets[] = trim(strip_tags($item));
						}
					}
					$snippets = array_values(array_filter($snippets, static fn (string $s) => $s !== ''));

					$entry = $this->entries->describe($root, $userId, $node);
					if ($snippets !== []) {
						$entry['excerpts'] = $snippets;
					}
					$files[] = $entry;
					if (count($files) >= $limit) {
						break 2;
					}
				}
			}

			return [
				'search_mode' => 'content',
				'full_text_active' => true,
				'files' => $files,
			];
		} catch (\Throwable) {
			return [
				'search_mode' => 'name_only',
				'full_text_active' => false,
				'notice' => FilesMessages::searchFallbackNotice(),
				'files' => $this->names->search($root, $userId, $query, $limit),
			];
		}
	}
}
