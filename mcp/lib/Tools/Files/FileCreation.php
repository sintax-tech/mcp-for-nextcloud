<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCA\Mcp\Service\VisibilityGuard;
use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\Common\LockAwareWrite;
use OCA\Mcp\Tools\Common\NodeAccess;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\Common\PathGuard;
use OCA\Mcp\Tools\Common\SharedWriteGuard;
use OCA\Mcp\Tools\ToolFailure;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IFilenameValidator;
use OCP\Files\InvalidPathException;
use OCP\Files\NotEnoughSpaceException;
use OCP\Files\NotFoundException;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * New files: files_upload (any type, through a single-use link) and files_create (small text inline), plus the
 * write the upload route performs when the link is spent.
 *
 * Every path goes through the same target(): the name is one Nextcloud accepts, the folder exists, is visible and
 * is a folder, and the name is free. Nothing here ever writes over an existing file, so there is no backup to take
 * and nothing is ever removed, not even the empty file a failed write may leave behind.
 *
 * Stateless: the caller passes the user's folder on every call.
 */
final class FileCreation {
    /** Extensions files_create writes; anything else goes through files_upload. */
    public const TEXT_EXTENSIONS = ['md', 'txt', 'csv', 'json', 'html', 'xml', 'yaml', 'yml'];
    /** Largest content files_create accepts, in bytes of UTF-8. */
    public const MAX_INLINE_BYTES = 1024 * 1024;
    /** Characters of the content the plan of files_create carries; the renderer shows them as a quote. */
    public const PREVIEW_CHARS = 500;
    /** What the curl command in the result of files_upload names as the local file. */
    private const LOCAL_FILE = '/path/to/local/file';

    public function __construct(
        private NodeAccessInfo $access,
        private SharedWriteGuard $guard,
        private CheckoutService $checkout,
        private IFilenameValidator $filenames,
        private VisibilityGuard $visibilityGuard,
        private LoggerInterface $logger,
        private LockAwareWrite $locks,
    ) {}

    /**
     * The plan of files_upload: the file, its folder and the limit, with no link minted.
     *
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param string $path full path of the new file
     * @param int|null $size declared size of the local file, null when the agent did not say
     * @return array<string, mixed>
     * @throws ArgumentValidationException for a path that names no file Nextcloud accepts
     * @throws ToolFailure for a taken name, a missing or read-only folder, the backup folder or a size over the limit
     */
    public function planUpload(Folder $root, string $userId, string $path, ?int $size): array {
        $target = $this->target($root, $path);
        $shared = $this->guard->guard($target['folder'], $userId, $target['path'], false);
        self::assertCreatable($target['folder']);
        $limit = $this->assertSize($size);
        return [
            'action' => 'files_upload',
            'path' => $target['path'],
            'name' => $target['name'],
            'folder' => $target['folderPath'],
        ] + ($size === null ? [] : ['size' => $size]) + [
            'uploadMaxBytes' => $limit,
            'uploadTtlSeconds' => CheckoutService::UPLOAD_TTL,
            'access' => $this->access->describe($target['folder'], $userId),
            'shared' => $shared === null ? [] : [$shared],
            'requiresSharedConfirmation' => $shared !== null,
            'recoverable' => true,
            'linksAfterConfirmation' => true,
            'consequence' => FilesMessages::planNewFileConsequence(),
            'message' => FilesMessages::planUpload(),
        ];
    }

