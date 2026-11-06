<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reading;

use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\Files\Folder;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCP\Files\Node;
use OCA\Mcp\Modules\Files\Presentation\FilesMessages;
use OCA\Mcp\Modules\Files\FilesCallContext;

/** Runs the operation that lists directory entries and their access metadata inside the actor view. */
final class ListFilesHandler {
	/**
	 * @param FilesCallContext $context actor-root admission and node-access mapping
	 * @param FileEntry $entries node listing entry formatter
	 * @param ?FilesAccessGuard $visibilityGuard optional resource-scope admission guard
	 */
	public function __construct(
		private FilesCallContext $context,
		private FileEntry $entries,
		private ?FilesAccessGuard $visibilityGuard = null,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged tool result
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->context->run($userId, fn (Folder $root): array => ToolResult::json($this->list($root, $userId, $arguments['path'])));
	}

	/** @return list<array{name:string, path:string, isDir:bool, size:int, mtime:string, contentType:string, access:array<string, mixed>}>
	 * @param Folder $root actor folder used to resolve relative paths
	 * @param string $userId authenticated actor identifier
	 * @param string $path user-relative file path
	 */
	private function list(Folder $root, string $userId, string $path): array {
		// With a Files scope that does not hold the root, "/" lists the selected folders at their real paths: the
		// folders above them are never listed.
		$listing = PathGuard::normalize($path) === '/' ? $this->visibilityGuard?->scopeRoots($root) : null;
		if ($listing === null) {
			$folder = NodeAccess::get($root, $path, $this->visibilityGuard);
			if (!$folder instanceof Folder) {
				throw new ToolFailure(FilesMessages::notAFolder());
			}
			$listing = $folder->getDirectoryListing();
		}
		if ($this->visibilityGuard !== null) {
			$listing = $this->visibilityGuard->filter($listing);
		}
		$entries = array_map(fn (Node $node) => $this->entries->describe($root, $userId, $node), $listing);
		usort($entries, static fn (array $a, array $b) => [$b['isDir'], $a['name']] <=> [$a['isDir'], $b['name']]);
		return $entries;
	}
}
