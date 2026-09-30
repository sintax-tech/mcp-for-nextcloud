<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCA\Mcp\Tools\Common\NodeAccess;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\Common\PathGuard;
use OCA\Mcp\Tools\Common\SharedWriteGuard;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolModule;
use OCA\Mcp\Tools\ToolResult;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Files tools: list, search, read, protected edit, snippet replace, local checkout and versions.
 * There is deliberately no delete, move or rename; the only removal in the module is a version rollback,
 * which is a write of new content and therefore always creates a version.
 *
 * Every result that names a node also carries its `access` description, and every write outside the
 * personal scope goes through SharedWriteGuard first.
 */
class FilesModule implements ToolModule {
    /** Characters returned by files_read before the text is truncated. */
    public const MAX_CHARS = 100000;
    /** Maximum size of the new content accepted by files_edit and files_replace, in bytes. */
    public const MAX_EDIT_BYTES = 10 * 1024 * 1024;
    /** Folder in the user's root that receives a copy of every file before an edit writes it. */
    public const BACKUP_FOLDER = FileBackup::FOLDER;
    /** Extra rows fetched by files_search to make up for unreadable matches filtered out afterwards. */
    public const SEARCH_OVERFETCH = 10;

    public function __construct(
        private IRootFolder $rootFolder,
        private TextExtractor $extractor,
        private FileBackup $backup,
        private IUserManager $userManager,
        private IDBConnection $db,
        private NodeAccessInfo $accessInfo,
        private SharedWriteGuard $guard,
        private CheckoutService $checkout,
        private VersionTools $versions,
        private Reorganization $reorganization,
    ) {}