    /**
     * files_upload confirmed: the same checks as the plan, then the single-use link that creates the file.
     *
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param string $path full path of the new file
     * @param int|null $size declared size of the local file
     * @param bool $confirmed whether the caller passed confirm_shared
     * @return array<string, mixed> the link with the curl command, or the shared-write confirmation
     * @throws ArgumentValidationException for a path that names no file Nextcloud accepts
     * @throws ToolFailure as {@see self::planUpload()}
     */
    public function upload(Folder $root, string $userId, string $path, ?int $size, bool $confirmed): array {
        $target = $this->target($root, $path);
        if (($payload = $this->guard->guard($target['folder'], $userId, $target['path'], $confirmed)) !== null) {
            return $payload;
        }
        self::assertCreatable($target['folder']);
        $limit = $this->assertSize($size);
        $link = $this->checkout->issueCreate($userId, $target['path'], $this->access->describe($target['folder'], $userId), $confirmed, $size);
        return [
            'path' => $target['path'],
            'uploadUrl' => $link['upload_url'],
            'method' => 'PUT',
            'expiresAt' => $link['expires_at'],
            'maxBytes' => $limit,
            'curl' => sprintf('curl -sS -T %s -X PUT -H "Content-Type: application/octet-stream" "%s"', self::LOCAL_FILE, $link['upload_url']),
            'message' => FilesMessages::uploadIssued(),
        ];
    }

    /**
     * The plan of files_create: the file, its folder, its size and the beginning of its text.
     *
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param string $path full path of the new text file
     * @param string $content its complete text
     * @return array<string, mixed>
     * @throws ArgumentValidationException for a name Nextcloud refuses or an extension files_create does not write
     * @throws ToolFailure for a content over the limit, a taken name or a missing or read-only folder
     */
    public function planCreate(Folder $root, string $userId, string $path, string $content): array {
        $target = $this->textTarget($root, $path, $content);
        $shared = $this->guard->guard($target['folder'], $userId, $target['path'], false);
        self::assertCreatable($target['folder']);
        $text = mb_scrub($content, 'UTF-8');
        return [
            'action' => 'files_create',
            'path' => $target['path'],
            'name' => $target['name'],
            'folder' => $target['folderPath'],
            'size' => strlen($content),
            'preview' => mb_substr($text, 0, self::PREVIEW_CHARS),
            'previewTruncated' => mb_strlen($text) > self::PREVIEW_CHARS,
            'access' => $this->access->describe($target['folder'], $userId),
            'shared' => $shared === null ? [] : [$shared],
            'requiresSharedConfirmation' => $shared !== null,
            'recoverable' => true,
            'consequence' => FilesMessages::planNewFileConsequence(),
            'message' => FilesMessages::planCreate(),
        ];
    }

    /**
     * files_create confirmed: the same checks as the plan, then the file.
     *
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param string $path full path of the new text file
     * @param string $content its complete text
     * @param bool $confirmed whether the caller passed confirm_shared
     * @return array<string, mixed> the receipt of {@see self::receipt()}, or the shared-write confirmation
     * @throws ArgumentValidationException as {@see self::planCreate()}
     * @throws ToolFailure as {@see self::planCreate()}, and {@see FileExists} when the name was taken in between
     */
    public function create(Folder $root, string $userId, string $path, string $content, bool $confirmed): array {
        $target = $this->textTarget($root, $path, $content);
        if (($payload = $this->guard->guard($target['folder'], $userId, $target['path'], $confirmed)) !== null) {
            return $payload;
        }
        self::assertCreatable($target['folder']);
        return $this->receipt($target, $this->write($target['folder'], $target['name'], $content, $userId), $userId);
    }

