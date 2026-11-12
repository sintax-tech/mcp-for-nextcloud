<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Activity\Reading;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Modules\Activity\ActivityReferenceUnavailable;
use OCA\Mcp\Platform\FileAccess\ActorRoot;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IURLGenerator;
use Throwable;

/** Current actor-tree IDs only, all aliases checked before metadata or folder ancestry is exposed. */
final class ActivityFileReferences {
	/**
	 * @param ActivityOriginAccess $access independent Files admission
	 * @param ActorRoot $actorRoot identical protected actor-root contract used by files_list/files_read
	 * @param FilesAccessGuard $guard Files scope, Guests external mounts, exclusions and backups
	 * @param IURLGenerator $urls canonical native viewer route, never an Activity link
	 */
	public function __construct(private ActivityOriginAccess $access, private ActorRoot $actorRoot, private FilesAccessGuard $guard, private IURLGenerator $urls) {}
	/**
	 * @param ActivityReferenceView $view this call only
	 * @return void refuse an explicitly selected missing/hidden folder before the native feed is queried
	 */
	public function selectFolder(ActivityReferenceView $view): void {
		if (!$this->admit($view) || $this->aliases($view, $view->folderId, true) === []) throw new ActivityReferenceUnavailable();
	}
	/**
	 * @param ActivityReferenceView $view this call only
	 * @param list<int> $ids primary object and every recognized parameter reference
	 * @return list<array<string,mixed>>|null every reference is current, accessible and, when requested, a descendant
	 */
	public function project(ActivityReferenceView $view, array $ids): ?array {
		if (!$this->admit($view)) return null;
		$out = [];
		foreach ($ids as $id) {
			$nodes = $this->aliases($view, $id);
			if ($nodes === []) return null;
			if ($view->folderId !== null) $nodes = array_values(array_filter($nodes, fn (Node $node): bool => $this->descendant($view, $node)));
			if ($nodes === []) return null;
			usort($nodes, static fn (Node $a, Node $b): int => $a->getPath() <=> $b->getPath());
			$node = $nodes[0];
			$path = $view->root->getRelativePath($node->getPath());
			if (!is_string($path)) return null;
			$out[] = ['type' => 'file', 'id' => $id, 'name' => $node->getName(), 'path' => '/' . ltrim($path, '/'),
				'url' => $this->urls->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => $id])];
		}
		return $out;
	}
	/**
	 * @param ActivityReferenceView $view call-local admission
	 * @return bool source policy is read before any root or target lookup; unreadable scope propagates
	 */
	private function admit(ActivityReferenceView $view): bool {
		if ($view->filesAllowed !== null) return $view->filesAllowed;
		if (!$this->access->allows($view->actor, 'files')) return $view->filesAllowed = false;
		$this->guard->clearCache();
		$this->guard->assertScopeReadable();
		return $view->filesAllowed = true;
	}
	/**
	 * @param ActivityReferenceView $view authorized actor
	 * @param int $id current native identity
	 * @param bool $folder whether a selected filter must be a folder
	 * @return list<Node> no alias is skipped merely because it is outside the requested folder
	 */
	private function aliases(ActivityReferenceView $view, int $id, bool $folder = false): array {
		try {
			$view->root ??= $this->actorRoot->get($view->actor);
			$nodes = (int)$view->root->getId() === $id ? [$view->root] : $view->root->getById($id);
			if ($nodes === [] || count($nodes) > 50) return [];
			foreach ($nodes as $node) {
				if (!$node instanceof Node || ($folder && !$node instanceof Folder) || (int)$node->getId() !== $id
					|| !$this->guard->isVisible($node) || !$node->isReadable() || $view->root->getRelativePath($node->getPath()) === null) return [];
			}
			return array_values($nodes);
		} catch (PolicyUnavailable $error) { throw $error;
		} catch (Throwable) { return []; }
	}
	/**
	 * @param ActivityReferenceView $view selected current folder ID
	 * @param Node $node already authorized alias
	 * @return bool strict descendant by current IDs, with bounded metadata-only traversal and cycle refusal
	 */
	private function descendant(ActivityReferenceView $view, Node $node): bool {
		if ((int)$node->getId() === $view->folderId) return false;
		$seen = [];
		try {
			for ($depth = 0; $depth < 128; $depth++) {
				if ($node->getPath() === $view->root->getPath()) return false;
				$node = $node->getParent();
				$key = $node->getId() . ':' . $node->getPath();
				if (isset($seen[$key]) || $view->root->getRelativePath($node->getPath()) === null) return false;
				$seen[$key] = true;
				if ((int)$node->getId() === $view->folderId) return true;
			}
		} catch (PolicyUnavailable $error) { throw $error;
		} catch (Throwable) { return false; }
		return false;
	}
}
