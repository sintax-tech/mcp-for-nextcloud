<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCA\Mcp\Tools\Common\LockAwareWrite;
use OCA\Mcp\Tools\Common\NodeAccess;
use OCA\Mcp\Tools\Files\Handler\EditFileHandler;
use OCA\Mcp\Tools\Files\Handler\ReplaceFileHandler;
use OCA\Mcp\Tools\Files\Handler\RestoreVersionHandler;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\Common\PathGuard;
use OCA\Mcp\Tools\Common\SharedWriteGuard;
use OCA\Mcp\Tools\Files\Sharing\ShareAccess;
use OCA\Mcp\Tools\Files\Sharing\ShareLister;
use OCA\Mcp\Tools\Files\Sharing\ShareRemover;
use OCA\Mcp\Tools\Files\Sharing\SharePermission;
use OCA\Mcp\Tools\Files\Sharing\ShareWriter;
use OCA\Mcp\Service\UserTimezone;
use OCA\Mcp\Tools\PlanState;
use OCA\Mcp\Tools\PreviewsWrites;
use OCA\Mcp\Tools\RendersPlans;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolGuideNotes;
use OCA\Mcp\Tools\ToolModule;
use OCA\Mcp\Tools\ToolResult;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCA\Mcp\Service\Scope\FilesAccessGuard;
use OCP\App\IAppManager;
use OCP\Files\Node;
use OCP\FullTextSearch\IFullTextSearchManager;
use OCP\FullTextSearch\Model\ISearchResult;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Files tools: list, search, tree, mkdir, copy, move, batch, read, protected edit, snippet replace, local
 * checkout, versions, undo, new files (upload link and inline text), and the list, creation and removal of the
 * user's own shares.
 *
 * There is deliberately no delete. Moving a node is allowed and happens in Reorganization, behind the
 * storage, destination and shared-write guards; the only removal in the module is the empty folder a batch
 * itself created when it is undone. A version restore is a write of new content, after the same backup as an edit;
 * whether Nextcloud also keeps a version of what it replaces is up to the versioning policy of the backend.
 *
 * Every result that names a node also carries its `access` description, and every write outside the
 * personal scope goes through SharedWriteGuard first.
 *
 * Every writing tool also answers {@see self::preview()}, which the registry asks for whenever the call
 * arrives without `confirm: true`. A plan reads what it needs — the file, its ETag, the diff, the backup
 * that would be created, the node the batch would touch — and writes nothing: no folder is created, no
 * checkout token is minted and no file is backed up before the user has said yes.
 *
 * A file locked by files_lock (open in Text or Office, locked by another person or a WebDAV client) is a warning in
 * the plan and a refusal of the confirmed call, decided before the shared-write confirmation and the backup
 * ({@see LockAwareWrite}). The user's own manual lock lets the write through, as in the Files app.
 */
