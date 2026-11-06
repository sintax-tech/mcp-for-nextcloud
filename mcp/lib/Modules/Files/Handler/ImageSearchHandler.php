<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Handler;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCA\Mcp\Modules\Files\FilesMessages;
use OCA\Mcp\Modules\Files\ImageSearchMessages;
use OCA\Mcp\Modules\Files\ImageTools;
use OCA\Mcp\Modules\Files\NameSearchQuery;
use OCA\Mcp\Modules\Files\SearchBinaryOperator;
use OCA\Mcp\Modules\Files\SearchComparison;
use OCA\Mcp\Modules\Files\SearchOrder;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchOrder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Lock\LockedException;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use Psr\Log\LoggerInterface;

/** Executes files_image_search while distinguishing unresolved filters from empty matches. */
final class ImageSearchHandler {
	/**
	 * @param ISystemTagManager $tagManager Reads user-visible tags.
	 * @param ISystemTagObjectMapper $tagMapper Resolves tagged file IDs.
	 * @param IUserManager $userManager Resolves the authenticated search user.
	 * @param LoggerInterface $logger Reports truncated searches and sanitized backend failures.
	 * @param IDBConnection $db Escapes filename filters.
	 * @param \Closure(Folder, string, File): array<string, mixed> $entry Shared image metadata builder.
	 * @param FilesAccessGuard|null $visibilityGuard Enforces MCP file visibility.
	 */
	public function __construct(
		private ISystemTagManager $tagManager,
		private ISystemTagObjectMapper $tagMapper,
		private IUserManager $userManager,
		private LoggerInterface $logger,
		private IDBConnection $db,
		private \Closure $entry,
		private ?FilesAccessGuard $visibilityGuard = null,
	) {
	}

	/**
	 * Searches images by name, folder, mtime range and system tag; returns metadata only.
	 *
	 * @param Folder $root the user's folder
	 * @param string $userId authenticated user
	 * @param array{query?:string, folder?:string, modified_after?:string, modified_before?:string, tag?:string, limit:int} $args Validated tool arguments.
	 * @return list<array<string, mixed>>
	 * @throws ToolFailure For an invalid folder, date, unresolved tag or backend failure.
	 * @throws PolicyUnavailable When the visibility policy cannot be read.
	 * @throws LockedException When Nextcloud reports a locked resource.
	 */
	public function execute(Folder $root, string $userId, array $args): array {
		$limit = max(1, min(ImageTools::SEARCH_MAX_LIMIT, (int)$args['limit']));
		$query = isset($args['query']) ? trim((string)$args['query']) : '';
		$folder = isset($args['folder']) ? PathGuard::normalize((string)$args['folder'], 'folder') : '/';
		$after = $this->parseDate($args['modified_after'] ?? null);
		$before = $this->parseDate($args['modified_before'] ?? null);
		$tag = isset($args['tag']) && trim((string)$args['tag']) !== '' ? trim((string)$args['tag']) : null;

		// A hidden folder answers like a missing one: an empty list would tell that it is there.
		$scope = NodeAccess::getBase($root, $folder, $this->visibilityGuard);
		if (!$scope instanceof Folder) {
			throw new ToolFailure(FilesMessages::notAFolder());
		}

		try {
			if ($tag !== null) {
				return $this->searchByTag($root, $scope, $userId, $tag, $query, $after, $before, $limit);
			}
			return $this->searchByQuery($root, $scope, $userId, $query, $after, $before, $limit);
		} catch (ToolFailure|PolicyUnavailable|LockedException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->logger->error('Image search failed', [
				'app' => 'mcp',
				'tool' => 'files_image_search',
				'exception_class' => $e::class,
				'exception_message' => ToolRegistry::loggable($e),
			]);
			throw new ToolFailure(ImageSearchMessages::failed(), 0, $e);
		}
	}