    /** @return list<array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app?:string}> */
    public function definitions(): array {
        $path = ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::path()];
        $etag = ['type' => 'string', 'description' => FilesMessages::etag()];
        $confirmShared = ['type' => 'boolean', 'description' => FilesMessages::confirmShared()];
        $version = ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::version()];
        return [
            ['name' => 'files_list', 'module' => 'files', 'operation' => 'read',
                'description' => FilesMessages::listTool(),
                'inputSchema' => self::schema(['path' => ['type' => 'string', 'default' => '/', 'description' => FilesMessages::path()]])],
            ['name' => 'files_search', 'module' => 'files', 'operation' => 'read',
                'description' => FilesMessages::searchTool(),
                'inputSchema' => self::schema([
                    'query' => ['type' => 'string', 'minLength' => 1, 'description' => 'Termo a buscar'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
                ], ['query'])],
            ['name' => 'files_tree', 'module' => 'files', 'operation' => 'read',
                'description' => FilesMessages::treeTool(),
                'inputSchema' => self::schema([
                    'path' => ['type' => 'string', 'default' => '/', 'description' => FilesMessages::path()],
                    'depth' => ['type' => 'integer', 'minimum' => 1, 'maximum' => Reorganization::MAX_DEPTH, 'default' => Reorganization::MAX_DEPTH],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => Reorganization::MAX_ENTRIES, 'default' => Reorganization::MAX_ENTRIES],
                ])],
            ['name' => 'files_mkdir', 'module' => 'files', 'operation' => 'create',
                'description' => FilesMessages::mkdirTool(),
                'inputSchema' => self::schema(['path' => $path, 'confirm_shared' => $confirmShared], ['path'])],
            ['name' => 'files_read', 'module' => 'files', 'operation' => 'read',
                'description' => FilesMessages::readTool(),
                'inputSchema' => self::schema(['path' => $path], ['path'])],
            ['name' => 'files_edit', 'module' => 'files', 'operation' => 'edit',
                'description' => FilesMessages::editTool(),
                'inputSchema' => self::schema([
                    'path' => $path,
                    'content' => ['type' => 'string', 'description' => FilesMessages::content()],
                    'etag' => $etag,
                    'confirm_shared' => $confirmShared,
                ], ['path', 'content'])],
            ['name' => 'files_replace', 'module' => 'files', 'operation' => 'edit',
                'description' => FilesMessages::replaceTool(),
                'inputSchema' => self::schema([
                    'path' => $path,
                    'old' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::oldSnippet()],
                    'new' => ['type' => 'string', 'description' => FilesMessages::newSnippet()],
                    'etag' => $etag,
                    'confirm_shared' => $confirmShared,
                ], ['path', 'old', 'new'])],
            ['name' => 'files_checkout', 'module' => 'files', 'operation' => 'edit',
                'description' => FilesMessages::checkoutTool(),
                'inputSchema' => self::schema(['path' => $path, 'confirm_shared' => $confirmShared], ['path'])],
            ['name' => 'files_versions_list', 'module' => 'files', 'operation' => 'read', 'app' => VersionTools::APP,
                'description' => FilesMessages::versionsListTool(),
                'inputSchema' => self::schema([
                    'path' => $path,
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => VersionTools::DEFAULT_LIMIT, 'default' => VersionTools::DEFAULT_LIMIT],
                ], ['path'])],
            ['name' => 'files_version_read', 'module' => 'files', 'operation' => 'read', 'app' => VersionTools::APP,
                'description' => FilesMessages::versionReadTool(),
                'inputSchema' => self::schema(['path' => $path, 'version' => $version], ['path', 'version'])],
            ['name' => 'files_version_restore', 'module' => 'files', 'operation' => 'restore', 'app' => VersionTools::APP,
                'description' => FilesMessages::versionRestoreTool(),
                'inputSchema' => self::schema([
                    'path' => $path,
                    'version' => $version,
                    'confirm' => ['type' => 'boolean', 'const' => true, 'description' => FilesMessages::confirm()],
                    'confirm_shared' => $confirmShared,
                ], ['path', 'version', 'confirm'])],
        ];
    }

    /**
     * @param string $name one of the names from definitions()
     * @param array<string, mixed> $arguments already validated against the tool schema, defaults applied
     * @param string $userId authenticated user; only their IRootFolder view is used
     * @return array{content: list<array{type:string, text:string}>, isError?: bool}
     * @throws \InvalidArgumentException for an unknown tool or a path with traversal/control characters
     * @throws ToolFailure for client-safe failures (not found, forbidden, limits, blocked edit)
     */
    public function call(string $name, array $arguments, string $userId): array {
        $root = $this->rootFolder->getUserFolder($userId);
        $confirmed = (bool)($arguments['confirm_shared'] ?? false);
        return NodeAccess::run(fn (): array => match ($name) {
            'files_list' => ToolResult::json($this->list($root, $userId, $arguments['path'])),
            'files_tree' => ToolResult::json($this->reorganization->tree($root, $userId, $arguments['path'], $arguments['depth'], $arguments['limit'])),
            'files_mkdir' => ToolResult::json($this->reorganization->mkdir($root, $userId, $arguments['path'], $confirmed)),
            'files_search' => ToolResult::json($this->search($root, $userId, $arguments['query'], $arguments['limit'])),
            'files_read' => $this->read($root, $userId, $arguments['path']),
            'files_edit' => $this->write($root, $userId, $arguments['path'], $arguments['content'], $arguments['etag'] ?? null, $confirmed),
            'files_replace' => $this->replace($root, $userId, $arguments['path'], $arguments['old'], $arguments['new'], $arguments['etag'] ?? null, $confirmed),
            'files_checkout' => $this->checkoutOut($root, $userId, $arguments['path'], $confirmed),
            'files_versions_list' => ToolResult::json($this->versions->list($root, $this->file($root, $arguments['path']), PathGuard::normalize($arguments['path']), $arguments['limit'], $userId)),
            'files_version_read' => ToolResult::json($this->versions->read($this->file($root, $arguments['path']), PathGuard::normalize($arguments['path']), $arguments['version'], $userId)),
            'files_version_restore' => $this->restore($root, $userId, $arguments['path'], $arguments['version'], $confirmed),
            default => throw new \InvalidArgumentException('Unknown tool'),
        });
    }

    /** @return list<array{name:string, path:string, isDir:bool, size:int, mtime:string, contentType:string, access:array<string, mixed>}> */
    private function list(Folder $root, string $userId, string $path): array {
        $folder = NodeAccess::get($root, $path);
        if (!$folder instanceof Folder) {
            throw new ToolFailure(FilesMessages::notAFolder());
        }
        $entries = array_map(fn (Node $node) => $this->entry($root, $userId, $node), $folder->getDirectoryListing());
        usort($entries, static fn (array $a, array $b) => [$b['isDir'], $a['name']] <=> [$a['isDir'], $b['name']]);
        return $entries;
    }

    /** @return list<array{name:string, path:string, isDir:bool, size:int, mtime:string, contentType:string, access:array<string, mixed>}> */
    private function search(Folder $root, string $userId, string $query, int $limit): array {
        // %, _ and \ in the term are literal: escaped the way core's own file search escapes LIKE terms.
        $pattern = '%' . $this->db->escapeLikeParameter($query) . '%';
        $search = new NameSearchQuery(new NameLikeComparison($pattern), $limit + self::SEARCH_OVERFETCH, $this->userManager->get($userId));
        $out = [];
        foreach ($root->search($search) as $node) {
            if ($node->getPath() === $root->getPath() || !$node->isReadable()) {
                continue;
            }
            $out[] = $this->entry($root, $userId, $node);
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * The text stays in content[0] exactly as it always was; content[1] carries the metadata the client
     * needs to place the text and to know who owns the file.
     *
     * @return array{content: list<array{type:string, text:string}>}
     */
    private function read(Folder $root, string $userId, string $path): array {
        $path = PathGuard::normalize($path);
        $file = $this->file($root, $path);
        $text = $this->extractor->extract($file);
        return $this->text(
            mb_strlen($text) > self::MAX_CHARS ? mb_substr($text, 0, self::MAX_CHARS) . "\n\n" . FilesMessages::textTruncated() : $text,
            ['path' => $path, 'etag' => (string)$file->getEtag(), 'size' => (int)$file->getSize(),
                'mime' => (string)$file->getMimetype(), 'access' => $this->accessInfo->describe($file, $userId)],
        );
    }

    /**
     * @return array{content: list<array{type:string, text:string}>}
     * @throws ToolFailure for a non-text file, an oversized content or a failed write
     */
    private function write(Folder $root, string $userId, string $path, string $content, ?string $etag, bool $confirmed): array {
        $path = PathGuard::normalize($path);
        $this->assertEditable($root, $path);
        if (strlen($content) > self::MAX_EDIT_BYTES) {
            throw new ToolFailure(FilesMessages::editTooLarge(self::MAX_EDIT_BYTES));
        }
        $file = $this->file($root, $path);
        if (($payload = $this->guard->guard($file, $userId, $path, $confirmed)) !== null) {
            return ToolResult::json($payload);
        }
        return ToolResult::json($this->commit($root, $userId, $file, $path, $content, $etag, $this->extractor->extract($file)));
    }

    /**
     * Replaces one snippet, counting and substituting over the RAW bytes.
     *
     * The extracted text is only for the diff: it went through mb_scrub, which drops a BOM and turns every
     * byte of a Latin-1 file into "?", so writing it back would silently corrupt the whole file when the
     * snippet touches one line. `old` therefore has to be valid UTF-8, because a byte sequence the client
     * cannot express as text is not something we can locate safely, and the substitution happens on
     * whatever the file actually holds.
     *
     * @return array{content: list<array{type:string, text:string}>}
     * @throws ToolFailure when the snippet is absent or ambiguous, or the write is refused
     */
    private function replace(Folder $root, string $userId, string $path, string $old, string $new, ?string $etag, bool $confirmed): array {
        $path = PathGuard::normalize($path);
        $this->assertEditable($root, $path);
        if (strlen($new) > self::MAX_EDIT_BYTES) {
            throw new ToolFailure(FilesMessages::editTooLarge(self::MAX_EDIT_BYTES));
        }
        if (!mb_check_encoding($old, 'UTF-8')) {
            throw new ToolFailure(FilesMessages::snippetNotUtf8());
        }
        $file = $this->file($root, $path);
        if (($payload = $this->guard->guard($file, $userId, $path, $confirmed)) !== null) {
            return ToolResult::json($payload);
        }
        $raw = (string)$file->getContent();
        $occurrences = substr_count($raw, $old);
        if ($occurrences === 0) {
            throw new ToolFailure(FilesMessages::snippetMissing());
        }
        if ($occurrences > 1) {
            throw new ToolFailure(FilesMessages::snippetAmbiguous($occurrences));
        }
        // str_replace with a count of 1 cannot touch anything but the single occurrence we just verified.
        $updated = str_replace($old, $new, $raw, $count);
        if ($count !== 1) {
            throw new ToolFailure(FilesMessages::snippetAmbiguous($occurrences));
        }
        return ToolResult::json($this->commit($root, $userId, $file, $path, $updated, $etag, $this->extractor->extract($file)));
    }

    /**
     * @return array{content: list<array{type:string, text:string}>}
     * @throws ToolFailure when the file is not text or the write is refused
     */
    private function checkoutOut(Folder $root, string $userId, string $path, bool $confirmed): array {
        $path = PathGuard::normalize($path);
        $this->assertEditable($root, $path);
        $file = $this->file($root, $path);
        $access = $this->accessInfo->describe($file, $userId);
        if (($payload = $this->guard->guard($file, $userId, $path, $confirmed)) !== null) {
            return ToolResult::json($payload);
        }
        return ToolResult::json($this->checkout->issue($userId, $file, $path, $access, $confirmed));
    }

    /**
     * The backup folder is refused here for the same reason files_edit refuses it: a restore would roll a
     * backup copy back and then take another backup of that backup, growing the folder on every attempt and
     * editing the very folder a user recovers from.
     *
     * @return array{content: list<array{type:string, text:string}>}
     * @throws ToolFailure when the write is refused or the rollback fails
     */
    private function restore(Folder $root, string $userId, string $path, string $version, bool $confirmed): array {
        $path = PathGuard::normalize($path);
        if (FileBackup::isBackupPath($path)) {
            throw new ToolFailure(FilesMessages::backupPath());
        }
        $file = $this->file($root, $path);
        if (($payload = $this->guard->guard($file, $userId, $path, $confirmed)) !== null) {
            return ToolResult::json($payload);
        }
        return ToolResult::json($this->versions->restore($root, $file, $path, $version, $userId));
    }

    /**
     * Backup, write and report: the single place where content actually reaches the file, so files_edit,
     * files_replace and the upload route all get the same preparation.
     *
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param File $file file about to be overwritten
     * @param string $path normalized user-relative path of $file
     * @param string $content new content
     * @param string|null $etag ETag the caller read before, null to skip the check
     * @param string $before content as read, for the diff
     * @return array{path:string, size:int, etag:string, access:array<string, mixed>, backup:string, diff:string}
     * @throws ToolFailure when the preparation or the write fails
     */
    private function commit(Folder $root, string $userId, File $file, string $path, string $content, ?string $etag, string $before): array {
        $copy = $this->backup->prepare($root, $file, $path, $userId, $etag);
        try {
            $file->putContent($content);
        } catch (\Throwable) {
            throw new ToolFailure(FilesMessages::writeFailed($copy));
        }
        $node = NodeAccess::requireFile(NodeAccess::get($root, $path));
        return [
            'path' => $path,
            'size' => strlen($content),
            'etag' => (string)$node->getEtag(),
            'access' => $this->accessInfo->describe($node, $userId),
            'backup' => $copy,
            'diff' => UnifiedDiff::between($before, $content),
        ];
    }

    /**
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param Node $node entry to describe
     * @return array{name:string, path:string, isDir:bool, size:int, mtime:string, contentType:string, access:array<string, mixed>}
     */
    private function entry(Folder $root, string $userId, Node $node): array {
        $isDir = NodeAccess::isFolder($node);
        return [
            'name' => $node->getName(),
            'path' => $root->getRelativePath($node->getPath()) ?? '',
            'isDir' => $isDir,
            'size' => $isDir ? 0 : (int)$node->getSize(),
            'mtime' => gmdate('D, d M Y H:i:s \G\M\T', (int)$node->getMTime()),
            'contentType' => $isDir ? 'httpd/unix-directory' : (string)$node->getMimetype(),
            'access' => $this->accessInfo->describe($node, $userId),
        ];
    }

    /**
     * @param string $text the file text, unchanged
     * @param array<string, mixed> $metadata path, etag and access of the file it came from
     * @return array{content: list<array{type:string, text:string}>}
     */
    private function text(string $text, array $metadata): array {
        return [
            'content' => [
                ['type' => 'text', 'text' => $text],
                ['type' => 'text', 'text' => json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)],
            ],
        ];
    }

    /**
     * @param Folder $root the user's folder
     * @param string $path normalized user-relative path
     * @return File the readable node
     * @throws ToolFailure when it is not a file
     */
    private function file(Folder $root, string $path): File {
        return NodeAccess::requireFile(NodeAccess::get($root, $path));
    }

    /**
     * @param Folder $root the user's folder
     * @param string $path normalized user-relative path
     * @throws ToolFailure when the path is the backup folder or does not hold editable text
     */
    private function assertEditable(Folder $root, string $path): void {
        if (FileBackup::isBackupPath($path)) {
            throw new ToolFailure(FilesMessages::backupPath());
        }
        if (!TextExtractor::isText($this->file($root, $path))) {
            throw new ToolFailure(FilesMessages::notText());
        }
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string> $required
     * @return array<string, mixed>
     */
    private static function schema(array $properties, array $required = []): array {
        $schema = ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
        return $required === [] ? $schema : $schema + ['required' => $required];
    }
}
