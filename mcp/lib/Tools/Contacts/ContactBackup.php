<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Contacts;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Service\UserTimezone;
use OCA\Mcp\Tools\Common\NodeAccess;
use OCA\Mcp\Tools\Files\FileBackup;
use OCA\Mcp\Tools\ToolFailure;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use RuntimeException;
use Throwable;

/** A verified, non-overwriting vCard copy in the acting user's Files before permanent CardDAV deletion. */
class ContactBackup {
    /**
     * Receives Files access, the account timezone, clock and backup mutex provider.
     *
     * @param IRootFolder $roots user Files root access
     * @param ITimeFactory $time clock for timestamps
     * @param IConfig $config Nextcloud configuration
     * @param ILockingProvider $locks application mutex provider for unique backups
     * @return void
     */
    public function __construct(
        private IRootFolder $roots,
        private ITimeFactory $time,
        private IConfig $config,
        private ILockingProvider $locks,
    ) {}

    /**
     * Builds the actor-relative backup directory using a safe address-book name.
     *
     * @param string $bookName address-book display name for the backup directory
     * @return string
     */
    public function directory(string $bookName): string {
        return '/' . FileBackup::FOLDER . '/Contacts/' . $this->segment($bookName);
    }

    /**
     * Saves a unique full vCard copy and verifies its content before permitting deletion.
     *
     * @param string $userId authenticated user UID
     * @param string $bookName address-book display name for the backup directory
     * @param string $name contact display name or UID used in the unique backup filename
     * @param string $data complete serialized DAV object
     * @return string actor-relative path of the verified vCard copy
     * @throws ToolFailure when saving or reading back the full backup fails
     */
    public function save(string $userId, string $bookName, string $name, string $data): string {
        $locked = false;
        $lockKey = null;
        try {
            $root = $this->roots->getUserFolder($userId);
            $folder = NodeAccess::ensureFolder($root, $this->directory($bookName));
            // Use a separate application mutex: locking the Files folder would block newFile itself.
            $lockKey = 'mcp-contact-backup/' . hash('sha256', $userId . '\0' . $folder->getPath());
            $this->locks->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
            $locked = true;
            $stamp = $this->time->now()->setTimezone((new UserTimezone($this->config))->forUser($userId))->format('Ymd-His');
            $base = $this->segment($name) . '.' . $stamp . '-' . bin2hex(random_bytes(8));
            $filename = $base . '.vcf';
            for ($i = 2; $folder->nodeExists($filename); $i++) {
                $filename = $base . '-' . $i . '.vcf';
            }
            $copy = $folder->newFile($filename, $data);
            // Read-back equality proves that every original property was captured, not merely its size.
            if ($copy->getContent() !== $data) {
                throw new RuntimeException('Contact backup verification failed');
            }
            $relative = $root->getRelativePath($copy->getPath());
            if ($relative === null || $relative === '') {
                throw new RuntimeException('Contact backup path unavailable');
            }
            return '/' . ltrim($relative, '/');
        } catch (Throwable $e) {
            throw new ToolFailure(
                Translator::t(
                    'The contact backup could not be saved and verified; the contact was not deleted.'
                ),
                0,
                $e
            );
        } finally {
            if ($locked) {
                $this->locks->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
            }
        }
    }

    /**
     * Builds a bounded Files name, replacing separators and reserved characters.
     *
     * @param string $name original contact or address-book name
     * @return string safe non-empty path segment
     */
    private function segment(string $name): string {
        $value = preg_replace('/[\x00-\x1f\x7f\\\\\\/:*?"<>|]/u', '_', $name) ?? '';
        $value = trim(mb_substr($value, 0, 100), " .\t\r\n");
        return $value === '' ? 'contact' : $value;
    }
}
