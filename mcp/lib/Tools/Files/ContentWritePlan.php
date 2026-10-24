<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCA\Mcp\Tools\Common\LockAwareWrite;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\Common\SharedWriteGuard;
use OCA\Mcp\Tools\ToolFailure;
use OCP\Files\File;

/**
 * The fields files_edit and files_replace share on a plan: path, etag, backup folder, access and the lock notice.
 *
 * files_version_restore keeps its own key order and does not use this class.
 */
final class ContentWritePlan {
	/**
	 * @param SharedWriteGuard $shared Confirmation of a write outside the personal scope. Called with confirmed false.
	 * @param NodeAccessInfo $access Who owns the file and which permissions the viewer has.
	 * @param BackupPlan|null $backupPlan configured copy policy and plan wording
	 * @param LockAwareWrite|null $locks Lock notice for the plan. Null where files_lock is not wired.
	 */
	public function __construct(
		private SharedWriteGuard $shared,
		private NodeAccessInfo $access,
		private ?LockAwareWrite $locks = null,
		private ?BackupPlan $backupPlan = null,
	) {
	}

	/**
	 * Appends the shared plan fields to what the specific tool would do. Nothing is written.
	 *
	 * @param File $file File the plan would overwrite.
	 * @param string $userId Authenticated user.
	 * @param string $path Normalized user-relative path of `$file`.
	 * @param array<string, mixed> $plan Action, sizes, diff and message already decided by the tool.
	 * @param bool $asUser False only for a checkout upload, which runs without the user's session.
	 * @return array<string, mixed> `$plan` plus path, etag, backup, access, shared-write fields and the lock notice.
	 * @throws ToolFailure When Nextcloud refuses the update, with or without the shared confirmation.
	 */
	public function describe(File $file, string $userId, string $path, array $plan, bool $asUser = true): array {
		$shared = $this->shared->guard($file, $userId, $path, false);
		return ($this->backupPlan ?? new BackupPlan())->decorate($plan + [
			'path' => $path,
			'etag' => (string)$file->getEtag(),
			'backup' => '/' . \OCA\Mcp\Service\BackupLocation::LEGACY,
			'access' => $this->access->describe($file, $userId),
			'shared' => $shared === null ? [] : [$shared],
			'requiresSharedConfirmation' => $shared !== null,
			'recoverable' => true,
			'consequence' => FilesMessages::planBackupConsequence(),
		] + ($this->locks?->planNotice($file, $userId, $path, $asUser) ?? []), $userId);
	}
}