    /**
     * Where a new file would go, after every check that does not depend on who asks: the name is one Nextcloud
     * accepts, the path is outside the backup folder, the folder exists, is visible and is a folder, and the name
     * is free. The upload route asks again when the link is spent, so a name that appeared or a folder that went
     * away since the plan is refused there too.
     *
     * @param Folder $root the user's folder
     * @param string $path full path of the new file, as the agent wrote it
     * @return array{path:string, name:string, folderPath:string, folder:Folder}
     * @throws ArgumentValidationException for traversal, the root or a name Nextcloud refuses, never echoing it
     * @throws ToolFailure for the backup folder, a missing or hidden folder (one answer for both), a file where the
     *         folder should be, or a hidden node at the name
     * @throws FileExists when the name is taken
     */
    public function target(Folder $root, string $path): array {
        $path = PathGuard::normalize($path);
        if ($path === '/') {
            throw self::invalidName();
        }
        if (FileBackup::isBackupPath($path)) {
            throw new ToolFailure(FilesMessages::backupPath());
        }
        $cut = (int)strrpos($path, '/');
        $name = substr($path, $cut + 1);
        $folderPath = $cut === 0 ? '/' : substr($path, 0, $cut);
        try {
            $this->filenames->validateFilename($name);
        } catch (InvalidPathException) {
            throw self::invalidName();
        }
        $folder = $this->folder($root, $folderPath);
        if ($folder->nodeExists($name)) {
            // As files_mkdir: a hidden node at the name is a denied write, not a name the agent may learn is taken.
            if (!$this->visibilityGuard->isVisible($folder->get($name))) {
                throw new ToolFailure(CommonMessages::forbidden());
            }
            throw new FileExists(FilesMessages::fileExists());
        }
        return ['path' => $path, 'name' => $name, 'folderPath' => $folderPath, 'folder' => $folder];
    }

    /**
     * Creates the file and writes its content, never over a file with somebody else's content.
     *
     * Nextcloud has no create-if-absent: Folder::newFile() with content overwrites a file that appeared since the
     * check. So the file is first created empty — newFile() without content is View::touch(), which keeps the bytes
     * of a file that is already there — and a file that turns out not to be empty belongs to somebody else and is
     * left alone. Right before the content is written the file is read again, and any change since the creation (its
     * id, its ETag, a size) is a 409 that writes nothing.
     *
     * What stays possible: an EMPTY file with the same name that another client created in the instant before ours
     * is taken over, and a client writing that very name between the second read and our write overwrites us or is
     * overwritten as with any other write, its content kept as a version. A write that fails after the empty file
     * appeared leaves it in place; nothing is deleted. A lock taken on the new file before its content arrives
     * (files_lock, also a WebDAV token lock the storage would not refuse) is checked right before the content.
     *
     * @param Folder $folder destination folder, already checked
     * @param string $name file name, already validated
     * @param string|resource $content the bytes; '' creates an empty file
     * @param string|null $userId user the file is written for, whose locks are checked; null skips the check
     * @param bool $asUser whether the write runs as that user; false on the upload link, which has no session
     * @return File the new file
     * @throws FileExists when the name is taken before the creation, or the file changed before the write
     * @throws ArgumentValidationException when the storage refuses the name
     * @throws ToolFailure with the message of a lock ({@see \OCA\Mcp\Tools\Common\LockWriteFailure}) or a full quota, or {@see FilesMessages::createFailed()}
     */
    public function write(Folder $folder, string $name, mixed $content, ?string $userId = null, bool $asUser = true): File {
        if ($folder->nodeExists($name)) {
            throw new FileExists(FilesMessages::fileExists());
        }
        try {
            $file = NodeAccess::requireFile($folder->newFile($name));
        } catch (InvalidPathException) {
            throw self::invalidName();
        }
        if ((int)$file->getSize() > 0) {
            throw new FileExists(FilesMessages::fileExists());
        }
        if ($content === '') {
            return $file;
        }
        $created = [(int)$file->getId(), (string)$file->getEtag()];
        $current = NodeAccess::requireFile($folder->get($name));
        if ([(int)$current->getId(), (string)$current->getEtag()] !== $created || (int)$current->getSize() > 0) {
            throw new FileExists(FilesMessages::fileExists());
        }
        try {
            $put = static fn () => $current->putContent($content);
            $userId === null ? $put() : $this->locks->run($put, $current, $userId, null, $asUser);
        } catch (ToolFailure $e) {
            // Already a readable refusal: a lock found by the check, or one the storage raised, explained.
            throw $e;
        } catch (LockedException|NotEnoughSpaceException $e) {
            // The two failures the agent can act on keep the message NodeAccess::run() gives them everywhere else.
            NodeAccess::run(static fn () => throw $e);
        } catch (\Throwable $e) {
            // Only the class: the message of a storage exception may carry the path.
            $this->logger->error('MCP new file write failed', ['app' => 'mcp', 'exception_class' => $e::class]);
            throw new ToolFailure(FilesMessages::createFailed());
        }
        return $current;
    }

