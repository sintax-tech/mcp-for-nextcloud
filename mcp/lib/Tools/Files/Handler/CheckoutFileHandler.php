<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files\Handler;

use OCA\Mcp\Tools\Common\FileTypeDenied;
use OCA\Mcp\Tools\Common\LockWriteFailure;
use OCA\Mcp\Service\FileWritePolicyUnavailable;
use OCP\Files\NotFoundException;
use OCA\Mcp\Tools\Files\CheckoutService;
use OCA\Mcp\Tools\Files\FileBackup;
use OCA\Mcp\Tools\Files\FileWriteBoundary;
use OCA\Mcp\Tools\Files\FilesMessages;
use OCA\Mcp\Tools\Common\NodeAccess;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\Common\SharedWriteGuard;
use OCA\Mcp\Tools\Common\PathGuard;
use OCA\Mcp\Tools\Common\LockAwareWrite;
use OCA\Mcp\Tools\Common\FileTypeGuard;
use OCA\Mcp\Service\Scope\FilesAccessGuard;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolResult;
use OCP\Files\Folder;
use OCP\Files\File;

/** Owns files_checkout planning and link emission for the resolved current node. */
final class CheckoutFileHandler {
	/**
	 * @param \OCA\Mcp\Tools\Files\BackupPlan|null $backupPlan effective plan fields
	 * @param CheckoutService $checkout Link service.
	 * @param NodeAccessInfo $accessInfo Existing access description.
	 * @param SharedWriteGuard $guard Existing shared-write confirmation.
	 * @param \OCA\Mcp\Service\BackupLocation|null $backupLocation configured protected roots
	 * @param FilesAccessGuard|null $visibilityGuard Existing scope and exclusion checks.
	 * @param LockAwareWrite|null $locks Existing lock service.
	 * @param FileTypeGuard|null $fileTypes Current file policy.
	 */
	public function __construct(private CheckoutService $checkout, private NodeAccessInfo $accessInfo,
		private SharedWriteGuard $guard, private ?FilesAccessGuard $visibilityGuard,
		private ?LockAwareWrite $locks, private ?FileTypeGuard $fileTypes,
		private ?\OCA\Mcp\Service\BackupLocation $backupLocation = null,
		private ?\OCA\Mcp\Tools\Files\BackupPlan $backupPlan = null) {
	}

	/**
	 * @param Folder $root Owner's root.
	 * @param string $userId Owner.
	 * @param string $path Existing file path.
	 * @param string|null $etag Expected revision.
	 * @return array<string, mixed> Checkout plan without minting links.
	 * @throws NotFoundException If the resolved path is absent.
	 * @throws ToolFailure If access, ETag, versioning or destination checks refuse.
	 * @throws FileTypeDenied If policy refuses the current name.
	 * @throws FileWritePolicyUnavailable If policy cannot be read.
	 * @throws LockWriteFailure If a lock refuses the checkout.
	 */
	public function plan(Folder $root, string $userId, string $path, ?string $etag): array {
		$path = PathGuard::normalize($path);
		if (FileBackup::isBackupPath($path, $this->backupLocation)) {
			throw new ToolFailure(FilesMessages::backupPath());
		}
		$file = $this->file($root, $path);
		FileWriteBoundary::check($this->fileTypes, $file->getName());
		NodeAccess::checkEtag($file, $etag);
		$shared = $this->guard->guard($file, $userId, $path, false);
		// The same refusal the mint gives, so the plan cannot promise links the upload would not honour.
		$this->checkout->assertAvailable($userId);
		return ($this->backupPlan ?? new \OCA\Mcp\Tools\Files\BackupPlan())->decorate([
			'action' => 'files_checkout',
			'path' => $path,
			'etag' => (string)$file->getEtag(),
			'size' => (int)$file->getSize(),
			'mime' => (string)$file->getMimetype(),
			'downloadTtlSeconds' => CheckoutService::DOWNLOAD_TTL,
			'uploadTtlSeconds' => CheckoutService::UPLOAD_TTL,
			'uploadMaxBytes' => $this->checkout->maxBytes(),
			'backup' => '/' . \OCA\Mcp\Service\BackupLocation::LEGACY,
			'access' => $this->accessInfo->describe($file, $userId),
			'shared' => $shared === null ? [] : [$shared],
			'requiresSharedConfirmation' => $shared !== null,
			'recoverable' => true,
			'linksAfterConfirmation' => true,
			'consequence' => FilesMessages::planCheckoutConsequence(),
			'message' => FilesMessages::planCheckout(),
		] + ($this->locks?->planNotice($file, $userId, $path, false) ?? []), $userId);
	}

	/**
	 * @param Folder $root Owner's root.
	 * @param string $userId Owner.
	 * @param string $path Existing file path.
	 * @param string|null $etag Expected revision.
	 * @param bool $confirmed Shared-write confirmation.
	 * @return array<string, mixed> Tool receipt containing links or shared-write confirmation.
	 * @throws NotFoundException If the resolved path is absent.
	 * @throws ToolFailure If access, ETag, versioning or destination checks refuse.
	 * @throws FileTypeDenied If policy refuses the current name.
	 * @throws FileWritePolicyUnavailable If policy cannot be read.
	 * @throws LockWriteFailure If a lock refuses the checkout.
	 */
	public function execute(Folder $root, string $userId, string $path, ?string $etag, bool $confirmed): array {
		$path = PathGuard::normalize($path);
		if (FileBackup::isBackupPath($path, $this->backupLocation)) {
			throw new ToolFailure(FilesMessages::backupPath());
		}
		$file = $this->file($root, $path);
		FileWriteBoundary::check($this->fileTypes, $file->getName());
		NodeAccess::checkEtag($file, $etag);
		// A link for a file the upload could not write is no use: the lock is refused before any link is minted. The
		// upload runs without the user's session, where files_lock refuses even the user's own manual lock.
		$this->locks?->assertWritable($file, $userId, $path, false);
		$access = $this->accessInfo->describe($file, $userId);
		if (($payload = $this->guard->guard($file, $userId, $path, $confirmed)) !== null) {
			return ToolResult::json($payload);
		}
		return ToolResult::json($this->checkout->issue($userId, $file, $path, $access, $confirmed));
	}

	/**
	 * @param Folder $root Owner's root.
	 * @param string $path Resolved path.
	 * @return File Visible file.
	 * @throws NotFoundException If the path is absent.
	 * @throws ToolFailure If the node is hidden, unreadable or is not a file.
	 */
	private function file(Folder $root, string $path): File {
		return NodeAccess::requireFile(NodeAccess::get($root, $path, $this->visibilityGuard));
	}
}