class FilesModule implements ToolModule, PreviewsWrites, RendersPlans, ToolGuideNotes {
    /** Characters returned by files_read before the text is truncated. */
    public const MAX_CHARS = TextExtractor::MAX_CHARS;
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
        private MovePlanner $planner,
        private BatchStore $batches,
        private ITimeFactory $time,
        private ImageTools $images,
        private OcrSupport $ocr,
        private ?FilesAccessGuard $visibilityGuard = null,
        private ?IAppManager $appManager = null,
        private ?IFullTextSearchManager $ftsManager = null,
        private ?UserTimezone $timezone = null,
        private ?ShareLister $shareLister = null,
        private ?ShareWriter $shareWriter = null,
        private ?ShareRemover $shareRemover = null,
        private ?FileCreation $creation = null,
        private ?LockAwareWrite $locks = null,
        private ?\OCA\Mcp\Tools\Common\FileTypeGuard $fileTypes = null,
        private ?EditFileHandler $editFile = null,
        private ?ReplaceFileHandler $replaceFile = null,
        private ?RestoreVersionHandler $restoreVersion = null,
        private ?\OCA\Mcp\Service\BackupLocation $backupLocation = null,
        private ?BackupPlan $backupPlan = null,
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
                    'query' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::searchQuery()],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25, 'description' => FilesMessages::searchLimit()],
                    'mode' => ['type' => 'string', 'enum' => ['auto', 'name'], 'default' => 'auto', 'description' => FilesMessages::searchMode()],
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
            ['name' => 'files_copy', 'module' => 'files', 'operation' => 'create',
                'description' => FilesMessages::copyTool(),
                'inputSchema' => self::schema([
                    'from' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::path()],
                    'to' => ['type' => 'string', 'minLength' => 1, 'description' => 'Destination path, e.g. /Archived/plan.md'],
                    'etag' => $etag,
                    'confirm_shared' => $confirmShared,
                ], ['from', 'to'])],
            ['name' => 'files_move', 'module' => 'files', 'operation' => 'move',
                'description' => FilesMessages::moveTool(),
                'inputSchema' => self::schema([
                    'from' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::path()],
                    'to' => ['type' => 'string', 'minLength' => 1, 'description' => 'Destination path, e.g. /2026/plan.md'],
                    'etag' => $etag,
                    'confirm_shared' => $confirmShared,
                ], ['from', 'to'])],
            ['name' => 'files_move_batch', 'module' => 'files', 'operation' => 'move',
                'description' => FilesMessages::batchTool(),
                'inputSchema' => self::schema([
                    'moves' => ['type' => 'array', 'minItems' => 1, 'maxItems' => ReorganizationLimits::BATCH_ITEMS,
                        'description' => 'Moves in the order they will be executed.',
                        'items' => ['type' => 'object',
                            'properties' => [
                                'from' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::path()],
                                'to' => ['type' => 'string', 'minLength' => 1, 'description' => 'Destination path.'],
                            ],
                            'required' => ['from', 'to']]],
                    'mkdirs' => ['type' => 'array', 'maxItems' => ReorganizationLimits::BATCH_ITEMS,
                        'items' => ['type' => 'string', 'minLength' => 1],
                        'description' => 'Folders to create before moves.'],
                    'confirm_shared' => $confirmShared,
                ], ['moves'])],
            ['name' => 'files_undo_batch', 'module' => 'files', 'operation' => 'move',
                'description' => FilesMessages::undoTool(),
                'inputSchema' => self::schema([
                    'batch_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The batch_id returned by files_move_batch.'],
                ], ['batch_id'])],
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
                    'old' => ['type' => 'string', 'minLength' => 1, 'maxLength' => UniqueSnippet::MAX_BYTES, 'description' => FilesMessages::oldSnippet()],
                    'new' => ['type' => 'string', 'description' => FilesMessages::newSnippet()],
                    'etag' => $etag,
                    'confirm_shared' => $confirmShared,
                ], ['path', 'old', 'new'])],
            ['name' => 'files_checkout', 'module' => 'files', 'operation' => 'edit',
                'description' => FilesMessages::checkoutTool(),
                'inputSchema' => self::schema(['path' => $path, 'etag' => $etag, 'confirm_shared' => $confirmShared], ['path'])],
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
                    'confirm_shared' => $confirmShared,
                ], ['path', 'version'])],
            ['name' => 'files_image_view', 'module' => 'files', 'operation' => 'read',
                'description' => FilesMessages::imageViewTool(),
                'inputSchema' => self::schema([
                    'path' => $path,
                    'max_size' => ['type' => 'integer', 'minimum' => ImageTools::MIN_MAX_SIZE, 'maximum' => ImageTools::MAX_MAX_SIZE,
                        'default' => ImageTools::DEFAULT_MAX_SIZE, 'description' => FilesMessages::imageMaxSize()],
                ], ['path'])],
            ['name' => 'files_images_view', 'module' => 'files', 'operation' => 'read',
                'description' => FilesMessages::imagesViewTool(),
                'inputSchema' => self::schema([
                    'paths' => ['type' => 'array', 'minItems' => 1, 'maxItems' => ImageTools::BATCH_MAX_ITEMS,
                        'items' => ['type' => 'string', 'minLength' => 1], 'description' => FilesMessages::imagePaths()],
                    'folder' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::imageFolder()],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ImageTools::BATCH_MAX_ITEMS,
                        'default' => ImageTools::BATCH_MAX_ITEMS],
                    'max_size' => ['type' => 'integer', 'minimum' => ImageTools::MIN_MAX_SIZE, 'maximum' => ImageTools::MAX_MAX_SIZE,
                        'default' => ImageTools::DEFAULT_MAX_SIZE, 'description' => FilesMessages::imageMaxSize()],
                ])],
            ['name' => 'files_image_search', 'module' => 'files', 'operation' => 'read',
                'description' => FilesMessages::imageSearchTool(),
                'inputSchema' => self::schema([
                    'query' => ['type' => 'string', 'description' => FilesMessages::imageQuery()],
                    'folder' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::imageFolder()],
                    'modified_after' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::imageModifiedAfter()],
                    'modified_before' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::imageModifiedBefore()],
                    'tag' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::imageTag()],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ImageTools::SEARCH_MAX_LIMIT,
                        'default' => ImageTools::SEARCH_DEFAULT_LIMIT],
                ])],
            ['name' => 'files_list_shares', 'module' => 'files', 'operation' => 'read',
                'description' => FilesMessages::listSharesTool(),
                'inputSchema' => self::schema([
                    'path' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::listSharesPath()],
                    'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => ShareAccess::MAX_OFFSET, 'default' => 0,
                        'description' => FilesMessages::listSharesOffset()],
                ])],
            // One tool for both kinds of share: the registry lets the call in with either grant, and ShareWriter checks
            // the one the recipient needs (share for a person or a group, link for a public link).
            ['name' => 'files_share', 'module' => 'files', 'operation' => 'share', 'grantAnyOf' => ['share', 'link'],
                'destructiveHint' => true,
                'description' => FilesMessages::shareTool(),
                'inputSchema' => self::schema([
                    'path' => $path,
                    'with' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::shareWithParam()],
                    'permission' => ['type' => 'string', 'enum' => SharePermission::LEVELS, 'default' => SharePermission::VIEW,
                        'description' => FilesMessages::sharePermissionParam()],
                    'expires' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::shareExpiresParam()],
                    'note' => ['type' => 'string', 'maxLength' => ShareWriter::NOTE_MAX, 'description' => FilesMessages::shareNoteParam()],
                    'password' => ['type' => 'boolean', 'description' => FilesMessages::sharePasswordParam()],
                    PlanState::ARGUMENT => PlanState::property(),
                ], ['path', 'with'])],
            // Same grants as files_share: the type of the share removed decides which one the call needs, and
            // ShareRemover checks it after it knows whose share it is.
            ['name' => 'files_unshare', 'module' => 'files', 'operation' => 'share', 'grantAnyOf' => ['share', 'link'],
                'destructiveHint' => true,
                'description' => FilesMessages::unshareTool(),
                'inputSchema' => self::schema([
                    'shareId' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128, 'description' => FilesMessages::unshareIdParam()],
                    'path' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::unsharePathParam()],
                    'with' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::unshareWithParam()],
                    PlanState::ARGUMENT => PlanState::property(),
                ])],
            ['name' => 'files_upload', 'module' => 'files', 'operation' => 'create',
                'description' => FilesMessages::uploadTool(),
                'inputSchema' => self::schema([
                    'path' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::newFilePath()],
                    'size' => ['type' => 'integer', 'minimum' => 0, 'description' => FilesMessages::uploadSize()],
                    'confirm_shared' => $confirmShared,
                ], ['path'])],
            ['name' => 'files_create', 'module' => 'files', 'operation' => 'create',
                'description' => FilesMessages::createTool(),
                'inputSchema' => self::schema([
                    'path' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::newFilePath()],
                    'content' => ['type' => 'string', 'description' => FilesMessages::createContent()],
                    'confirm_shared' => $confirmShared,
                ], ['path', 'content'])],
        ];
    }

    /**
     * What the schemas cannot say: how a path is spelled, which tool finds what, and where the writes stop.
     *
     * @return list<string>
     */
    public function guideNotes(): array {
        return FilesGuideNotes::notes();
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
        // A Files scope that cannot be read answers 503 before anything runs, never a narrower or a full view.
        $this->visibilityGuard?->assertScopeReadable();
        $root = $this->rootFolder->getUserFolder($userId);
        $confirmed = (bool)($arguments['confirm_shared'] ?? false);
        return NodeAccess::run(fn (): array => match ($name) {
            'files_list' => ToolResult::json($this->list($root, $userId, $arguments['path'])),
            'files_tree' => ToolResult::json($this->reorganization->tree($root, $userId, $arguments['path'], $arguments['depth'], $arguments['limit'])),
            'files_mkdir' => ToolResult::json($this->reorganization->mkdir($root, $userId, $arguments['path'], $confirmed)),
            'files_copy' => ToolResult::json($this->reorganization->copy($root, $userId, $arguments['from'], $arguments['to'], $arguments['etag'] ?? null, $confirmed)),
            'files_move' => ToolResult::json($this->reorganization->move($root, $userId, $arguments['from'], $arguments['to'], $arguments['etag'] ?? null, $confirmed)),
            'files_move_batch' => ToolResult::json($this->reorganization->batch(
                $root,
                $userId,
                $arguments['moves'],
                $arguments['mkdirs'] ?? [],
                $confirmed,
                $this->planner,
                $this->batches,
                $this->time->getTime(),
            )),
            'files_search' => ToolResult::json($this->search($root, $userId, $arguments['query'], (int)$arguments['limit'], (string)($arguments['mode'] ?? 'auto'))),
            'files_undo_batch' => ToolResult::json($this->reorganization->undoBatch(
                $root,
                $userId,
                $arguments['batch_id'],
                $this->batches,
                $this->time->getTime(),
            )),
            'files_read' => $this->read($root, $userId, $arguments['path']),
            'files_edit' => $this->editFile()->execute($root, $userId, $arguments['path'], $arguments['content'], $arguments['etag'] ?? null, $confirmed, $this->backup),
            'files_replace' => $this->replaceFile()->execute($root, $userId, $arguments['path'], $arguments['old'], $arguments['new'], $arguments['etag'] ?? null, $confirmed, $this->backup),
            'files_checkout' => $this->checkoutOut($root, $userId, $arguments['path'], $arguments['etag'] ?? null, $confirmed),
            'files_versions_list' => ToolResult::json($this->versions->list($root, $this->file($root, $arguments['path']), PathGuard::normalize($arguments['path']), $arguments['limit'], $userId)),
            'files_version_read' => ToolResult::json($this->versions->read($this->file($root, $arguments['path']), PathGuard::normalize($arguments['path']), $arguments['version'], $userId)),
            'files_version_restore' => $this->restoreVersion()->execute($root, $userId, $arguments['path'], $arguments['version'], $confirmed, $this->versions),
            'files_image_view' => $this->images->view($root, $userId, $arguments['path'], $arguments['max_size']),
            'files_images_view' => $this->images->viewMany($root, $userId, $arguments['paths'] ?? null, $arguments['folder'] ?? null, $arguments['limit'], $arguments['max_size']),
            'files_image_search' => ToolResult::json($this->images->search($root, $userId, $arguments)),
            'files_list_shares' => ToolResult::json($this->listShares($root, $userId, $arguments['path'] ?? null, (int)$arguments['offset'])),
            'files_share' => self::shareResult($this->shareWriter()->apply($root, $userId, $arguments)),
            'files_unshare' => ToolResult::json($this->shareRemover()->apply($root, $userId, $arguments)),
            'files_upload' => ToolResult::json((new Handler\UploadFileHandler($this->creation()))->execute($root, $userId, $arguments['path'], $arguments['size'] ?? null, $confirmed)),
            'files_create' => ToolResult::json((new Handler\CreateFileHandler($this->creation()))->execute($root, $userId, $arguments['path'], $arguments['content'], $confirmed)),
            default => throw new \InvalidArgumentException('Unknown tool'),
        });
    }

    /**
     * files_list_shares: the shares of one own node, or one page of every share the user created.
     *
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param string|null $path node whose shares to list, null for every share of the user
     * @param int $offset shares to skip when listing without path
     * @return array<string, mixed> {@see ShareLister::forPath()} or {@see ShareLister::mine()}
     * @throws ToolFailure for a node that is missing, hidden, the root or not the user's own
     */
    private function listShares(Folder $root, string $userId, ?string $path, int $offset): array {
        $lister = $this->shareLister ?? throw new \LogicException('ShareLister is not wired');
        return $path === null ? $lister->mine($root, $userId, $offset) : $lister->forPath($root, $userId, $path);
    }

    /**
     * The result of files_share in text and in structuredContent, so a client that shows either one shows the generated
     * link password, which this result is the only place to carry.
     *
     * @param array<string, mixed> $result what {@see ShareWriter::apply()} returned
     * @return array{content: list<array{type:string, text:string}>, structuredContent: array<string, mixed>}
     */
    private static function shareResult(array $result): array {
        return ToolResult::structured(ToolResult::json($result)['content'][0]['text'], $result);
    }

    /** @return ShareWriter the writer of files_share, which the container always injects */
    private function shareWriter(): ShareWriter {
        return $this->shareWriter ?? throw new \LogicException('ShareWriter is not wired');
    }

    /** @return ShareRemover the remover of files_unshare, which the container always injects */
    private function shareRemover(): ShareRemover {
        return $this->shareRemover ?? throw new \LogicException('ShareRemover is not wired');
    }

    /** @return FileCreation the creator of files_upload and files_create, which the container always injects */
    private function creation(): FileCreation {
        return $this->creation ?? throw new \LogicException('FileCreation is not wired');
    }

    /** @return EditFileHandler the handler of files_edit, which the container always injects */
    private function editFile(): EditFileHandler {
        return $this->editFile ?? throw new \LogicException('EditFileHandler is not wired');
    }

    /** @return ReplaceFileHandler the handler of files_replace, which the container always injects */
    private function replaceFile(): ReplaceFileHandler {
        return $this->replaceFile ?? throw new \LogicException('ReplaceFileHandler is not wired');
    }

    /** @return RestoreVersionHandler the handler of files_version_restore, which the container always injects */
    private function restoreVersion(): RestoreVersionHandler {
        return $this->restoreVersion ?? throw new \LogicException('RestoreVersionHandler is not wired');
    }

    /**
     * {@inheritDoc}
     *
     * Every plan runs the same checks the write runs and reads the same state, so what the user is shown is
     * what the confirmed call would do — including the refusals, which arrive as errors before anything is
     * asked. Nothing here writes: no backup, no folder, no checkout token.
     */
    public function preview(string $name, array $arguments, string $userId): array {
        $this->visibilityGuard?->assertScopeReadable();
        $root = $this->rootFolder->getUserFolder($userId);
        $etag = $arguments['etag'] ?? null;
        return NodeAccess::run(fn (): array => match ($name) {
            'files_mkdir' => $this->reorganization->planMkdir($root, $userId, $arguments['path']),
            'files_copy' => $this->reorganization->planCopy($root, $userId, $arguments['from'], $arguments['to'], $etag),
            'files_move' => $this->reorganization->planMove($root, $userId, $arguments['from'], $arguments['to'], $etag),
            'files_move_batch' => $this->reorganization->planBatch($root, $userId, $arguments['moves'], $arguments['mkdirs'] ?? [], $this->planner),
            'files_undo_batch' => $this->reorganization->planUndoBatch($root, $userId, $arguments['batch_id'], $this->batches, $this->time->getTime()),
            'files_edit' => $this->editFile()->plan($root, $userId, $arguments['path'], $arguments['content'], $etag),
            'files_replace' => $this->replaceFile()->plan($root, $userId, $arguments['path'], $arguments['old'], $arguments['new'], $etag),
            'files_checkout' => $this->planCheckout($root, $userId, $arguments['path'], $etag),
            'files_version_restore' => $this->restoreVersion()->plan($root, $userId, $arguments['path'], $arguments['version'], $this->versions, $this->timezone),
            'files_share' => $this->shareWriter()->plan($root, $userId, $arguments),
            'files_unshare' => $this->shareRemover()->plan($root, $userId, $arguments),
            'files_upload' => (new Handler\UploadFileHandler($this->creation()))->plan($root, $userId, $arguments['path'], $arguments['size'] ?? null),
            'files_create' => (new Handler\CreateFileHandler($this->creation()))->plan($root, $userId, $arguments['path'], $arguments['content']),
            default => throw new \InvalidArgumentException('Unknown tool'),
        });
    }

    /**
     * {@inheritDoc}
     *
     * The words live in {@see FilesPlanRenderer}; this module only hands the plan over.
     */
    public function renderPlan(string $tool, array $plan): ?string {
        return FilesPlanRenderer::render($tool, $plan);
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
        return (new Handler\CheckoutFileHandler($this->checkout, $this->accessInfo, $this->guard, $this->visibilityGuard, $this->locks, $this->fileTypes, $this->backupLocation, $this->backupPlan))->plan($root, $userId, $path, $etag);
    }

    /** @return list<array{name:string, path:string, isDir:bool, size:int, mtime:string, contentType:string, access:array<string, mixed>}> */
    private function list(Folder $root, string $userId, string $path): array {
        // With a Files scope that does not hold the root, "/" lists the selected folders at their real paths: the
        // folders above them are never listed.
        $listing = PathGuard::normalize($path) === '/' ? $this->visibilityGuard?->scopeRoots($root) : null;
        if ($listing === null) {
            $folder = NodeAccess::get($root, $path, $this->visibilityGuard);
            if (!$folder instanceof Folder) {
                throw new ToolFailure(FilesMessages::notAFolder());
            }
            $listing = $folder->getDirectoryListing();
        }
        if ($this->visibilityGuard !== null) {
            $listing = $this->visibilityGuard->filter($listing);
        }
        $entries = array_map(fn (Node $node) => $this->entry($root, $userId, $node), $listing);
        usort($entries, static fn (array $a, array $b) => [$b['isDir'], $a['name']] <=> [$a['isDir'], $b['name']]);
        return $entries;
    }

    /**
     * @return array{search_mode:string, full_text_active:bool, notice?:string, files:list<array{name:string, path:string, isDir:bool, size:int, mtime:string, contentType:string, access:array<string, mixed>, excerpts?:list<string>}>}
     */
    private function search(Folder $root, string $userId, string $query, int $limit, string $mode = 'auto'): array {
        if ($mode === 'name') {
            return [
                'search_mode' => 'name_only',
                'full_text_active' => $this->isFullTextAvailable($userId),
                'files' => $this->searchByName($root, $userId, $query, $limit),
            ];
        }

        if (!$this->isFullTextAvailable($userId)) {
            return [
                'search_mode' => 'name_only',
                'full_text_active' => false,
                'notice' => FilesMessages::searchNameOnlyNotice(),
                'files' => $this->searchByName($root, $userId, $query, $limit),
            ];
        }

        try {
            $results = $this->ftsManager->search([
                'search' => $query,
                'providers' => ['files'],
                'size' => $limit + self::SEARCH_OVERFETCH,
                'page' => 1,
            ], $userId);

            $files = [];
            $seen = [];
            foreach ($results as $result) {
                if (!$result instanceof ISearchResult) {
                    continue;
                }
                foreach ($result->getDocuments() as $doc) {
                    $fileId = (int)$doc->getId();
                    if ($fileId <= 0) {
                        continue;
                    }
                    $nodes = $root->getById($fileId);
                    if ($nodes === []) {
                        continue;
                    }
                    $visibleNodes = ($this->visibilityGuard?->filter($nodes) ?? array_values($nodes));
                    if ($visibleNodes === []) {
                        continue;
                    }
                    $node = $visibleNodes[0];
                    if ($node->getPath() === $root->getPath() || !$node->isReadable()) {
                        continue;
                    }
                    if (isset($seen[$node->getId()])) {
                        continue;
                    }
                    $seen[$node->getId()] = true;

                    $snippets = [];
                    foreach ($doc->getExcerpts() as $item) {
                        if (is_array($item) && isset($item['excerpt']) && is_string($item['excerpt'])) {
                            $snippets[] = trim(strip_tags($item['excerpt']));
                        } elseif (is_string($item)) {
                            $snippets[] = trim(strip_tags($item));
                        }
                    }
                    $snippets = array_values(array_filter($snippets, static fn (string $s) => $s !== ''));

                    $entry = $this->entry($root, $userId, $node);
                    if ($snippets !== []) {
                        $entry['excerpts'] = $snippets;
                    }
                    $files[] = $entry;
                    if (count($files) >= $limit) {
                        break 2;
                    }
                }
            }

            return [
                'search_mode' => 'content',
                'full_text_active' => true,
                'files' => $files,
            ];
        } catch (\Throwable) {
            return [
                'search_mode' => 'name_only',
                'full_text_active' => false,
                'notice' => FilesMessages::searchFallbackNotice(),
                'files' => $this->searchByName($root, $userId, $query, $limit),
            ];
        }
    }

    /**
     * Checks whether the user's full-text apps and indexed Files provider are available.
     *
     * @param string $userId authenticated user whose enabled apps are checked
     * @return bool false when the service is absent or its availability check fails
     */
    private function isFullTextAvailable(string $userId): bool {
        if ($this->appManager === null || $this->ftsManager === null) {
            return false;
        }
        $user = $this->userManager->get($userId);
        if (!$this->appManager->isEnabledForUser('fulltextsearch', $user)
            || !$this->appManager->isEnabledForUser('files_fulltextsearch', $user)) {
            return false;
        }
        try {
            return $this->ftsManager->isAvailable() && $this->ftsManager->isProviderIndexed('files');
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<array{name:string, path:string, isDir:bool, size:int, mtime:string, contentType:string, access:array<string, mixed>}> */
    private function searchByName(Folder $root, string $userId, string $query, int $limit): array {
        // %, _ and \ in the term are literal: escaped the way core's own file search escapes LIKE terms.
        $pattern = '%' . $this->db->escapeLikeParameter($query) . '%';
        $user = $this->userManager->get($userId);
        $out = [];
        $seenIds = [];
        $batchSize = $limit + self::SEARCH_OVERFETCH;
        $maxInspected = 500;
        $offset = 0;
        $totalInspected = 0;

        while (count($out) < $limit && $totalInspected < $maxInspected) {
            $fetchLimit = min($batchSize, $maxInspected - $totalInspected);
            $search = new NameSearchQuery(new NameLikeComparison($pattern), $fetchLimit, $user, [], $offset);
            $batch = $root->search($search);
            $batchCount = 0;
            $newInBatch = 0;

            foreach ($batch as $node) {
                $batchCount++;
                $totalInspected++;
                $id = (int)$node->getId();
                if ($id > 0) {
                    if (isset($seenIds[$id])) {
                        continue;
                    }
                    $seenIds[$id] = true;
                }
                $newInBatch++;

                if ($node->getPath() === $root->getPath() || !$node->isReadable()) {
                    continue;
                }
                if ($this->visibilityGuard !== null && !$this->visibilityGuard->isVisible($node)) {
                    continue;
                }
                $out[] = $this->entry($root, $userId, $node);
                if (count($out) >= $limit) {
                    break 2;
                }
            }

            if ($batchCount === 0 || $newInBatch === 0 || $batchCount < $fetchLimit) {
                break;
            }

            $offset += $batchCount;
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
        $meta = ['path' => $path, 'etag' => (string)$file->getEtag(), 'size' => (int)$file->getSize(),
            'mime' => (string)$file->getMimetype(), 'access' => $this->accessInfo->describe($file, $userId)];
        $scannable = OcrSupport::canLackText($file->getName(), (string)$file->getMimetype());
        $isImage = $scannable && str_starts_with(strtolower((string)$file->getMimetype()), 'image/');
        $text = $isImage ? '' : $this->extractor->readText($file);
        if ($scannable && trim($text) === '') {
            $notice = $this->ocr->noTextNotice();
            return $this->text($notice, $meta + ['text_layer' => false, 'ocr_active' => $this->ocr->isActive(), 'notice' => $notice]);
        }
        return $this->text(
            $text,
            $meta,
        );
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
        return (new Handler\CheckoutFileHandler($this->checkout, $this->accessInfo, $this->guard, $this->visibilityGuard, $this->locks, $this->fileTypes, $this->backupLocation, $this->backupPlan))->execute($root, $userId, $path, $etag, $confirmed);
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
        return NodeAccess::requireFile(NodeAccess::get($root, $path, $this->visibilityGuard));
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
