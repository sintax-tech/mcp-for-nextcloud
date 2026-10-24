<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCA\Mcp\Service\Scope\FilesAccessGuard;
use OCA\Mcp\Tools\Common\FileTypeGuard;
use OCA\Mcp\Tools\Common\LockAwareWrite;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\Node;

/** Thin wiring for single copy/move handlers; every native authorization check has one implementation. */
final class CopyMoveContext {
	/**
	 * Bind handler dependencies to the existing native checks and final-name policy.
	 *
	 * @param Reorganization $reorganization Single owner of native inspection, authorization and receipts.
	 * @param NodeAccessInfo $access Ownership descriptions.
	 * @param MoveReport $report Native versions and shares reporting.
	 * @param FilesAccessGuard|null $visibilityGuard Visibility boundary.
	 * @param LockAwareWrite|null $locks Official lock-aware writes.
	 * @param FileTypeGuard|null $fileTypes Injected policy guard; core resolves it when omitted.
	 * @param \OCA\Mcp\Service\BackupLocation|null $backupLocation live protected root names
	 * @return void
	 */
	public function __construct(
		public readonly Reorganization $reorganization,
		public readonly NodeAccessInfo $access,
		public readonly MoveReport $report,
		public readonly ?FilesAccessGuard $visibilityGuard,
		public readonly ?LockAwareWrite $locks,
		private ?FileTypeGuard $fileTypes,
		private ?\OCA\Mcp\Service\BackupLocation $backupLocation = null,
	) {
	}

	/**
	 * Resolve policy without an optional authorization bypass.
	 *
	 * @return FileDestinationPolicy Final-name preflight and sink checks.
	 */
	public function policy(): FileDestinationPolicy {
		return new FileDestinationPolicy($this->fileTypes ?? \OCP\Server::get(FileTypeGuard::class), $this);
	}

	/**
	 * Resolve a readable and changeable source through the native authorization checks.
	 *
	 * @param Folder $root the user's folder
	 * @param string $path source path to resolve and check
	 * @return Node the readable, writable source
	 * @throws ToolFailure when it is in the backup folder, does not exist, cannot be read or cannot be changed
	 */
	public function writable(Folder $root, string $path): Node {
		return $this->reorganization->writable($root, $path);
	}

	/**
	 * Refuse protected backup paths through the shared native check.
	 *
	 * The backup folder holds the copies of what MCP overwrote, and they stay hidden only while they sit where the
	 * mirror rule expects them: moving, copying or renaming anything in or into it is refused, like an edit is.
	 *
	 * @param string $path normalized user-relative path, a source or a destination
	 * @return string the same path
	 * @throws ToolFailure when the path is /MCP backups or inside it
	 */
	public function notBackup(string $path): string {
		return BackupWriteBoundary::checked($path, $this->backupLocation);
	}

	/**
	 * Check destination availability without exposing a hidden collision.
	 *
	 * @param Folder $root the user's folder
	 * @param string $to destination path, user-relative
	 * @return void
	 * @throws ToolFailure when anything already sits there
	 */
	public function assertFree(Folder $root, string $to): void {
		$this->reorganization->assertFree($root, $to);
	}

	/**
	 * Resolve the visible receiving folder or planned existing ancestor.
	 *
	 * @param Folder $root the user's folder
	 * @param string $path destination path, user-relative
	 * @param list<string> $mkdirs planned folders whose existing ancestor supplies permissions and storage
	 * @return Folder the destination folder, or the existing ancestor of a planned folder
	 * @throws ToolFailure when it does not exist or cannot take new entries
	 */
	public function destination(Folder $root, string $path, array $mkdirs = []): Folder {
		return $this->reorganization->destination($root, $path, $mkdirs);
	}

	/**
	 * Both ends of the operation go through the guard: moving a file out of a team folder changes what the
	 * team sees, and moving one into it adds something they did not put there.
	 *
	 * @param Node $source the node being moved or copied
	 * @param string $sourcePath the source, as the user wrote it
	 * @param Folder $destination the folder that would receive it
	 * @param string $destinationPath the destination, as the user wrote it
	 * @param string $userId authenticated user
	 * @param bool $confirmedShared whether the caller passed confirm_shared
	 * @return array<string, mixed>|null the confirmation payload, or null when the change may proceed
	 */
	public function confirmBoth(
		Node $source,
		string $sourcePath,
		Folder $destination,
		string $destinationPath,
		string $userId,
		bool $confirmedShared,
	): ?array {
		return $this->reorganization->confirmBoth($source, $sourcePath, $destination, $destinationPath, $userId, $confirmedShared);
	}

	/**
	 * Which of the given nodes reach people other than the caller, described the way the write would ask.
	 *
	 * @param string $userId authenticated user
	 * @param Node ...$nodes ends of the operation: the source and the folder that would receive it
	 * @return list<array<string, mixed>> the ownership description of each non-personal end, empty when all are personal
	 */
	public function sharedOf(string $userId, Node ...$nodes): array {
		return $this->reorganization->sharedOf($userId, ...$nodes);
	}

	/**
	 * Versions belong to files: a folder has none of its own, and asking anyway would report a count nobody
	 * could check.
	 *
	 * @param Node $node the node to count versions of
	 * @param string $userId authenticated user
	 * @return int|null the version count, or null for a folder or when versioning is off
	 */
	public function versions(Node $node, string $userId): ?int {
		return $this->reorganization->versions($node, $userId);
	}

	/**
	 * Resolve the native absolute destination path through the shared helper.
	 *
	 * @param Folder $root the user's folder
	 * @param string $path user-relative path
	 * @return string the absolute path in the user's own storage, which is what Node::move() takes
	 */
	public function absolute(Folder $root, string $path): string {
		return $this->reorganization->absolute($root, $path);
	}

	/**
	 * Runs a move: checks the locks of the node, and of everything inside a folder, right before it, and explains a
	 * lock refusal of the storage.
	 *
	 * @param callable():Node $write the move
	 * @param Node $node the node it moves
	 * @param string $userId authenticated user
	 * @return Node what the write returned
	 */
	public function locked(callable $write, Node $node, string $userId): Node {
		return $this->reorganization->locked($write, $node, $userId);
	}

}
