<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Images;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Modules\Files\Searching\NameSearchQuery;
use OCA\Mcp\Modules\Files\Searching\SearchBinaryOperator;
use OCA\Mcp\Modules\Files\Searching\SearchComparison;
use OCA\Mcp\Modules\Files\Searching\SearchOrder;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchOrder;
use OCP\IDBConnection;
use OCP\IUserManager;

/** Runs the existing paged image query with its visibility and inspection limits. */
final class ImageQuerySearch {
	/**
	 * @param IDBConnection $db Database-specific escaping for image filename queries.
	 * @param IUserManager $userManager Actor lookup for user-scoped image searches.
	 * @param ?FilesAccessGuard $visibilityGuard Optional resource-scope visibility guard.
	 */
	public function __construct(
		private IDBConnection $db,
		private IUserManager $userManager,
		private ?FilesAccessGuard $visibilityGuard = null,
	) {
	}

	/**
	 * Builds and executes an ISearchQuery with filters (mimetype, name, mtime) and mtime DESC ordering.
	 *
	 * @param Folder $root The user's root folder.
	 * @param Folder $scope Folder restricting the search.
	 * @param string $userId Authenticated user.
	 * @param string $query Optional filename filter.
	 * @param int|null $after Lower mtime bound.
	 * @param int|null $before Upper mtime bound.
	 * @param int $limit Maximum returned entries.
	 * @param \Closure(Folder, string, File): array<string, mixed> $entry Formats a visible image entry with actor access metadata.
	 * @return list<array<string, mixed>> Image metadata.
	 * @throws ToolFailure When the filter cannot be resolved.
	 * @throws \Throwable Backend, policy and lock failures handled by execute.
	 */
	public function execute(
		Folder $root,
		Folder $scope,
		string $userId,
		string $query,
		?int $after,
		?int $before,
		int $limit,
		\Closure $entry,
	): array {
		$conditions = [
			new SearchComparison('mimetype', ISearchComparison::COMPARE_LIKE, 'image/%'),
		];
		if ($query !== '') {
			$pattern = '%' . $this->db->escapeLikeParameter($query) . '%';
			$conditions[] = new SearchComparison('name', ISearchComparison::COMPARE_LIKE, $pattern);
		}
		if ($after !== null) {
			$conditions[] = new SearchComparison('mtime', ISearchComparison::COMPARE_GREATER_THAN_EQUAL, $after);
		}
		if ($before !== null) {
			$conditions[] = new SearchComparison('mtime', ISearchComparison::COMPARE_LESS_THAN_EQUAL, $before);
		}

		$operation = SearchBinaryOperator::and(...$conditions);
		$order = [new SearchOrder('mtime', ISearchOrder::DIRECTION_DESCENDING)];
		$user = $this->userManager->get($userId);

		$out = [];
		$seenIds = [];
		$batchSize = $limit;
		$maxInspected = 500;
		$offset = 0;
		$totalInspected = 0;

		while (count($out) < $limit && $totalInspected < $maxInspected) {
			$fetchLimit = min($batchSize, $maxInspected - $totalInspected);
			$search = new NameSearchQuery($operation, $fetchLimit, $user, $order, $offset);
			$batch = $scope->search($search);
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

				if ($node->getPath() === $scope->getPath() || !$node instanceof File || !$node->isReadable()) {
					continue;
				}
				if ($this->visibilityGuard !== null && !$this->visibilityGuard->isVisible($node)) {
					continue;
				}
				$out[] = ($entry)($root, $userId, $node);
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
