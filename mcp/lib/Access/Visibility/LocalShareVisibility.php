<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Visibility;

use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\Storage\ISharedStorage;
use OCP\SystemTag\ISystemTagObjectMapper;

/** Checks omitted canonical owner ancestors without changing which native nodes tools access. */
final class LocalShareVisibility {
	/** @var array<int, bool> Owner-tree decisions independent of mounted aliases. */
	private array $ownerCache = [];
	/** @var array<string, array{node: Node, mount: string}|null> Share roots keyed by ancestor ID and mounted path. */
	private array $shareRootCache = [];

	/**
	 * Bind local-share dependencies without resolving owners, nodes or tags.
	 * @param IRootFolder|null $rootFolder Native root for metadata-only owner resolution.
	 * @param ISystemTagObjectMapper $tagMapper Native owner-ancestor tag lookup.
	 * @param VisibilityGuard $guard Actual facade whose virtual parent resolver the walks use.
	 * @param VisibilityDiagnostics $diagnostics Shared diagnostic state and warnings.
	 */
	public function __construct(
		private ?IRootFolder $rootFolder,
		private ISystemTagObjectMapper $tagMapper,
		private VisibilityGuard $guard,
		private VisibilityDiagnostics $diagnostics,
	) {
	}

	/**
	 * Classify local shares before the mounted-node cache and check the omitted owner ancestors.
	 * @param Node $node Mounted file or folder, including descendants on another storage.
	 * @param list<string> $hiddenTags Configured exclusion tag IDs.
	 * @return bool Whether no local share applies, or its owner tree permits access.
	 */
	public function isVisibleThroughLocalShare(Node $node, array $hiddenTags): bool {
		try {
			$current = $node;
			$seen = [];
			$keys = [];
			$shareRoot = null;
			while (!$current instanceof IRootFolder) {
				if (isset($seen[spl_object_id($current)])) {
					throw new \RuntimeException();
				}
				$seen[spl_object_id($current)] = $current;
				$id = (int)$current->getId();
				if ($id > 0) {
					// A file id can occur through both personal and shared paths.
					// Keep classification local to the mounted path, unlike tags.
					$key = $id . ':' . $current->getPath();
					if (array_key_exists($key, $this->shareRootCache)) {
						$known = $this->shareRootCache[$key];
						if ($shareRoot === null || ($known !== null && $known['mount'] === $shareRoot['mount'])) {
							$shareRoot = $known;
						}
						break;
					}
					if ($current->getStorage()->instanceOfStorage(ISharedStorage::class)) {
						$mount = $current->getMountPoint()->getMountPoint();
						if ($mount === '') {
							throw new \RuntimeException();
						}
						if ($shareRoot !== null && $mount !== $shareRoot['mount']) {
							break; // a different mount is not part of this share
						}
						$shareRoot = ['node' => $current, 'mount' => $mount];
					} elseif ($shareRoot !== null) {
						break; // reached the parent outside the shared mount
					}
					$keys[] = $key;
				}
				// Nodes without an id cannot be memoized or resolved by id;
				// continue to their parent so an enclosing share is still checked.
				$parent = $this->guard->parentOf($current);
				if (!$parent instanceof Node || $parent === $current
					|| ($id > 0 && (int)$parent->getId() === $id)) {
					throw new \RuntimeException();
				}
				$current = $parent;
			}
			foreach ($keys as $key) {
				$this->shareRootCache[$key] = $shareRoot;
			}
			// Descendants below this root have the same mounted and owner chain;
			// only the omitted parents above the mount need canonical resolution.
			return $shareRoot === null || $this->isVisibleInOwnerTree($shareRoot['node'], $hiddenTags);
		} catch (\Throwable) {
			$this->diagnostics->warnVisibilityFailure();
			return false;
		}
	}

	/**
	 * Inspect every canonical owner alias by metadata only; failed resolution is refused and retried.
	 * @param Node $node Local shared mount root carrying the source file ID and owner.
	 * @param list<string> $hiddenTags Configured exclusion tag IDs.
	 * @return bool Whether all owner aliases and their ancestors are free of exclusion tags.
	 */
	private function isVisibleInOwnerTree(Node $node, array $hiddenTags): bool {
		try {
			$id = (int)$node->getId();
			if ($id <= 0 || $this->rootFolder === null) {
				throw new \RuntimeException();
			}
			if (isset($this->ownerCache[$id])) {
				return $this->ownerCache[$id];
			}
			$owner = $node->getOwner();
			if ($owner === null || $owner->getUID() === '') {
				throw new \RuntimeException();
			}
			$folder = $this->rootFolder->getUserFolder($owner->getUID());
			$folderId = (int)$folder->getId();
			$origins = $id === $folderId ? [$folder] : $folder->getById($id);
			if ($origins === []) {
				throw new \RuntimeException();
			}
			$ids = [];
			foreach ($origins as $origin) {
				if (!$origin instanceof Node || (int)$origin->getId() !== $id) {
					throw new \RuntimeException();
				}
				$current = $origin;
				$seen = [];
				while (true) {
					$cid = (int)$current->getId();
					if ($cid <= 0 || isset($seen[$cid])) {
						throw new \RuntimeException();
					}
					$seen[$cid] = true;
					$ids[$cid] = (string)$cid;
					if ($cid === $folderId) {
						break; // do not call Root::getParent()
					}
					$current = $this->guard->parentOf($current);
				}
			}
			$tagsByObject = $this->tagMapper->getTagIdsForObjects(array_values($ids), 'files');
			foreach ($ids as $cid) {
				foreach ($tagsByObject[$cid] ?? [] as $tagId) {
					if (in_array((string)$tagId, $hiddenTags, true)) {
						$this->diagnostics->because(VisibilityDiagnostics::EXPLAINED_TAG_SHARE_ORIGIN);
						return $this->ownerCache[$id] = false;
					}
				}
			}
			return $this->ownerCache[$id] = true;
		} catch (\Throwable) {
			$this->diagnostics->warnVisibilityFailure();
			return false; // resolution failures are retried, never cached as visible
		}
	}

	/**
	 * Forget owner-tree decisions and mounted share classifications.
	 * @return void
	 */
	public function clearCache(): void {
		$this->ownerCache = [];
		$this->shareRootCache = [];
	}
}
