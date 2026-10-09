<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCA\Mcp\Tools\Common\LockAwareWrite;
use OCA\Mcp\Tools\Common\LockWriteFailure;
use OCA\Mcp\Tools\Common\NodeAccess;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\ToolFailure;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * The three version tools, built on the files_versions app.
 *
 * Nextcloud 33 exposes no OCP for versions (`lib/public/Files/` has no `Versions/`), so the internal
 * `OCA\Files_Versions\Versions\IVersionManager` is resolved through the container — injecting it in a
 * constructor would break the `mcp` container wherever files_versions is disabled. Every signature used
 * here was read from stable33: getVersionsForFile and rollback and read in
 * `apps/files_versions/lib/Versions/IVersionBackend.php`, the accessors in `IVersion.php`.
 */
final class VersionTools {
    /** Nextcloud app that owns the versions, and therefore the gate for these tools. */
    public const APP = 'files_versions';
    /** Versions returned when the caller asks for no limit. */
    public const DEFAULT_LIMIT = 50;
    /** Characters returned by files_version_read before the text is truncated. */
    public const MAX_CHARS = 100000;
    /** Class resolved lazily; a string so this file autoloads with files_versions absent. */
    private const MANAGER = 'OCA\Files_Versions\Versions\IVersionManager';

    public function __construct(
        private IAppManager $appManager,
        private IUserManager $userManager,
        private TextExtractor $extractor,
        private FileBackup $backup,
        private NodeAccessInfo $access,
        private ContainerInterface $container,
        private OcrSupport $ocr,
        private ?LockAwareWrite $locks = null,
    ) {}

    /**
     * @param Folder $root the user's folder
     * @param File $file file whose versions are listed
     * @param string $path normalized user-relative path of $file
     * @param int $limit maximum number of versions
     * @param string $viewerUid authenticated user
     * @return array{path:string, access:array<string, mixed>, versions:list<array{revision:string, timestamp:string, size:int, mime:string, name:string}>, truncated:bool}
     * @throws ToolFailure when versioning is off or unreadable
     */
    public function list(Folder $root, File $file, string $path, int $limit, string $viewerUid): array {
        $user = $this->requireVersioning($viewerUid);
        $versions = $this->manager()->getVersionsForFile($user, $file);
        $out = [];
        foreach (array_slice($versions, 0, $limit) as $version) {
            $out[] = [
                'revision' => (string)$version->getRevisionId(),
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z', $version->getTimestamp()),
                'size' => (int)$version->getSize(),
                'mime' => $version->getMimeType(),
                'name' => $version->getSourceFileName(),
            ];
        }
        return [
            'path' => $path,
            'access' => $this->access->describe($file, $viewerUid),
            'versions' => $out,
            'truncated' => count($versions) > count($out),
        ];
    }

    /**
     * @param File $file file the version belongs to
     * @param string $path normalized user-relative path of $file
     * @param string $revision version identifier as returned by list()
     * @param string $viewerUid authenticated user
     * @return array{path:string, version:string, size:int, mime:string, text:string, truncated:bool, text_layer?:bool, ocr_active?:bool, notice?:string}
     * @throws ToolFailure when versioning is off, the version does not exist or cannot be read
     */
    public function read(File $file, string $path, string $revision, string $viewerUid): array {
        $user = $this->requireVersioning($viewerUid);
        $version = $this->find($user, $file, $revision);
        $handle = $this->manager()->read($version);
        if (!is_resource($handle)) {
            throw new ToolFailure(FilesMessages::versionUnreadable());
        }
        try {
            $bytes = (string)stream_get_contents($handle, TextExtractor::MAX_BYTES + 1);
        } finally {
            fclose($handle);
        }
        if (strlen($bytes) > TextExtractor::MAX_BYTES) {
            throw new ToolFailure(FilesMessages::readTooLarge(TextExtractor::MAX_BYTES));
        }
        $mime = $version->getMimeType();
        $name = $version->getSourceFileName() ?: $path;
        $scannable = OcrSupport::canLackText($name, $mime);
        $text = $scannable && str_starts_with(strtolower($mime), 'image/') ? '' : $this->extractor->extractBytes($bytes, $name, $mime);
        if ($scannable && trim($text) === '') {
            $notice = $this->ocr->noTextNotice();
            return ['path' => $path, 'version' => $revision, 'size' => strlen($bytes), 'mime' => $mime, 'text' => $notice,
                'truncated' => false, 'text_layer' => false, 'ocr_active' => $this->ocr->isActive(), 'notice' => $notice];
        }
        return [
            'path' => $path,
            'version' => $revision,
            'size' => strlen($bytes),
            'mime' => $mime,
            'text' => mb_strlen($text) > self::MAX_CHARS ? mb_substr($text, 0, self::MAX_CHARS) . "\n\n" . FilesMessages::textTruncated() : $text,
            'truncated' => mb_strlen($text) > self::MAX_CHARS,
        ];
    }

