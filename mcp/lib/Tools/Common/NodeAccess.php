<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Common;

use OCA\Mcp\Tools\ToolFailure;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\ForbiddenException;
use OCP\Files\Node;
use OCP\Files\NotEnoughSpaceException;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Lock\LockedException;
use OCP\Lock\ManuallyLockedException;

/** Maps Nextcloud file exceptions to client-safe failures and shares node helpers between modules. */
final class NodeAccess {
	/**
	 * @template T
	 * @param callable():T $write mutation through the OCP node API
	 * @return T
	 */
	public static function mutate(callable $write, ?\OCA\Mcp\Service\VisibilityGuard $guard = null): mixed {
		try {
			return $write();
		} finally {
			// A storage/hook can throw after it has already changed the tree.
			$guard?->clearCache();
		}
	}

	/**
	 * @template T
	 * @param callable():T $action file operation to run
	 * @return T
	 * @throws ToolFailure mapped from NotFound, NotPermitted/Forbidden, Locked and NotEnoughSpace; a files_lock
	 *         refusal becomes a {@see LockWriteFailure} that only says the file is locked, since nothing here knows
	 *         which file it is: the writes that do know ask {@see LockAwareWrite} for the whole explanation
	 */
	public static function run(callable $action): mixed {
		try {
			return $action();
		} catch (NotFoundException) {
			throw new ToolFailure(CommonMessages::notFound());
		} catch (NotPermittedException|ForbiddenException) {
			throw new ToolFailure(CommonMessages::forbidden());
		} catch (LockedException $e) {
			throw self::lockFailure($e);
		} catch (NotEnoughSpaceException) {
			throw new ToolFailure(CommonMessages::insufficientQuota());
		}
	}

	/**
	 * @param LockedException $e a lock refusal without the file it concerns
	 * @return ToolFailure the files_lock refusal ({@see LockWriteFailure}) or the short transactional retry message
	 */
	public static function lockFailure(LockedException $e): ToolFailure {
		return $e instanceof ManuallyLockedException
			? new LockWriteFailure(LockMessages::lockedWithoutDetails())
			: new ToolFailure(CommonMessages::locked());
	}

	/**
	 * @param Folder $root the user's folder
	 * @param string $path user-relative path
	 * @return Node readable node
	 * @throws InvalidArgumentException for traversal or control characters
	 * @throws \OCP\Files\NotFoundException when the node does not exist
	 * @throws ToolFailure when the node is not readable
	 */
	public static function get(Folder $root, string $path, ?\OCA\Mcp\Service\VisibilityGuard $guard = null): Node {
		$path = PathGuard::normalize($path);
		$node = $path === '/' ? $root : $root->get(ltrim($path, '/'));
		if ($guard !== null) {
			$guard->assertVisible($node);
		}
		if (!$node->isReadable()) {
			throw new ToolFailure(CommonMessages::forbidden());
		}
		return $node;
	}

	/** @return bool whether the node is a folder */
	public static function isFolder(Node $node): bool {
		return $node instanceof Folder || $node->getType() === FileInfo::TYPE_FOLDER;
	}

	/**
	 * @return File the node itself
	 * @throws ToolFailure when the node is a folder
	 */
	public static function requireFile(Node $node): File {
		if (!$node instanceof File) {
			throw new ToolFailure(CommonMessages::notAFile());
		}
		return $node;
	}

	/**
	 * Folder below $root, created segment by segment when missing.
	 *
	 * With a guard, the folders that already exist are crossed by metadata only ({@see VisibilityGuard::isTraversable()}):
	 * an excluded one refuses the whole path like one that does not exist. The deepest one that exists is the
	 * boundary (contract B.5, F5): it must be reachable itself ({@see VisibilityGuard::isVisible()}, which for Files
	 * includes the resource scope), since it receives the first new segment or is the folder returned. All of this
	 * is decided before the first newFolder, so a refusal never leaves folders behind.
	 *
	 * @param Folder $root base folder
	 * @param string $relative path below $root ('' or '/' returns $root)
	 * @param \OCA\Mcp\Service\VisibilityGuard|null $guard the gate of the caller's module; null checks nothing
	 * @return Folder existing or newly created folder
	 * @throws InvalidArgumentException for traversal or control characters
	 * @throws ToolFailure when a file already uses one of the folder names, or not found for a refused path
	 */
	public static function ensureFolder(Folder $root, string $relative, ?\OCA\Mcp\Service\VisibilityGuard $guard = null): Folder {
		$guard?->assertTraversable($root);
		$folder = $root;
		$missing = [];
		foreach (PathGuard::segments(PathGuard::normalize($relative)) as $segment) {
			if ($missing === [] && $folder->nodeExists($segment)) {
				$next = $folder->get($segment);
				$guard?->assertTraversable($next);
				if (!$next instanceof Folder) {
					// Named only when reachable: outside the Files scope it answers like a missing path (D6).
					$guard?->assertVisible($next);
					throw new ToolFailure(CommonMessages::fileInTargetFolderPath());
				}
				$folder = $next;
			} else {
				$missing[] = $segment;
			}
		}
		$guard?->assertVisible($folder);
		foreach ($missing as $segment) {
			$folder = self::mutate(fn () => $folder->newFolder($segment), $guard);
			$guard?->assertVisible($folder);
		}
		return $folder;
	}

	/**
	 * The folder a search or a walk starts from. The user's root is crossed by metadata only: below it the guard
	 * still filters every result, so a Files scope narrows what is found without the root itself being reachable.
	 *
	 * @param Folder $root the user's folder
	 * @param string $path user-relative path
	 * @return Node the node at the path, readable
	 * @throws InvalidArgumentException for traversal or control characters
	 * @throws \OCP\Files\NotFoundException when the node does not exist
	 * @throws ToolFailure when the node may not be reached or read
	 */
	public static function getBase(Folder $root, string $path, ?\OCA\Mcp\Service\VisibilityGuard $guard = null): Node {
		if (PathGuard::normalize($path) !== '/') {
			return self::get($root, $path, $guard);
		}
		$guard?->assertTraversable($root);
		if (!$root->isReadable()) {
			throw new ToolFailure(CommonMessages::forbidden());
		}
		return $root;
	}

	/**
	 * @param Node $node node about to change
	 * @param string|null $etag ETag the caller read before, null to skip the check
	 * @throws ToolFailure when the caller's etag does not match the current one
	 */
	public static function checkEtag(Node $node, ?string $etag): void {
		if ($etag !== null && trim($etag, '"') !== trim((string)$node->getEtag(), '"')) {
			throw new ToolFailure(CommonMessages::conflict());
		}
	}
}
