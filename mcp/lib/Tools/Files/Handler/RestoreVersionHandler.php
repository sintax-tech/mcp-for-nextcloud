<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files\Handler;

use OCA\Mcp\Service\Scope\FilesAccessGuard;
use OCA\Mcp\Service\UserTimezone;
use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Common\LockAwareWrite;
use OCA\Mcp\Tools\Common\NodeAccess;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\Common\PathGuard;
use OCA\Mcp\Tools\Common\SharedWriteGuard;
use OCA\Mcp\Tools\Files\ContentTypeGate;
use OCA\Mcp\Tools\Files\FileBackup;
use OCA\Mcp\Tools\Files\FilesMessages;
use OCA\Mcp\Tools\Files\FilesModule;
use OCA\Mcp\Tools\Files\VersionTools;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolResult;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;

/**
 * Backs `files_version_restore`: the plan and the confirmed restore of one version onto the current file.
 *
 * The backup folder is refused first. A missing version is still reported for a name the policy would allow,
 * because the plan describes the version before it authorizes the current basename. The version's old name
 * and its bytes do not authorize the write. `confirm` and the user id do not bypass the policy.
 */
final class RestoreVersionHandler {
	/**
	 * @param \OCA\Mcp\Tools\Files\BackupPlan|null $backupPlan effective plan fields
	 * @param NodeAccessInfo $access Access description of the file on the plan.
	 * @param SharedWriteGuard $shared Confirmation of a write outside the personal scope.
	 * @param ContentTypeGate $types Current-name policy, one snapshot per boundary.
	 * @param LockAwareWrite|null $locks Refuses a locked file before any backup. Null where files_lock is not wired.
	 * @param \OCA\Mcp\Service\BackupLocation|null $backupLocation configured protected roots
	 * @param FilesAccessGuard|null $visibilityGuard Scope and exclusion tags of the resolved file.
	 */
	public function __construct(
		private NodeAccessInfo $access,
		private SharedWriteGuard $shared,
		private ContentTypeGate $types,
		private ?LockAwareWrite $locks = null,
		private ?FilesAccessGuard $visibilityGuard = null,
		private ?\OCA\Mcp\Service\BackupLocation $backupLocation = null,
		private ?\OCA\Mcp\Tools\Files\BackupPlan $backupPlan = null,
	) {
	}

	/**
	 * The plan of `files_version_restore`: the version that would come back and the backup taken first.
	 *
	 * The key order matches the plan the renderer already reads. There is no top-level etag; the current
	 * etag lives under `current`.
	 *
	 * @param Folder $root The user's folder.
	 * @param string $userId Authenticated user.
	 * @param string $path File to roll back, user-relative.
	 * @param string $version Version identifier.
	 * @param VersionTools $versions Version lookup of the module, so a test that replaced it is the one that answers.
	 * @param UserTimezone|null $timezone Renders the version date in the user's zone. Null leaves the plan field null.
	 * @return array<string, mixed> Plan. Nothing is written and no backup is created.
	 * @throws ArgumentValidationException When {@see PathGuard::normalize()} rejects '..' or a control character.
	 * @throws NotFoundException When {@see NodeAccess::get()} does not find the path.
	 * @throws ToolFailure When versioning is off, the version is gone, the path is a backup, the type is denied or the write is denied.
	 */
	public function plan(Folder $root, string $userId, string $path, string $version, VersionTools $versions, ?UserTimezone $timezone): array {
		$path = PathGuard::normalize($path);
		if (FileBackup::isBackupPath($path, $this->backupLocation)) {
			throw new ToolFailure(FilesMessages::backupPath());
		}
		$file = $this->file($root, $path);
		// A missing version is reported before the type check, so an allowed name keeps that message.
		$described = $versions->describe($file, $version, $userId);
		$this->types->assertCurrentName($file);
		$shared = $this->shared->guard($file, $userId, $path, false);
		return ($this->backupPlan ?? new \OCA\Mcp\Tools\Files\BackupPlan())->decorate([
			'action' => 'files_version_restore',
			'path' => $path,
			'version' => $described,
			'timezone' => $timezone?->forUser($userId)->getName(),
			'current' => ['etag' => (string)$file->getEtag(), 'size' => (int)$file->getSize()],
			'backup' => '/' . \OCA\Mcp\Service\BackupLocation::LEGACY,
			'access' => $this->access->describe($file, $userId),
			'shared' => $shared === null ? [] : [$shared],
			'requiresSharedConfirmation' => $shared !== null,
			'recoverable' => true,
			'consequence' => FilesMessages::planBackupConsequence(),
			'message' => FilesMessages::planRestore(),
		] + ($this->locks?->planNotice($file, $userId, $path) ?? []), $userId);
	}

	/**
	 * Restores one version. The backup folder is refused before the file is resolved, for the same reason
	 * an edit refuses it: a restore would roll a backup copy back and then take another backup of that backup.
	 *
	 * @param Folder $root The user's folder.
	 * @param string $userId Authenticated user.
	 * @param string $path File to roll back, user-relative.
	 * @param string $version Version identifier.
	 * @param bool $confirmed Whether the caller passed confirm_shared. This does not bypass the type policy.
	 * @param VersionTools $versions Restore entry of the module. It receives the backup it was constructed with.
	 * @return array{content: list<array{type:string, text:string}>} The restore report, or the shared-write confirmation.
	 * @throws ArgumentValidationException When {@see PathGuard::normalize()} rejects '..' or a control character.
	 * @throws NotFoundException When {@see NodeAccess::get()} does not find the path.
	 * @throws ToolFailure When the write is refused or the rollback fails.
	 */
	public function execute(Folder $root, string $userId, string $path, string $version, bool $confirmed, VersionTools $versions): array {
		$path = PathGuard::normalize($path);
		if (FileBackup::isBackupPath($path, $this->backupLocation)) {
			throw new ToolFailure(FilesMessages::backupPath());
		}
		$file = $this->file($root, $path);
		$this->locks?->assertWritable($file, $userId, $path);
		// Before the shared-write confirmation and before restore, so a denied name takes no backup.
		$this->types->assertCurrentName($file);
		if (($payload = $this->shared->guard($file, $userId, $path, $confirmed)) !== null) {
			return ToolResult::json($payload);
		}
		return ToolResult::json($versions->restore($root, $file, $path, $version, $userId));
	}

	/**
	 * @param Folder $root The user's folder.
	 * @param string $path Normalized user-relative path.
	 * @return File The readable node.
	 * @throws ArgumentValidationException When {@see PathGuard::normalize()} rejects '..' or a control character.
	 * @throws NotFoundException When {@see NodeAccess::get()} does not find the path.
	 * @throws ToolFailure When it is not a file.
	 */
	private function file(Folder $root, string $path): File {
		return NodeAccess::requireFile(NodeAccess::get($root, $path, $this->visibilityGuard));
	}
}