    /**
     * Describes the version a rollback would bring back, without reading its content and without writing.
     *
     * @param File $file file to be rolled back
     * @param string $revision version identifier as returned by list()
     * @param string $viewerUid authenticated user
     * @return array{revision:string, timestamp:string, size:int, mime:string, name:string}
     * @throws ToolFailure when versioning is off or no version carries that identifier
     */
    public function describe(File $file, string $revision, string $viewerUid): array {
        $version = $this->find($this->requireVersioning($viewerUid), $file, $revision);
        return [
            'revision' => (string)$version->getRevisionId(),
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z', $version->getTimestamp()),
            'size' => (int)$version->getSize(),
            'mime' => $version->getMimeType(),
            'name' => $version->getSourceFileName(),
        ];
    }

    /**
     * Brings a stored version back, after the same backup files_edit performs.
     *
     * With a lock provider (files_lock) there, the version is read through files_versions and written through the
     * file, like any edit: files_lock refuses the write when the file is locked, and the versions app keeps the
     * current content as a version first. The core rollback is not used then, because VersionManager::rollback()
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
        $user = $this->requireVersioning($viewerUid);
        $version = $this->find($user, $file, $revision);
        // The backup refuses a locked file before copying it.
        $copy = $this->backup->prepare($root, $file, $path, $viewerUid, null);
        $manager = $this->manager();
        if ($this->locks !== null && $this->locks->providerAvailable()) {
            $this->writeVersion($manager, $version, $file, $path, $viewerUid, $copy);
        } elseif ($manager->rollback($version) === false) {
            throw new ToolFailure(FilesMessages::versionRestoreFailed());
        }
        $node = NodeAccess::requireFile(NodeAccess::get($root, $path));
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
            throw new ToolFailure(FilesMessages::versionRestoreFailed());
        }
        try {
            $this->locks->run(static fn () => $file->putContent($stream), $file, $viewerUid, $path);
        } catch (LockWriteFailure $e) {
            throw $e->withNote(FilesMessages::originalKept($copy));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @param string $viewerUid authenticated user
     * @return IUser the user the versions belong to
     * @throws ToolFailure when files_versions is not enabled for them
     */
    private function requireVersioning(string $viewerUid): IUser {
        $user = $this->userManager->get($viewerUid);
        if ($user === null || !$this->appManager->isEnabledForUser(self::APP, $user)) {
            throw new ToolFailure(FilesMessages::versionsOff());
        }
        return $user;
    }

    /**
     * @param IUser $user owner of the versions
     * @param File $file file the version belongs to
     * @param string $revision version identifier
     * @return object the IVersion whose revision matches
     * @throws ToolFailure when no version carries that identifier
     */
    private function find(IUser $user, File $file, string $revision): object {
        foreach ($this->manager()->getVersionsForFile($user, $file) as $version) {
            if ((string)$version->getRevisionId() === $revision) {
                return $version;
            }
        }
        throw new ToolFailure(FilesMessages::versionMissing($revision));
    }

    /**
     * @return object the files_versions manager, resolved on first use
     * @throws ToolFailure when the app is enabled but its classes cannot be resolved
     */
    private function manager(): object {
        try {
            $manager = $this->container->get(self::MANAGER);
        } catch (NotFoundExceptionInterface | \RuntimeException) {
            $manager = null;
        }
        if (!is_object($manager)) {
            throw new ToolFailure(FilesMessages::versionsOff());
        }
        return $manager;
    }
}
