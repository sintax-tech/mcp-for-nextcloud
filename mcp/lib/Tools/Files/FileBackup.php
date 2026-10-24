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
use OCA\Mcp\Tools\Common\LockMessages;
use OCA\Mcp\Tools\Common\LockWriteFailure;
use OCA\Mcp\Tools\ToolFailure;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Lock\ManuallyLockedException;

/**
 * Guards a file edit: versioning must be on, the file writable, unchanged (etag) and not locked against the write
 * by files_lock. A verified original copy is required unless the administrator suspended Files copies while
 * native versioning is active for this actor. Locks are checked before any copy.
 */
class FileBackup {
    /** Legacy default name, retained as a compatibility alias; live destinations come from BackupLocation. */
    public const FOLDER = \OCA\Mcp\Service\BackupLocation::LEGACY;

    public function __construct(
        private IAppManager $appManager,
        private IUserManager $userManager,
        private ITimeFactory $time,
        private IConfig $config,
        private ?\OCA\Mcp\Service\VisibilityGuard $visibilityGuard = null,
        private ?LockAwareWrite $locks = null,
        private ?\OCA\Mcp\Service\BackupLocation $backupLocation = null,
        private ?\OCA\Mcp\Service\BackupCopies $backupCopies = null,
    ) {}

    /**
     * @param string $path normalized user-relative path
     * @param \OCA\Mcp\Service\BackupLocation|null $location injected live backup settings
     * @return bool whether the path is the backup folder or inside it
     */
    public static function isBackupPath(string $path, ?\OCA\Mcp\Service\BackupLocation $location = null): bool {
        return \OCA\Mcp\Service\BackupLocation::contains($path, $location);
    }

    /**
     * @param string $path normalized actor-relative path
     * @return void
     */
    public function assertWritablePath(string $path): void {
        BackupWriteBoundary::check($path, $this->backupLocation);
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
     * @return string user-relative verified copy path, or empty string for an authorized suspension
     * @throws ToolFailure when versioning is off, the file is not writable, the etag differs, a lock refuses the
     *         write ({@see \OCA\Mcp\Tools\Common\LockWriteFailure}) or the copy fails
     */
    public function prepare(Folder $root, File $file, string $path, string $userId, ?string $etag, bool $asUser = true): string {
        return (new FileBackupPreparation($this->appManager, $this->userManager, $this->locks, $this->backupCopies, $this->backupLocation))->prepare($file, $path, $userId, $etag, $asUser, fn () => $this->copy($root, $file, $path, $userId, $asUser));
    }

    /**
     * Copies the original next to its mirrored path in the backup folder and checks the copy's size.
     *
     * @param Folder $root the user's folder
     * @param File $file file about to be overwritten
     * @param string $path normalized user-relative path of $file
     * @param string $userId authenticated user, whose timezone stamps the copy
     * @param bool $asUser whether the write runs as $userId; false on the checkout route without user session
     * @throws ToolFailure when the copy cannot be created or its size differs, a {@see LockWriteFailure} when it is locked
     */
    private function copy(Folder $root, File $file, string $path, string $userId, bool $asUser = true): string {
        return (new FileBackupCopy($this->visibilityGuard, $this->locks, $this->backupLocation))->copy($root, $file, $path, $this->stamp($userId), $asUser, $userId);
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
