<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Editing;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use OCA\Mcp\Platform\FileAccess\BackupLocation;
use OCA\Mcp\Platform\FileAccess\BackupMessages;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\FileAccess\Locks\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\Locks\LockMessages;
use OCA\Mcp\Platform\FileAccess\Locks\LockWriteFailure;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Lock\ManuallyLockedException;

/**
 * Guards a file edit: versioning must be on, the file writable, unchanged (etag) and not locked against the write
 * by files_lock, and a verified copy of the original must exist in the user's backup folder before the caller may
 * write. The lock is checked before the copy, so a refused write leaves no useless backup behind.
 */
class FileBackup {
    public function __construct(
        private IAppManager $appManager,
        private IUserManager $userManager,
        private ITimeFactory $time,
        private IConfig $config,
        private FilesAccessGuard $visibilityGuard,
        private ?LockAwareWrite $locks = null,
    ) {}

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
     *         write ({@see \OCA\Mcp\Platform\FileAccess\Locks\LockWriteFailure}) or the copy fails
     */
    public function prepare(Folder $root, File $file, string $path, string $userId, ?string $etag, bool $asUser = true): string {
        $user = $this->userManager->get($userId);
        if ($user === null || !$this->appManager->isEnabledForUser('files_versions', $user)) {
            throw new ToolFailure(EditingMessages::versioningOff());
        }
        if (!$file->isUpdateable()) {
            throw new ToolFailure(FileAccessMessages::forbidden());
        }
        NodeAccess::checkEtag($file, $etag);
        $this->locks?->assertWritable($file, $userId, $path, $asUser);
        return $this->copy($root, $file, $path, $userId, $asUser);
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
        // The node the copy was working on when it failed, so a lock refusal names the backup and not the original.
        $target = $root;
        try {
            $folder = NodeAccess::mutate(fn () => NodeAccess::ensureFolder($root, BackupLocation::FOLDER . dirname($path)), $this->visibilityGuard);
            $target = $folder;
            $base = $file->getName() . '.' . $this->stamp($userId);
            $name = $base . '.bak';
            for ($i = 2; $folder->nodeExists($name); $i++) {
                $name = $base . '-' . $i . '.bak';
            }
            // Establish privacy while the new backup contains no sensitive bytes.
            $copy = NodeAccess::mutate(fn () => $folder->newFile($name), $this->visibilityGuard);
            $target = $copy;
            $this->visibilityGuard->copyHiddenTags($file, $copy);
            $stream = $file->fopen('rb');
            if (!is_resource($stream)) {
                throw new \RuntimeException('Backup source stream unavailable');
            }
            try {
                // The new copy is checked right before the original goes into it: a WebDAV client may have locked it.
                $put = static fn () => $copy->putContent($stream);
                NodeAccess::mutate(fn () => $this->locks === null ? $put() : $this->locks->run($put, $copy, $userId, null, $asUser), $this->visibilityGuard);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $valid = $copy->getSize() === $file->getSize();
        } catch (LockWriteFailure $e) {
            throw $e->withNote(BackupMessages::failed());
        } catch (ManuallyLockedException $e) {
            // A backup folder locked by files_lock is a lock, and stays one: the checkout answers it with 423.
            $failure = $this->locks?->failure($e, $target, $userId) ?? NodeAccess::lockFailure($e);
            throw ($failure instanceof LockWriteFailure ? $failure : new LockWriteFailure(LockMessages::lockedWithoutDetails()))
                ->withNote(BackupMessages::failed());
        } catch (\Throwable) {
            $valid = false;
        }
        if (!$valid) {
            throw new ToolFailure(BackupMessages::failed());
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
