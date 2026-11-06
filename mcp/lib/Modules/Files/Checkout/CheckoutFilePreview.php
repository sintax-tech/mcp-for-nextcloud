<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Checkout;

use OCA\Mcp\Platform\FileAccess\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\SharedWriteGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Modules\Files\CheckoutService;
use OCA\Mcp\Modules\Files\FilesPreviewContext;
use OCA\Mcp\Modules\Files\Backups\BackupPlan;
use OCA\Mcp\Modules\Files\Handler;

/** Plans the operation that starts the local checkout workflow, without executing the write. */
final class CheckoutFilePreview {
	/**
	 * @param FilesPreviewContext $context actor-root admission and node-access mapping
	 * @param CheckoutService $checkout checkout token and content workflow
	 * @param NodeAccessInfo $accessInfo node access metadata formatter
	 * @param SharedWriteGuard $guard shared-write confirmation guard
	 * @param ?FilesAccessGuard $visibilityGuard optional resource-scope admission guard
	 * @param ?LockAwareWrite $locks optional native file-lock guard
	 * @param ?\OCA\Mcp\Access\FileTypes\FileTypeGuard $fileTypes optional file-type policy guard
	 * @param ?\OCA\Mcp\Platform\Backups\BackupLocation $backupLocation optional backup destination policy
	 * @param ?BackupPlan $backupPlan optional backup impact planner
	 */
	public function __construct(
		private FilesPreviewContext $context,
		private CheckoutService $checkout,
		private NodeAccessInfo $accessInfo,
		private SharedWriteGuard $guard,
		private ?FilesAccessGuard $visibilityGuard = null,
		private ?LockAwareWrite $locks = null,
		private ?\OCA\Mcp\Access\FileTypes\FileTypeGuard $fileTypes = null,
		private ?\OCA\Mcp\Platform\Backups\BackupLocation $backupLocation = null,
		private ?BackupPlan $backupPlan = null,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged read-only plan
	 */
	public function plan(array $arguments, string $userId): array {
		$etag = $arguments['etag'] ?? null;
		return $this->context->run($userId, fn (Folder $root): array => $this->planCheckout($root, $userId, $arguments['path'], $etag));
	}

	/**
	 * The plan of `files_checkout`: the file the links would be minted for, and nothing else.
	 *
	 * No token is minted here on purpose: a link in an unapproved answer is a write the user never saw.
	 *
	 * @param Folder $root the user's folder
	 * @param string $userId authenticated user
	 * @param string $path file to download and upload, user-relative
	 * @param string|null $etag ETag the caller read before, null to skip the check
	 * @return array<string, mixed>
	 * @throws ToolFailure when the path is the backup folder or not a file, the etag is stale, versioning is off or the write would be refused
	 */
	private function planCheckout(Folder $root, string $userId, string $path, ?string $etag): array {
		return (new Handler\CheckoutFileHandler($this->checkout, $this->accessInfo, $this->guard, $this->visibilityGuard, $this->locks, $this->fileTypes, $this->backupLocation, $this->backupPlan))->plan($root, $userId, $path, $etag);
	}
}
