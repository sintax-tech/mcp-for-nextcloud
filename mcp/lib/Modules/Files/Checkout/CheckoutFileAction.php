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
use OCA\Mcp\Modules\Files\FilesCallContext;
use OCA\Mcp\Modules\Files\BackupPlan;
use OCA\Mcp\Modules\Files\Handler;

/** Runs the operation that starts the local checkout workflow inside the actor view. */
final class CheckoutFileAction {
	/**
	 * @param FilesCallContext $context actor-root admission and node-access mapping
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
		private FilesCallContext $context,
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
	 * @return array<string,mixed> unchanged tool result
	 */
	public function handle(array $arguments, string $userId): array {
		$confirmed = (bool)($arguments['confirm_shared'] ?? false);
		return $this->context->run($userId, fn (Folder $root): array => $this->checkoutOut($root, $userId, $arguments['path'], $arguments['etag'] ?? null, $confirmed));
	}

	/**
	 * Issues the links for any file type: the bytes travel between the client and the controller only.
	 *
	 * @param Folder $root the user's folder
	 * @param string $userId authenticated user
	 * @param string $path file to download and upload, user-relative
	 * @param string|null $etag ETag the plan showed, null to skip the check; a different one mints no link
	 * @param bool $confirmed whether the caller passed confirm_shared
	 * @return array{content: list<array{type:string, text:string}>}
	 * @throws ToolFailure when the path is the backup folder or not a file, the etag is stale, or the write is refused
	 */
	private function checkoutOut(Folder $root, string $userId, string $path, ?string $etag, bool $confirmed): array {
		return (new Handler\CheckoutFileHandler($this->checkout, $this->accessInfo, $this->guard, $this->visibilityGuard, $this->locks, $this->fileTypes, $this->backupLocation, $this->backupPlan))->execute($root, $userId, $path, $etag, $confirmed);
	}
}
