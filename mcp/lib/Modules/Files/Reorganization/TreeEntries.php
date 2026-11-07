<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Sort and describe the readable entries of a Files tree. */
namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCP\Files\Folder;
use OCP\Files\Node;

/** Preserves the existing TreeEntries operations and refusal order. */
final class TreeEntries {
	/**
	 * Bind the existing collaborators without storing operation state.
	 * @param NodeAccessInfo $access access collaborator.
	 * @return void
	 */
	public function __construct(
		private readonly NodeAccessInfo $access,
	) {
	}

	/**
	 * @param Folder $folder folder whose entries are listed
	 * @return list<Node> readable entries, folders first and both alphabetically, without the user's hidden files
	 */
	public function sorted(Folder $folder): array {
		$entries = [];
		foreach ($folder->getDirectoryListing() as $node) {
			// A leading dot is what Nextcloud's own file views hide, and its trash and versions folders
			// are implementation detail an agent planning a reorganization has no business touching.
			if (str_starts_with($node->getName(), '.') || !$node->isReadable()) {
				continue;
			}
			$entries[] = $node;
		}
		usort($entries, static fn (Node $a, Node $b) => [$a instanceof Folder ? 0 : 1, $a->getName()]
			<=> [$b instanceof Folder ? 0 : 1, $b->getName()]);
		return $entries;
	}

	/**
	 * @param Folder $root the user's folder
	 * @param string $userId authenticated user
	 * @param Node $node entry to describe
	 * @param string $relative user-relative path of the entry
	 * @return array{name:string, path:string, isDir:bool, size:int, mtime:string, mime:string, access:array<string, mixed>}
	 */
	public function entry(Folder $root, string $userId, Node $node, string $relative): array {
		$isDir = NodeAccess::isFolder($node);
		return [
			'name' => $node->getName(),
			'path' => $relative,
			'isDir' => $isDir,
			'size' => $isDir ? 0 : (int)$node->getSize(),
			'mtime' => gmdate('D, d M Y H:i:s \\G\\M\\T', (int)$node->getMTime()),
			'mime' => $isDir ? 'httpd/unix-directory' : (string)$node->getMimetype(),
			'access' => $this->access->describe($node, $userId),
		];
	}
}
