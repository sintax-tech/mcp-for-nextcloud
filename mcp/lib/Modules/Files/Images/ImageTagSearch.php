<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Images;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUserManager;
use OCP\Lock\LockedException;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use Psr\Log\LoggerInterface;

/** Resolves a visible system tag and filters its image nodes in the existing order. */
final class ImageTagSearch {
	/**
	 * @param ISystemTagManager $tagManager Existing request-scoped collaborator.
	 * @param ISystemTagObjectMapper $tagMapper Existing request-scoped collaborator.
	 * @param IUserManager $userManager Existing request-scoped collaborator.
	 * @param LoggerInterface $logger Existing request-scoped collaborator.
	 * @param ?FilesAccessGuard $visibilityGuard Existing optional Files visibility guard.
	 */
	public function __construct(
		private ISystemTagManager $tagManager,
		private ISystemTagObjectMapper $tagMapper,
		private IUserManager $userManager,
		private LoggerInterface $logger,
		private ?FilesAccessGuard $visibilityGuard = null,
	) {
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
	 * @param \Closure(Folder, string, File): array<string, mixed> $entry Existing metadata builder.
	 * @return list<array<string, mixed>> Image metadata.
	 * @throws ToolFailure When the filter cannot be resolved.
	 * @throws \Throwable Backend, policy and lock failures handled by execute.
	 */
	public function execute(
		Folder $root,
		Folder $scope,
		string $userId,
		string $tagName,
		string $query,
		?int $after,
		?int $before,
		int $limit,
		\Closure $entry,
	): array {
		$tag = $this->findTag($tagName);
		if ($tag === null) {
			throw new ToolFailure(ImageSearchMessages::tagNotFound());
		}

		try {
			$rawIds = $this->tagMapper->getObjectIdsForTags($tag->getId(), ImageLimits::TAG_OBJECT_TYPE);
		} catch (TagNotFoundException $e) {
			throw new ToolFailure(ImageSearchMessages::tagNotFound(), 0, $e);
		}

		if ($rawIds === []) {
			return [];
		}

		$count = count($rawIds);
		if ($count > ImageLimits::TAG_RESOLUTION_CEILING) {
			$this->logger->warning('Image tag search truncated: tag has more objects than ceiling', [
				'tag' => $tagName,
				'count' => $count,
				'ceiling' => ImageLimits::TAG_RESOLUTION_CEILING,
			]);
			$rawIds = array_slice($rawIds, 0, ImageLimits::TAG_RESOLUTION_CEILING);
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
				if (!ImageFileAccess::looksLikeImage($mime)) {
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

		return array_map(fn (File $file) => ($entry)($root, $userId, $file), $files);
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
}
