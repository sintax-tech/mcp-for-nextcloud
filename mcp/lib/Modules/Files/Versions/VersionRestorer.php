<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Versions;

use OCA\Mcp\Modules\Files\Editing\EditingMessages;
use OCA\Mcp\Modules\Files\Editing\FileBackup;
use OCA\Mcp\Platform\FileAccess\BackupLocation;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\FileAccess\Locks\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\Locks\LockWriteFailure;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;

final class VersionRestorer {
    /** Bytes held in memory before a version copy spills to temporary storage. */
    private const COPY_MEMORY = 8388608;

    public function __construct(
        private VersionReader $versions,
        private FileBackup $backup,
        private NodeAccessInfo $access,
        private SharedWriteGuard $guard,
        private FilesAccessGuard $visibilityGuard,
        private ?LockAwareWrite $locks = null,
        private ?UserTimezone $timezone = null,
    ) {}

    /**
     * Brings a stored version back, after the same backup files_edit performs.
     *
     * With a lock provider (files_lock) there, the version is read through files_versions and written through the
     * file, like any edit: files_lock refuses the write when the file is locked, and the write goes through the normal
     * versioning of the file, as the versions backend decides it. What this method guarantees about the current
     * content is the backup in /MCP backups, taken before. The core rollback is not used then, because VersionManager::rollback()
     * (Nextcloud 32 to 35, handleAppLocks) catches the refusal of a Text or Office lock and repeats the write inside
     * that app's lock scope, over the document still open in the editor. The file then shows the restore time as its
     * modification time and the change as an edit, where the core rollback would show the version's time and a restore.
     * Without a lock provider no lock can exist, and the core rollback runs as before.
     *
     * The shared-write guard runs in the module before this call, so the confirmation payload is a
     * non-error result produced there and never reaches this class.
     *
     * @param Folder $root the user's folder
     * @param File $file file to restore
     * @param string $path normalized user-relative path of $file
     * @param string $revision version identifier as returned by list()
     * @param string $viewerUid authenticated user
     * @return array{path:string, size:int, etag:string, access:array<string, mixed>, backup:string, version:string}
     * @throws ToolFailure when versioning is off, the version does not exist or cannot be read, the backup fails, a
     *         lock refuses the write ({@see LockWriteFailure}) or the rollback reports failure
     */
    public function restore(Folder $root, File $file, string $path, string $revision, string $viewerUid): array {
        $user = $this->versions->requireVersioning($viewerUid);
        $version = $this->versions->find($user, $file, $revision);
        // The backup refuses a locked file before copying it.
        $copy = $this->backup->prepare($root, $file, $path, $viewerUid, null);
        $this->visibilityGuard->assertVisible($file);
        $manager = $this->versions->manager();
        if ($this->locks !== null && $this->locks->providerAvailable()) {
            $this->writeVersion($manager, $version, $file, $path, $viewerUid, $copy);
        } elseif (NodeAccess::mutate(fn () => $manager->rollback($version), $this->visibilityGuard) === false) {
            throw new ToolFailure(VersionMessages::versionRestoreFailed());
        }
        $node = NodeAccess::requireFile(NodeAccess::get($root, $path, $this->visibilityGuard));
        $this->visibilityGuard->assertVisible($node);
        return [
            'path' => $path,
            'size' => (int)$node->getSize(),
            'etag' => (string)$node->getEtag(),
            'access' => $this->access->describe($node, $viewerUid),
            'backup' => $copy,
            'version' => $revision,
        ];
    }

    /**
     * Writes the content of a version over the file through the node, checking the locks right before.
     *
     * @param object $manager the files_versions manager
     * @param object $version the IVersion to bring back
     * @param File $file file to restore
     * @param string $path normalized user-relative path of $file
     * @param string $viewerUid authenticated user
     * @param string $copy user-relative path of the backup taken before
     * @throws ToolFailure when the version cannot be read or a lock refuses the write
     */
    private function writeVersion(object $manager, object $version, File $file, string $path, string $viewerUid, string $copy): void {
        $stream = $manager->read($version);
        if (!is_resource($stream)) {
            throw new ToolFailure(VersionMessages::versionRestoreFailed());
        }
        $content = null;
        try {
            try {
                $content = fopen('php://temp/maxmemory:' . self::COPY_MEMORY, 'w+');
                if ($content === false || stream_copy_to_stream($stream, $content) === false) {
                    throw new ToolFailure(VersionMessages::versionRestoreFailed());
                }
            } finally {
                // Before the write: the version's file must not be open while the file is written.
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            NodeAccess::mutate(fn () => $this->locks->run(static function () use ($file, $content): void {
                // Rewound inside, so a repeated write by the lock check starts from the first byte.
                rewind($content);
                $file->putContent($content);
            }, $file, $viewerUid, $path), $this->visibilityGuard);
        } catch (LockWriteFailure $e) {
            throw $e->withNote(EditingMessages::originalKept($copy));
        } finally {
            if (is_resource($content)) {
                fclose($content);
            }
        }
    }

    /**
     * The backup folder is refused here for the same reason files_edit refuses it: a restore would roll a
     * backup copy back and then take another backup of that backup, growing the folder on every attempt and
     * editing the very folder a user recovers from.
     *
     * @return array{content: list<array{type:string, text:string}>}
     * @throws ToolFailure when the write is refused or the rollback fails
     */
    public function execute(Folder $root, string $userId, string $path, string $version, bool $confirmed): array {
        $path = PathGuard::normalize($path);
        if (BackupLocation::isBackupPath($path)) {
            throw new ToolFailure(EditingMessages::backupPath());
        }
        $file = $this->file($root, $path);
        $this->locks?->assertWritable($file, $userId, $path);
        if (($payload = $this->guard->guard($file, $userId, $path, $confirmed)) !== null) {
            return ToolResult::json($payload);
        }
        return ToolResult::json($this->restore($root, $file, $path, $version, $userId));
    }

    /**
     * The plan of `files_version_restore`: the version that would come back and the backup taken first.
     *
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param string $path file to roll back, user-relative
     * @param string $version version identifier
     * @return array<string, mixed>
     * @throws ToolFailure when versioning is off, the version is gone, the path is a backup or the write is denied
     */
    public function preview(Folder $root, string $userId, string $path, string $version): array {
        $path = PathGuard::normalize($path);
        if (BackupLocation::isBackupPath($path)) {
            throw new ToolFailure(EditingMessages::backupPath());
        }
        $file = $this->file($root, $path);
        $shared = $this->guard->guard($file, $userId, $path, false);
        return [
            'action' => 'files_version_restore',
            'path' => $path,
            'version' => $this->versions->describe($file, $version, $userId),
            'timezone' => $this->timezone?->forUser($userId)->getName(),
            'current' => ['etag' => (string)$file->getEtag(), 'size' => (int)$file->getSize()],
            'backup' => '/' . BackupLocation::FOLDER,
            'access' => $this->access->describe($file, $userId),
            'shared' => $shared === null ? [] : [$shared],
            'requiresSharedConfirmation' => $shared !== null,
            'recoverable' => true,
            'consequence' => EditingMessages::planBackupConsequence(),
            'message' => VersionMessages::planRestore(),
        ] + $this->lockNotice($file, $userId, $path);
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
