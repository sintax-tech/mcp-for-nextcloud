<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Attachments;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;

/**
 * One caller-relative file identity and the visibility of every native alias in that caller's tree.
 */
final class CardFileNodeAccess {
	/**
	 * Receive Files services without resolving any node in the constructor.
	 *
	 * @param FilesAccessGuard $guard Existing Files scope, exclusion and native owner-chain boundary.
	 * @param IRootFolder $root Caller-relative native folder access.
	 * @return void
	 */
	public function __construct(private FilesAccessGuard $guard, private IRootFolder $root) {
	}

	/**
	 * Resolve a File only after callers admitted their operation's grants and scope.
	 *
	 * A clean alias cannot bypass a hidden alias. A folder is named only after every alias is a folder that is visible, readable and the expected id. Any other alias stays on the same refusal as a missing file.
	 *
	 * @param string $uid Authenticated caller.
	 * @param int $fileId Stable original node identity.
	 * @param bool $share Require native SHARE on every alias for a new link.
	 * @return array{node:File,metadata:array} Canonical native node and visible caller-relative metadata.
	 * @throws \Throwable On denied identity, visibility, permission or uncertain resolution.
	 */
	public function resolve(string $uid, int $fileId, bool $share = false): array {
		$folder = $this->root->getUserFolder($uid);
		try {
			$nodes = $folder->getById($fileId);
		} catch (NotFoundException) {
			throw \OCA\Mcp\Modules\Deck\Attachments\CardFiles::unavailable();
		}
		if ($nodes === []) {
			throw \OCA\Mcp\Modules\Deck\Attachments\CardFiles::unavailable();
		}
		foreach ($nodes as $node) {
			if (!$node instanceof File || !$this->guard->isVisible($node) || !$node->isReadable()
				|| (int)$node->getId() !== $fileId || ($share && !$node->isShareable())) {
				if ($this->onlyAuthorizedFolders($nodes, $fileId)) {
					throw new \OCA\Mcp\Modules\Deck\Failures\DeckRefusalException(\OCA\Mcp\Modules\Deck\Presentation\DeckMessages::fileFolderRefused());
				}
				throw \OCA\Mcp\Modules\Deck\Attachments\CardFiles::unavailable();
			}
		}
		usort($nodes, static fn (File $a, File $b): int => $a->getPath() <=> $b->getPath());
		$node = $nodes[0];
		$path = $folder->getRelativePath($node->getPath());
		if ($path === null) {
			throw \OCA\Mcp\Modules\Deck\Attachments\CardFiles::unavailable();
		}
		return ['node' => $node, 'metadata' => ['fileId' => $fileId, 'name' => $node->getName(), 'path' => $path,
			'size' => (int)$node->getSize(), 'etag' => $node->getEtag(), 'owner' => $node->getOwner()?->getUID() ?? '']];
	}

	/**
	 * Name a folder only after visibility, native READ and the expected id hold for every alias.
	 *
	 * A hidden folder, an unreadable folder, a different id, a file and a mixed set return false.
	 * The caller then uses the same refusal as a missing file.
	 *
	 * @param list<\OCP\Files\Node> $nodes Aliases returned for one file id in the caller's tree.
	 * @param int $fileId Stable original node identity requested by the caller.
	 * @return bool True only when every alias is a folder the caller can already read at that id.
	 */
	private function onlyAuthorizedFolders(array $nodes, int $fileId): bool {
		if ($nodes === []) {
			return false;
		}
		foreach ($nodes as $node) {
			if (!$node instanceof Folder || !$this->guard->isVisible($node) || !$node->isReadable()
				|| (int)$node->getId() !== $fileId) {
				return false;
			}
		}
		return true;
	}
}