    /**
     * What a new file reports: where it is, what it weighs, its ETag and id as read back after the write.
     *
     * @param array{path:string, name:string, folderPath:string, folder:Folder} $target the target the file was created at
     * @param File $file the file write() returned
     * @param string $userId authenticated user
     * @return array{path:string, size:int, etag:string, fileId:int, access:array<string, mixed>}
     */
    public function receipt(array $target, File $file, string $userId): array {
        try {
            $file = NodeAccess::requireFile($target['folder']->get($target['name']));
        } catch (NotFoundException) {
            // The node just written is the best answer left when the folder cannot be read back.
        }
        return [
            'path' => $target['path'],
            'size' => (int)$file->getSize(),
            'etag' => (string)$file->getEtag(),
            'fileId' => (int)$file->getId(),
            'access' => $this->access->describe($file, $userId),
        ];
    }

    /**
     * @param Folder $folder destination folder
     * @throws ToolFailure when Nextcloud does not let the user add a file there
     */
    public static function assertCreatable(Folder $folder): void {
        if (!$folder->isCreatable()) {
            throw new ToolFailure(CommonMessages::forbidden());
        }
    }

    /**
     * The target of files_create, after the checks of its own arguments: a text extension and at most 1 MB.
     *
     * @return array{path:string, name:string, folderPath:string, folder:Folder}
     * @throws ArgumentValidationException for an extension files_create does not write
     * @throws ToolFailure for a content over the limit, and as {@see self::target()}
     */
    private function textTarget(Folder $root, string $path, string $content): array {
        $extension = strtolower(pathinfo(PathGuard::normalize($path), PATHINFO_EXTENSION));
        if (!in_array($extension, self::TEXT_EXTENSIONS, true)) {
            throw new ArgumentValidationException('Invalid argument: path', 'path', FilesMessages::createExtensionRule());
        }
        if (strlen($content) > self::MAX_INLINE_BYTES) {
            throw new ToolFailure(FilesMessages::createTooLarge(self::MAX_INLINE_BYTES));
        }
        return $this->target($root, $path);
    }

    /**
     * @param Folder $root the user's folder
     * @param string $folderPath normalized path of the destination folder
     * @return Folder the folder, visible and readable
     * @throws ToolFailure the same not-found for a folder that is missing or hidden, and not-a-folder for a file
     */
    private function folder(Folder $root, string $folderPath): Folder {
        try {
            $node = NodeAccess::get($root, $folderPath, $this->visibilityGuard);
        } catch (NotFoundException) {
            throw new ToolFailure(FilesMessages::createFolderMissing());
        } catch (ToolFailure $e) {
            throw $e->getMessage() === CommonMessages::notFound() ? new ToolFailure(FilesMessages::createFolderMissing()) : $e;
        }
        if (!$node instanceof Folder) {
            throw new ToolFailure(FilesMessages::notAFolder());
        }
        return $node;
    }

    /**
     * @param int|null $size declared size of the local file
     * @return int the upload limit in bytes
     * @throws ToolFailure when the declared size is over it
     */
    private function assertSize(?int $size): int {
        $limit = $this->checkout->maxBytes();
        if ($size !== null && $size > $limit) {
            throw new ToolFailure(FilesMessages::uploadTooLarge($limit));
        }
        return $limit;
    }

    /** @return ArgumentValidationException the G4 refusal of a path that names no acceptable file, without the path */
    private static function invalidName(): ArgumentValidationException {
        return new ArgumentValidationException('Invalid argument: path', 'path', FilesMessages::newFileNameRule());
    }
}
