<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Checkout;

use OCA\Mcp\Modules\Files\Editing\EditingMessages;
use OCA\Mcp\Platform\FileAccess\BackupLocation;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\FileAccess\Locks\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;

final class FileCheckout {
    public function __construct(
        private IRootFolder $rootFolder,
        private CheckoutService $checkout,
        private NodeAccessInfo $accessInfo,
        private SharedWriteGuard $guard,
        private FilesAccessGuard $visibilityGuard,
        private ?LockAwareWrite $locks = null,
    ) {}

    public function execute(array $arguments, string $userId): array {
        $root = $this->rootFolder->getUserFolder($userId);
        return NodeAccess::run(fn (): array => $this->checkoutOut($root, $userId, $arguments['path'], $arguments['etag'] ?? null, (bool)($arguments['confirm_shared'] ?? false)));
    }

    public function preview(array $arguments, string $userId): array {
        $root = $this->rootFolder->getUserFolder($userId);
        return NodeAccess::run(fn (): array => $this->planCheckout($root, $userId, $arguments['path'], $arguments['etag'] ?? null));
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
        $path = PathGuard::normalize($path);
        $this->assertNotBackup($path);
        $file = $this->file($root, $path);
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
        $path = PathGuard::normalize($path);
        $this->assertNotBackup($path);
        $file = $this->file($root, $path);
        NodeAccess::checkEtag($file, $etag);
        $shared = $this->guard->guard($file, $userId, $path, false);
        // The same refusal the mint gives, so the plan cannot promise links the upload would not honour.
        $this->checkout->assertAvailable($userId);
        return [
            'action' => 'files_checkout',
            'path' => $path,
            'etag' => (string)$file->getEtag(),
            'size' => (int)$file->getSize(),
            'mime' => (string)$file->getMimetype(),
            'downloadTtlSeconds' => CheckoutService::DOWNLOAD_TTL,
            'uploadTtlSeconds' => CheckoutService::UPLOAD_TTL,
            'uploadMaxBytes' => $this->checkout->maxBytes(),
            'backup' => '/' . BackupLocation::FOLDER,
            'access' => $this->accessInfo->describe($file, $userId),
            'shared' => $shared === null ? [] : [$shared],
            'requiresSharedConfirmation' => $shared !== null,
            'recoverable' => true,
            'linksAfterConfirmation' => true,
            'consequence' => CheckoutMessages::planCheckoutConsequence(),
            'message' => CheckoutMessages::planCheckout(),
        ] + $this->lockNotice($file, $userId, $path, false);
    }

    /**
     * The only refusal files_checkout shares with an edit: the folder a user recovers from is never written.
     * The checkout never reads the bytes, so its type does not matter; the upload limit lives in CheckoutService.
     *
     * @param string $path normalized user-relative path
     * @throws ToolFailure when the path is inside the backup folder
     */
    private function assertNotBackup(string $path): void {
        if (BackupLocation::isBackupPath($path)) {
            throw new ToolFailure(EditingMessages::backupPath());
        }
    }

    /**
     * @param Folder $root the user's folder
     * @param string $path normalized user-relative path
     * @return File the readable node
     * @throws ToolFailure when it is not a file
     */
    private function file(Folder $root, string $path): File {
        return NodeAccess::requireFile(NodeAccess::get($root, $path, $this->visibilityGuard));
    }

    /**
     * @param Node $node the file a plan would change
     * @param string $userId authenticated user
     * @param string $path its user-relative path
     * @param bool $asUser false for the plan of a checkout, whose upload runs without the user's session
     * @return array<string, mixed> the lock and its warning for the plan, nothing for a free file
     */
    private function lockNotice(Node $node, string $userId, string $path, bool $asUser = true): array {
        return $this->locks?->planNotice($node, $userId, $path, $asUser) ?? [];
    }
}
