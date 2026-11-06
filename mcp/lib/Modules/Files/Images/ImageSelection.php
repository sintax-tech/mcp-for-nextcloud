<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Images;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCA\Mcp\Modules\Files\FilesMessages;
use OCA\Mcp\Modules\Files\NameSearchQuery;
use OCA\Mcp\Modules\Files\SearchComparison;
use OCA\Mcp\Modules\Files\SearchOrder;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchOrder;
use OCP\IUserManager;

/** Selects explicit or folder image candidates without merging their admission rules. */
final class ImageSelection {
	/**
	 * @param IUserManager $userManager Existing request-scoped collaborator.
	 * @param ?FilesAccessGuard $visibilityGuard Existing optional Files visibility guard.
	 */
	public function __construct(
		private IUserManager $userManager,
		private ?FilesAccessGuard $visibilityGuard = null,
	) {
	}

	/**
	 * Picks images under a folder, newest first, running the search as the authenticated user.
	 *
	 * @param Folder $root the user's folder
	 * @param string $userId authenticated user
	 * @param string $folder user-relative folder path
	 * @param int $limit maximum items to pick
	 * @return list<array{0:string, 1:File|null, 2:string|null}> path, file, error (one of file/error is set)
	 * @throws ToolFailure when the folder path is not a folder
	 */
	public function selectFromFolder(Folder $root, string $userId, string $folder, int $limit): array {
		$normalized = PathGuard::normalize($folder, 'folder');
		$scope = NodeAccess::getBase($root, $normalized, $this->visibilityGuard);
		if (!$scope instanceof Folder) {
			throw new ToolFailure(FilesMessages::notAFolder());
		}
		$operation = new SearchComparison('mimetype', ISearchComparison::COMPARE_LIKE, 'image/%');
		$order = [new SearchOrder('mtime', ISearchOrder::DIRECTION_DESCENDING)];
		$user = $this->userManager->get($userId);
		$search = new NameSearchQuery($operation, min($limit, ImageLimits::BATCH_MAX_ITEMS), $user, $order);
		$files = [];
		foreach ($scope->search($search) as $node) {
			if ($node instanceof File && $node->isReadable() && ($this->visibilityGuard === null || $this->visibilityGuard->isVisible($node))) {
				$files[] = $node;
			}
		}
		return array_map(fn (File $f) => [(string)$root->getRelativePath($f->getPath()), $f, null], $files);
	}

	/**
	 * Looks up explicit paths and marks the ones that are not readable images.
	 *
	 * @param Folder $root the user's folder
	 * @param list<string> $paths user-relative paths
	 * @return list<array{0:string, 1:File|null, 2:string|null}> path, file, error
	 */
	public function selectPaths(Folder $root, array $paths): array {
		$out = [];
		foreach ($paths as $raw) {
			$path = PathGuard::normalize((string)$raw, 'paths');
			try {
				$node = NodeAccess::get($root, $path, $this->visibilityGuard);
				if (!$node instanceof File) {
					$out[] = [$path, null, FilesMessages::notAnImage()];
					continue;
				}
				if (!ImageFileAccess::looksLikeImage((string)$node->getMimetype())) {
					$out[] = [$path, null, FilesMessages::notAnImage()];
					continue;
				}
				$out[] = [$path, $node, null];
			} catch (NotFoundException) {
				$out[] = [$path, null, CommonMessages::notFound()];
			} catch (ToolFailure $e) {
				$out[] = [$path, null, $e->getMessage()];
			}
		}
		return $out;
	}
}
