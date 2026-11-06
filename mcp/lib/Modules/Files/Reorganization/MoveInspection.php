<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Inspect move sources and storage boundaries in the established order. */
namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCA\Mcp\Modules\Files\Backups\BackupWriteBoundary;

/** Preserves the existing MoveInspection operations and refusal order. */
final class MoveInspection {
	/**
	 * Bind the existing collaborators without storing operation state.
	 * @param MoveDestination $moveDestination moveDestination collaborator.
	 * @param ?\OCA\Mcp\Platform\Backups\BackupLocation $backupLocation backupLocation collaborator.
	 * @param ?\OCA\Mcp\Access\ResourceScope\FilesAccessGuard $visibilityGuard visibilityGuard collaborator.
	 * @return void
	 */
	public function __construct(
		private readonly MoveDestination $moveDestination,
		private readonly ?\OCA\Mcp\Platform\Backups\BackupLocation $backupLocation = null,
		private readonly ?\OCA\Mcp\Access\ResourceScope\FilesAccessGuard $visibilityGuard = null,
	) {
	}

	/**
	 * Resolves a move and runs every check on it, without moving anything.
	 *
	 * A single move and a batch plan both come through here on purpose: a plan that accepted a move the
	 * tool would later refuse would send the user to approve something that cannot happen.
	 *
	 * @param Folder $root the user's folder
	 * @param string $from source path, user-relative
	 * @param string $to destination path, user-relative
	 * @param string|null $etag ETag the caller read before, null to skip the check
	 * @param list<string> $mkdirs folders (including their parents) available only while planning
	 * @return Inspection the node, the receiving folder or its existing ancestor, and the normalized destination
	 * @throws \InvalidArgumentException for a path with traversal or control characters
	 * @throws MoveConflict when the destination is taken, the folder would land inside itself, or the two ends are on different storages
	 * @throws ToolFailure when the source is missing, unreadable or not changeable, or the destination cannot receive
	 */
	public function inspect(Folder $root, string $from, string $to, ?string $etag = null, array $mkdirs = []): Inspection {
		$source = $this->writable($root, $from);
		NodeAccess::checkEtag($source, $etag);
		$to = self::notBackup(PathGuard::normalize($to, 'to'), $this->backupLocation);
		// A folder landing inside itself is refused before the destination check, so the user hears the real
		// reason instead of "something is already there" for a subfolder of the folder they are moving.
		$this->assertNotIntoItself($root, $source, $to);
		$this->moveDestination->assertFree($root, $to);
		$destination = $this->moveDestination->destination($root, $to, $mkdirs);
		$this->assertSameStorage($source, $destination);
		return new Inspection($source, $destination, $to);
	}

	/**
	 * @param Folder $root the user's folder
	 * @param string $path source path to resolve and check
	 * @return Node the readable, writable source
	 * @throws ToolFailure when it is in the backup folder, does not exist, cannot be read or cannot be changed
	 */
	public function writable(Folder $root, string $path): Node {
		// run() turns a missing node into a ToolFailure here, so a batch plan can put the item in its
		// denied list instead of the whole call failing.
		$path = self::notBackup(PathGuard::normalize($path), $this->backupLocation);
		$node = NodeAccess::run(fn () => NodeAccess::get($root, $path, $this->visibilityGuard));
		if (!$node->isUpdateable()) {
			throw new ToolFailure(CommonMessages::forbidden());
		}
		return $node;
	}

	/**
	 * The backup folder holds the copies of what MCP overwrote, and they stay hidden only while they sit where the
	 * mirror rule expects them: moving, copying or renaming anything in or into it is refused, like an edit is.
	 *
	 * @param string $path normalized user-relative path, a source or a destination
	 * @return string the same path
	 * @throws ToolFailure when the path is /MCP backups or inside it
	 * @param ?\OCA\Mcp\Platform\Backups\BackupLocation $location location input.
	 */
	public static function notBackup(string $path, ?\OCA\Mcp\Platform\Backups\BackupLocation $location = null): string {
		return BackupWriteBoundary::checked($path, $location);
	}

	/**
	 * @param Folder $root the user's folder
	 * @param Node $from the node being moved
	 * @param string $to destination path, user-relative
	 * @throws ToolFailure when a folder would land inside itself
	 * @return void
	 */
	private function assertNotIntoItself(Folder $root, Node $from, string $to): void {
		$source = $root->getRelativePath($from->getPath()) ?? '';
		if ($from instanceof Folder && ($to === $source || str_starts_with($to, rtrim($source, '/') . '/'))) {
			throw new MoveConflict(\OCA\Mcp\Modules\Files\Presentation\ReorganizationMessages::folderIntoItself());
		}
	}

	/**
	 * Nextcloud moves across storages by copying and deleting, which changes the id and re-versions the
	 * file; the first scope of this sprint stays inside one storage so that the reported id is meaningful.
	 *
	 * @param Node $source the node being moved
	 * @param Folder $destination the folder that would receive it
	 * @throws MoveConflict when source and destination are on different storages
	 * @return void
	 */
	private function assertSameStorage(Node $source, Folder $destination): void {
		if ($source->getStorage()->getId() !== $destination->getStorage()->getId()) {
			throw new MoveConflict(\OCA\Mcp\Modules\Files\Presentation\ReorganizationMessages::crossStorage());
		}
	}
}
