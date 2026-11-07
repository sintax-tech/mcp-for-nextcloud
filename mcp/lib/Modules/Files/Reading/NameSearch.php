<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reading;

use OCP\Files\Folder;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCA\Mcp\Modules\Files\FilesModule;
use OCA\Mcp\Modules\Files\Searching\NameSearchQuery;
use OCA\Mcp\Modules\Files\Searching\NameLikeComparison;

/** Searches filenames in the database and formats only visible matches. */
final class NameSearch {
	/**
	 * @param IDBConnection $db database connection for filename search
	 * @param IUserManager $userManager actor lookup for the native filename search query
	 * @param FileEntry $entries node listing entry formatter
	 * @param ?FilesAccessGuard $visibilityGuard optional resource-scope admission guard
	 */
	public function __construct(
		private IDBConnection $db,
		private IUserManager $userManager,
		private FileEntry $entries,
		private ?FilesAccessGuard $visibilityGuard = null,
	) {
	}

	/** @return list<array{name:string, path:string, isDir:bool, size:int, mtime:string, contentType:string, access:array<string, mixed>}>
	 * @param Folder $root actor folder used to resolve relative paths
	 * @param string $userId authenticated actor identifier
	 * @param string $query search text supplied by the caller
	 * @param int $limit maximum number of results
	 */
	public function search(Folder $root, string $userId, string $query, int $limit): array {
		// %, _ and \ in the term are literal: escaped the way core's own file search escapes LIKE terms.
		$pattern = '%' . $this->db->escapeLikeParameter($query) . '%';
		$user = $this->userManager->get($userId);
		$out = [];
		$seenIds = [];
		$batchSize = $limit + FilesModule::SEARCH_OVERFETCH;
		$maxInspected = 500;
		$offset = 0;
		$totalInspected = 0;

		while (count($out) < $limit && $totalInspected < $maxInspected) {
			$fetchLimit = min($batchSize, $maxInspected - $totalInspected);
			$search = new NameSearchQuery(new NameLikeComparison($pattern), $fetchLimit, $user, [], $offset);
			$batch = $root->search($search);
			$batchCount = 0;
			$newInBatch = 0;

			foreach ($batch as $node) {
				$batchCount++;
				$totalInspected++;
				$id = (int)$node->getId();
				if ($id > 0) {
					if (isset($seenIds[$id])) {
						continue;
					}
					$seenIds[$id] = true;
				}
				$newInBatch++;

				if ($node->getPath() === $root->getPath() || !$node->isReadable()) {
					continue;
				}
				if ($this->visibilityGuard !== null && !$this->visibilityGuard->isVisible($node)) {
					continue;
				}
				$out[] = $this->entries->describe($root, $userId, $node);
				if (count($out) >= $limit) {
					break 2;
				}
			}

			if ($batchCount === 0 || $newInBatch === 0 || $batchCount < $fetchLimit) {
				break;
			}

			$offset += $batchCount;
		}

		return $out;
	}
}
