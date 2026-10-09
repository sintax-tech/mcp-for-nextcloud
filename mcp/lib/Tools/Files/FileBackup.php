<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCA\Mcp\Service\UserTimezone;
use OCA\Mcp\Tools\Common\NodeAccess;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\Common\LockAwareWrite;
use OCA\Mcp\Tools\ToolFailure;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IConfig;
use OCP\IUserManager;

/**
 * Guards a file edit: versioning must be on, the file writable, unchanged (etag) and not locked against the write
 * by files_lock, and a verified copy of the original must exist in the user's backup folder before the caller may
 * write. The lock is checked before the copy, so a refused write leaves no useless backup behind.
 */
class FileBackup {
    /** Folder in the user's root that receives a copy of every file before files_edit writes it. */
    public const FOLDER = 'MCP backups';

    public function __construct(
        private IAppManager $appManager,
        private IUserManager $userManager,
        private ITimeFactory $time,
        private IConfig $config,
        private ?\OCA\Mcp\Service\VisibilityGuard $visibilityGuard = null,
        private ?LockAwareWrite $locks = null,
    ) {}

    /**
     * @param string $path normalized user-relative path
     * @return bool whether the path is the backup folder or inside it
     */
    public static function isBackupPath(string $path): bool {
        return $path === '/' . self::FOLDER || str_starts_with($path, '/' . self::FOLDER . '/');
    }

    /**
     * Runs every precondition in order; any failure leaves the original untouched.
     *
     * @param Folder $root the user's folder
     * @param File $file file about to be overwritten
     * @param string $path normalized user-relative path of $file
     * @param string $userId authenticated user
     * @param string|null $etag ETag the caller read before, null to skip the check
     * @param bool $asUser whether the write runs as $userId; false on the checkout route without user session
     * @return string user-relative path of the verified backup copy
     * @throws ToolFailure when versioning is off, the file is not writable, the etag differs, a lock refuses the
     *         write ({@see \OCA\Mcp\Tools\Common\LockWriteFailure}) or the copy fails
     */
    public function prepare(Folder $root, File $file, string $path, string $userId, ?string $etag, bool $asUser = true): string {
        $user = $this->userManager->get($userId);
        if ($user === null || !$this->appManager->isEnabledForUser('files_versions', $user)) {
            throw new ToolFailure(FilesMessages::versioningOff());
        }
        if (!$file->isUpdateable()) {
            throw new ToolFailure(CommonMessages::forbidden());
        }
        NodeAccess::checkEtag($file, $etag);
        $this->locks?->assertWritable($file, $userId, $path, $asUser);
        return $this->copy($root, $file, $path, $userId);
    }

    /**
     * Copies the original next to its mirrored path in the backup folder and checks the copy's size.
     *
     * @param Folder $root the user's folder
     * @param File $file file about to be overwritten
     * @param string $path normalized user-relative path of $file
     * @param string $userId authenticated user, whose timezone stamps the copy
     * @throws ToolFailure when the copy cannot be created or its size differs
     */
    private function copy(Folder $root, File $file, string $path, string $userId): string {
        try {
            $folder = NodeAccess::ensureFolder($root, self::FOLDER . dirname($path));
            $base = $file->getName() . '.' . $this->stamp($userId);
            $name = $base . '.bak';
            for ($i = 2; $folder->nodeExists($name); $i++) {
                $name = $base . '-' . $i . '.bak';
            }
            // Establish privacy while the new backup contains no sensitive bytes.
            $copy = $folder->newFile($name);
            $this->visibilityGuard?->copyHiddenTags($file, $copy);
            $stream = $file->fopen('rb');
            if (!is_resource($stream)) {
                throw new \RuntimeException('Backup source stream unavailable');
            }
            try {
                $copy->putContent($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $valid = $copy->getSize() === $file->getSize();
        } catch (\Throwable) {
            $valid = false;
        }
        if (!$valid) {
            throw new ToolFailure(FilesMessages::backupFailed());
        }
        return $root->getRelativePath($copy->getPath()) ?? '';
    }

    /**
     * Renders ITimeFactory's instant as `Ymd-His` in the timezone of the user (see timezone()).
     *
     * @param string $userId authenticated user, deciding the timezone
     * @return string backup stamp, e.g. `20260921-111320`
     */
    private function stamp(string $userId): string {
        return (new \DateTimeImmutable('@' . $this->time->getTime()))
            ->setTimezone($this->timezone($userId))
            ->format('Ymd-His');
    }

    /**
     * Resolves the timezone for backup names, with the precedence of {@see UserTimezone}.
     *
     * @param string $userId authenticated user
     * @return \DateTimeZone timezone to render the stamp in
     */
    private function timezone(string $userId): \DateTimeZone {
        return (new UserTimezone($this->config))->forUser($userId);
    }
}
