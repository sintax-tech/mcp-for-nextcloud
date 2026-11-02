<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Visibility;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\SystemTag\ISystemTagObjectMapper;

/** Preserves exclusion write preflight and privacy tag propagation through the actual facade. */
final class WritePathPolicy {
	/**
	 * Bind write preflight and tag propagation without reading configuration or touching nodes.
	 * @param VisibilityGuard $guard Actual facade for virtual configuration, visibility, parent and invalidation calls.
	 * @param ISystemTagObjectMapper $tagMapper Native tag lookup and assignment service.
	 * @param VisibilityDiagnostics $diagnostics Shared warnings; invalid configuration adds no decision reason here.
	 */
	public function __construct(
		private VisibilityGuard $guard,
		private ISystemTagObjectMapper $tagMapper,
		private VisibilityDiagnostics $diagnostics,
	) {
	}

	/**
	 * Check an existing target and its existing ancestors, refusing unexpected errors without leaking details.
	 * @param Folder $root Native destination base folder.
	 * @param string $path User-relative destination path.
	 * @return bool Whether every applicable existing boundary is visible.
	 */
	public function isPathAllowedForWrite(Folder $root, string $path): bool {
		try {
			return $this->checkPathAllowedForWrite($root, $path);
		} catch (\Throwable) {
			$this->diagnostics->warnVisibilityFailure();
			return false;
		}
	}

	/**
	 * Evaluate configuration, the base, an existing target and the deepest existing ancestor in their original order.
	 * @param Folder $root Native destination base folder.
	 * @param string $path User-relative destination path.
	 * @return bool Whether the path passes the exclusion preflight.
	 * @throws \Throwable On an unreadable base or existence query; the public preflight refuses it.
	 */
	private function checkPathAllowedForWrite(Folder $root, string $path): bool {
		if (!$this->guard->isConfigurationValid()) {
			$this->diagnostics->warnInvalidConfiguration();
			return false;
		}
		$hiddenTags = $this->guard->getHiddenTagIds();
		if ($hiddenTags === []) {
			return true;
		}

		if (!$this->guard->isVisible($root)) {
			return false;
		}
		$relative = trim($path, '/');
		if ($relative !== '' && $root->nodeExists($relative)) {
			try {
				$existing = $root->get($relative);
				if (!$this->guard->isVisible($existing)) {
					return false;
				}
			} catch (\Throwable) {
				return false;
			}
		}

		// Check existing ancestors
		$segments = array_values(array_filter(explode('/', $relative), static fn (string $segment): bool => $segment !== ''));
		while ($segments !== []) {
			array_pop($segments);
			$walk = implode('/', $segments);
			if ($walk === '') {
				break;
			}
			if ($root->nodeExists($walk)) {
				try {
					$ancestor = $root->get($walk);
					if (!$this->guard->isVisible($ancestor)) {
						return false;
					}
				} catch (\Throwable) {
					return false;
				}
				break;
			}
		}

		return true;
	}

	/**
	 * Propagate inherited hidden tags before backup content is written; invalidate virtually only after an assignment attempt.
	 * @param Node $source Original node whose own and ancestor tags are collected.
	 * @param Node $destination Empty backup that must receive the exclusion tags.
	 * @return void
	 * @throws ToolFailure When configuration, ancestry, tags or assignment cannot be confirmed; preserves the original cause.
	 */
	public function copyHiddenTags(Node $source, Node $destination): void {
		if (!$this->guard->isConfigurationValid()) {
			$this->diagnostics->warnInvalidConfiguration();
			throw new ToolFailure(CommonMessages::backupFailed());
		}
		$hiddenTags = $this->guard->getHiddenTagIds();
		if ($hiddenTags === []) {
			return;
		}

		$tagsToAssign = [];
		$current = $source;
		$seen = [];
		while (!$current instanceof IRootFolder) {
			$cid = (int)$current->getId();
			if ($cid <= 0 || isset($seen[$cid])) {
				$this->diagnostics->warnVisibilityFailure();
				throw new ToolFailure(CommonMessages::backupFailed());
			}
			$seen[$cid] = true;
			try {
				$tags = $this->tagMapper->getTagIdsForObjects([(string)$cid], 'files');
				foreach ($tags[(string)$cid] ?? [] as $tagId) {
					if (in_array((string)$tagId, $hiddenTags, true)) {
						$tagsToAssign[(string)$tagId] = true;
					}
				}
				$parent = $this->guard->parentOf($current);
				if (!$parent instanceof Node || $parent === $current || (int)$parent->getId() === $cid) {
					throw new \RuntimeException();
				}
				$current = $parent;
			} catch (\Throwable $e) {
				$this->diagnostics->warnVisibilityFailure();
				throw new ToolFailure(CommonMessages::backupFailed(), 0, $e);
			}
		}

		if ($tagsToAssign !== []) {
			try {
				try {
					$this->tagMapper->assignTags((string)$destination->getId(), 'files', array_map(strval(...), array_keys($tagsToAssign)));
				} finally {
					$this->guard->clearCache();
				}
			} catch (\Throwable $e) {
				$this->diagnostics->warnVisibilityFailure();
				throw new ToolFailure(CommonMessages::backupFailed(), 0, $e);
			}
		}
	}
}
