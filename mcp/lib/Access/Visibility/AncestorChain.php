<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Visibility;

use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\SystemTag\ISystemTagObjectMapper;

/** Evaluates mounted tag inheritance and resolves mount-boundary parents through the native tree. */
final class AncestorChain {
	/** @var array<string, bool> Node decisions keyed by file ID and mounted path. */
	private array $cache = [];
	/** @var array<string, true> Complete mounted chains checked for tags, not for backup visibility. */
	private array $tagClean = [];

	/**
	 * Bind ancestor dependencies without resolving nodes or reading tags.
	 * @param IRootFolder|null $rootFolder Native root used to resolve mount-boundary parents.
	 * @param ISystemTagObjectMapper $tagMapper Native batch tag lookup.
	 * @param VisibilityGuard $guard Actual facade whose virtual parentOf() every walk must use.
	 * @param VisibilityDiagnostics $diagnostics Shared refusal capture and warnings.
	 * @param \Closure(Node): bool $backup Lazy backup mirror check, performed even after a clean tag chain.
	 */
	public function __construct(
		private ?IRootFolder $rootFolder,
		private ISystemTagObjectMapper $tagMapper,
		private VisibilityGuard $guard,
		private VisibilityDiagnostics $diagnostics,
		private \Closure $backup,
	) {
	}

	/**
	 * Evaluate the mounted chain after configuration and local-share checks, retaining batch and cache ordering.
	 * @param Node $node Requested file or folder.
	 * @param list<string> $hiddenTags Valid nonempty selection already read by the facade.
	 * @return bool Whether tags and the backup mirror rule permit this node.
	 * @throws \Throwable When unexpected metadata or a malformed tag batch cannot be read; the facade refuses it.
	 */
	public function isVisible(Node $node, array $hiddenTags): bool {
		$nodeId = (int)$node->getId();
		$nodeKey = $nodeId . ':' . $node->getPath();
		if ($nodeId > 0 && isset($this->cache[$nodeKey])) {
			return $this->cache[$nodeKey];
		}

		// Collect ancestors up to root
		$chain = [];
		$current = $node;
		while (!$current instanceof IRootFolder) {
			$cid = (int)$current->getId();
			if ($cid <= 0 || isset($chain[$cid])) {
				$this->diagnostics->warnVisibilityFailure();
				return false;
			}

			// Mounted aliases can have different ancestors for the same file id.
			$cacheKey = $cid . ':' . $current->getPath();
			if (isset($this->cache[$cacheKey])) {
				if (!$this->cache[$cacheKey]) {
					if ($nodeId > 0) {
						$this->cache[$nodeKey] = false;
					}
					return false;
				}
				break;
			}

			if (isset($this->tagClean[$cacheKey])) {
				break; // tag ancestry only; the node's backup check must still run
			}

			$chain[$cid] = $current;
			try {
				$parent = $this->guard->parentOf($current);
				if (!$parent instanceof Node || $parent === $current || (int)$parent->getId() === $cid) {
					throw new \RuntimeException();
				}
				$current = $parent;
			} catch (\Throwable) {
				$this->diagnostics->warnVisibilityFailure();
				return false;
			}
		}

		// Check tags in batch for unverified chain nodes
		if ($chain !== []) {
			$idsToCheck = array_map(static fn (int $id): string => (string)$id, array_keys($chain));
			try {
				$tagsByObject = $this->tagMapper->getTagIdsForObjects($idsToCheck, 'files');
			} catch (\Throwable) {
				$this->diagnostics->warnVisibilityFailure();
				return false;
			}

			// Check from root down to node
			$reversedChain = array_reverse($chain, true);
			$foundHidden = false;
			foreach ($reversedChain as $cid => $chainNode) {
				$cacheKey = $cid . ':' . $chainNode->getPath();
				if ($foundHidden) {
					$this->cache[$cacheKey] = false;
					continue;
				}
				$nodeTags = array_map(strval(...), $tagsByObject[(string)$cid] ?? []);
				foreach ($nodeTags as $tagId) {
					if (in_array((string)$tagId, $hiddenTags, true)) {
						$foundHidden = true;
						$this->cache[$cacheKey] = false;
						if ($cid === $nodeId) {
							$this->diagnostics->because(VisibilityDiagnostics::EXPLAINED_TAG_DIRECT);
						} else {
							$this->diagnostics->because(VisibilityDiagnostics::EXPLAINED_TAG_FOLDER, $chainNode);
						}
						break;
					}
				}
			}

			if ($foundHidden) {
				if ($nodeId > 0) {
					$this->cache[$nodeKey] = false;
				}
				return false;
			}
			// Publish only after the entire mapper result was checked successfully.
			foreach ($chain as $cid => $chainNode) {
				$this->tagClean[$cid . ':' . $chainNode->getPath()] = true;
			}
		}

		// Check /MCP backups mirror rule
		if (($this->backup)($node)) {
			$this->diagnostics->because(VisibilityDiagnostics::EXPLAINED_BACKUP);
			if ($nodeId > 0) {
				$this->cache[$nodeKey] = false;
			}
			return false;
		}

		if ($nodeId > 0) {
			$this->cache[$nodeKey] = true;
		}
		return true;
	}

	/**
	 * Resolve the native parent at a mount boundary, preserving the cast, exact path and positive file ID checks.
	 * @param Node $node Child whose parent is needed.
	 * @return Node Native parent or the root above the user tree.
	 * @throws \Throwable When a mount-boundary parent cannot be resolved faithfully.
	 */
	public function parentOf(Node $node): Node {
		$parent = $node->getParent();
		if ($parent instanceof IRootFolder || $this->rootFolder === null || !$this->isMountRoot($node)) {
			return $parent;
		}
		$path = dirname((string)$node->getPath());
		$resolved = $this->rootFolder->get($path);
		if (!$resolved instanceof Node || $resolved instanceof IRootFolder
			|| $resolved->getPath() !== $path || (int)$resolved->getId() <= 0) {
			throw new \RuntimeException();
		}
		return $resolved;
	}

	/**
	 * Determine whether the node is its storage mount root, whose native parent ID may belong to another tree.
	 * @param Node $node Candidate boundary node.
	 * @return bool Whether its path matches its nonempty mount point.
	 */
	private function isMountRoot(Node $node): bool {
		$mountPoint = $node->getMountPoint();
		if (!is_object($mountPoint)) {
			return false; // nothing marks a boundary, so the parent core gives is kept, as before
		}
		$mount = rtrim((string)$mountPoint->getMountPoint(), '/');
		return $mount !== '' && $mount === rtrim((string)$node->getPath(), '/');
	}

	/**
	 * Forget mounted node decisions and clean tag chains.
	 * @return void
	 */
	public function clearCache(): void {
		$this->cache = [];
		$this->tagClean = [];
	}
}