	/**
	 * Resolves object IDs for a system tag, enforces user visibility and folder scoping, and sorts by mtime DESC.
	 *
	 * @param Folder $root The user's root folder.
	 * @param Folder $scope Folder restricting the search.
	 * @param string $userId Authenticated user.
	 * @param string $query Optional filename filter.
	 * @param int|null $after Lower mtime bound.
	 * @param int|null $before Upper mtime bound.
	 * @param int $limit Maximum returned entries.
	 * @param string $tagName Exact visible tag name.
	 * @return list<array<string, mixed>> Image metadata.
	 * @throws ToolFailure When the filter cannot be resolved.
	 * @throws \Throwable Backend, policy and lock failures handled by execute.
	 */
	private function searchByTag(
		Folder $root,
		Folder $scope,
		string $userId,
		string $tagName,
		string $query,
		?int $after,
		?int $before,
		int $limit,
	): array {
		$tag = $this->findTag($tagName);
		if ($tag === null) {
			throw new ToolFailure(ImageSearchMessages::tagNotFound());
		}

		try {
			$rawIds = $this->tagMapper->getObjectIdsForTags($tag->getId(), ImageTools::TAG_OBJECT_TYPE);
		} catch (TagNotFoundException $e) {
			throw new ToolFailure(ImageSearchMessages::tagNotFound(), 0, $e);
		}

		if ($rawIds === []) {
			return [];
		}

		$count = count($rawIds);
		if ($count > ImageTools::TAG_RESOLUTION_CEILING) {
			$this->logger->warning('Image tag search truncated: tag has more objects than ceiling', [
				'tag' => $tagName,
				'count' => $count,
				'ceiling' => ImageTools::TAG_RESOLUTION_CEILING,
			]);
			$rawIds = array_slice($rawIds, 0, ImageTools::TAG_RESOLUTION_CEILING);
		}

		$files = [];
		foreach ($rawIds as $id) {
			try {
				$nodes = $scope->getById((int)$id);
			} catch (PolicyUnavailable|LockedException $e) {
				throw $e;
			} catch (\Throwable) {
				continue;
			}
			foreach ($nodes as $node) {
				if (!$node instanceof File || !$node->isReadable()) {
					continue;
				}
				if ($this->visibilityGuard !== null && !$this->visibilityGuard->isVisible($node)) {
					continue;
				}
				$mime = (string)$node->getMimetype();
				if (!ImageTools::looksLikeImage($mime)) {
					continue;
				}
				if ($query !== '' && stripos($node->getName(), $query) === false) {
					continue;
				}
				$mtime = (int)$node->getMTime();
				if ($after !== null && $mtime < $after) {
					continue;
				}
				if ($before !== null && $mtime > $before) {
					continue;
				}
				$files[] = $node;
			}
		}

		usort($files, static fn (File $a, File $b): int => (int)$b->getMTime() <=> (int)$a->getMTime());
		$files = array_slice($files, 0, $limit);

		return array_map(fn (File $file) => ($this->entry)($root, $userId, $file), $files);
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
	 * @return list<array<string, mixed>> Image metadata.
	 * @throws ToolFailure When the filter cannot be resolved.
	 * @throws \Throwable Backend, policy and lock failures handled by execute.
	 */
	private function searchByQuery(
		Folder $root,
		Folder $scope,
		string $userId,
		string $query,
		?int $after,
		?int $before,
		int $limit,
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
				$out[] = ($this->entry)($root, $userId, $node);
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

	/**
	 * Searches by exact name, strictly restricted to user-visible tags.
	 *
	 * @param string $tagName Requested tag name.
	 * @return ISystemTag|null Visible match, or null for both absent and invisible tags.
	 * @throws \Throwable When querying the tag backend fails; execute preserves policy/lock failures.
	 */
	private function findTag(string $tagName): ?ISystemTag {
		foreach ($this->tagManager->getAllTags(true, $tagName) as $tag) {
			if ($tag->isUserVisible() && $tag->getName() === $tagName) {
				return $tag;
			}
		}
		return null;
	}

	/**
	 * @param mixed $raw ISO 8601 string or null
	 * @return int|null epoch seconds, or null when $raw is null
	 * @throws ToolFailure when $raw is set but not a parseable ISO 8601 moment
	 */
	private function parseDate(mixed $raw): ?int {
		if ($raw === null || $raw === '') {
			return null;
		}
		$value = (string)$raw;
		$time = strtotime($value);
		if ($time === false) {
			throw new ToolFailure(FilesMessages::invalidDate($value));
		}
		return $time;
	}
}
