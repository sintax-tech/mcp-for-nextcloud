<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Integration;

use OCA\Mcp\Access\Visibility\OutputReferenceFilter;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCP\Files\File;
use OCP\Files\Folder;
use Throwable;

/**
 * From a page of the tree to the file the actor's mount of the collective holds. A page is admitted only when it is
 * a `.md` file of the tree, outside every dotted folder (templates `.templates`, attachments `.attachments.<id>`),
 * visible to MCP (hidden tags) and inside the user's resource scope. Paths are never taken from the caller: they come
 * from the native tree, and the file is fetched by id and compared with it.
 */
class PageFiles {
	/**
	 * @param VisibilityGuard $visibility administrator exclusions by system tag
	 * @param OutputReferenceFilter $scope the user's Files resource scope
	 */
	public function __construct(
		private VisibilityGuard $visibility,
		private OutputReferenceFilter $scope,
	) {
	}

	/**
	 * @param array<string, mixed> $page a page of {@see PageProjection::page()}
	 * @return string its path relative to the collective folder, or '' when it is not an admitted page
	 */
	public static function relativePath(array $page): string {
		$name = (string)($page['file_name'] ?? '');
		$path = trim((string)($page['file_path'] ?? '') . '/' . $name, '/');
		if (!str_ends_with($name, '.md') || $path === '') {
			return '';
		}
		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '..' || str_starts_with($segment, '.')) {
				return '';
			}
		}
		return $path;
	}

	/**
	 * Whether a page of the tree may be shown to the actor; any doubt hides it.
	 * @param Folder $folder the actor's folder of the collective
	 * @param array<string, mixed> $page a page of {@see PageProjection::page()}
	 * @param string $uid authenticated actor
	 * @return bool whether the page is admitted and visible
	 */
	public function shows(Folder $folder, array $page, string $uid): bool {
		$path = self::relativePath($page);
		if ($path === '') {
			return false;
		}
		try {
			$node = $folder->get($path);
			return $node instanceof File && (int)$node->getId() === (int)$page['id'] && $this->admits($node, $uid);
		} catch (Throwable) {
			return false;
		}
	}

	/**
	 * The file of an admitted page, checked against the tree entry it must be.
	 * @param File $file what the page service resolved for the page id
	 * @param array<string, mixed> $page the tree entry of that id
	 * @param string $uid authenticated actor
	 * @return File the same file
	 * @throws ToolFailure not found for anything that is not an admitted, visible page of the tree
	 */
	public function checked(File $file, array $page, string $uid): File {
		if (self::relativePath($page) === '' || (int)$file->getId() !== (int)$page['id']
			|| $file->getName() !== (string)$page['file_name'] || !$this->admits($file, $uid)) {
			throw new ToolFailure(CommonMessages::notFound());
		}
		return $file;
	}

	/**
	 * @param File $file a page file
	 * @param string $uid authenticated actor
	 * @return bool whether MCP may show it to the actor
	 */
	private function admits(File $file, string $uid): bool {
		return $this->visibility->isVisible($file) && $this->scope->allowsUserNode($uid, $file);
	}
}
