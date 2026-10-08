<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCA\Mcp\Tools\Common\NodeAccess;
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
use OCA\Mcp\Service\VisibilityGuard;
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
 * itself created when it is undone, plus a version rollback, which is a write of new content and therefore
 * always creates a version.
 *
 * Every result that names a node also carries its `access` description, and every write outside the
 * personal scope goes through SharedWriteGuard first.
 *
 * Every writing tool also answers {@see self::preview()}, which the registry asks for whenever the call
 * arrives without `confirm: true`. A plan reads what it needs — the file, its ETag, the diff, the backup
 * that would be created, the node the batch would touch — and writes nothing: no folder is created, no
 * checkout token is minted and no file is backed up before the user has said yes.
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
        private ?VisibilityGuard $visibilityGuard = null,
        private ?IAppManager $appManager = null,
        private ?IFullTextSearchManager $ftsManager = null,
        private ?UserTimezone $timezone = null,
        private ?ShareLister $shareLister = null,
        private ?ShareWriter $shareWriter = null,
        private ?ShareRemover $shareRemover = null,
        private ?FileCreation $creation = null,
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
                    'old' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::oldSnippet()],
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
        return [
            'Paths are absolute inside the folder of the user calling and start at "/"; they are never relative and '
                . 'never leave the storage Nextcloud already lets that user reach.',
            'To find something, use files_search for full-text content search when available (or by file name), files_tree for the shape of a folder (depth '
                . Reorganization::MAX_DEPTH . ', ' . Reorganization::MAX_ENTRIES . ' entries) and files_list for a single '
                . 'folder. The `access` field of every entry says who owns it and what it allows.',
            'Reorganization is the only way files change places: a copy is capped at ' . ReorganizationLimits::NODES
                . ' items and ' . intdiv(ReorganizationLimits::BYTES, 1024 ** 3) . ' GiB, a batch takes at most '
                . ReorganizationLimits::BATCH_ITEMS . ' moves, and nothing overwrites the destination.',
            'Pass `etag` when changing what was just read: a different etag aborts the write instead of dropping '
                . 'the change someone else made in the meantime.',
            'Anything outside the personal folder is refused on a write until the user has been asked and the call '
                . 'repeats with confirm_shared: true. A calendar, a share or a file owned by somebody else is that case.',
            'files_edit, files_replace and files_checkout need versioning on (files_versions) and copy the file to '
                . '"/' . self::BACKUP_FOLDER . '" before writing. files_checkout hands out short-lived links for local '
                . 'tools instead of passing the content through the model.',
            'files_edit and files_replace change text files only. Use files_checkout for any file type up to the upload '
                . 'limit (docx, xlsx, pdf, images): download, edit locally, upload the whole file back. A scanned PDF is '
                . 'regenerated by the client from what it read, never edited byte by byte.',
            'To create a NEW file generated locally (docx, xlsx, pdf, image, any type), use files_upload: confirm it, then '
                . 'send the bytes with curl -sS -T /tmp/file -X PUT -H "Content-Type: application/octet-stream" "$UPLOAD_URL" '
                . 'to the single-use uploadUrl it returns (' . (int)(CheckoutService::UPLOAD_TTL / 60) . ' minutes, the same '
                . 'limit as files_checkout). For a small text file (.md, .txt, .csv, .json, .html, .xml, .yaml) use '
                . 'files_create with the content inline, up to 1 MB. Neither ever overwrites a file with content: to replace an existing file '
                . 'use files_checkout. The folder must already exist (files_mkdir).',
            'files_read and files_version_read of a PDF or image with no text return text_layer: false and a notice '
                . 'instead of an error: the file is a scan. If the Workflow OCR app is active it recognises the text in '
                . 'the background (by the administrator\'s rule) and writes it into the PDF as a new version, so read '
                . 'again later; otherwise view the page as an image with the image tools.',
            'There is no delete: files_undo_batch is the way back from files_move_batch, and files_version_restore '
                . 'restores content as a new version.',
            'files_share shares a file or folder of yours with a person (with: user:<uid>) or a group (group:<gid>); '
                . 'find the id with users_search (include_groups: true for groups) and never guess it. Sharing again '
                . 'with the same recipient changes that share; the plan shows before → after, including a re-share '
                . 'right a web-made share loses. Nextcloud notifies the recipient. with: "link" is the public link of the '
                . 'file (needs the "link" permission): its password is generated by the server, never by you, and is '
                . 'shown once in the confirmed result; tell the user to save it. Confirm with the plan_state of the plan: '
                . 'when the share changed in the meantime, nothing is written and the answer is the new plan to show again.',
            'files_list_shares shows only the shares you created and only of your own files; a file you received '
                . 'cannot be re-shared here. A password is never shown, only hasPassword. A room share is a Talk '
                . 'attachment and is removed in Talk. Sharing with people or groups needs the "share" permission and '
                . 'public links the separate "link" permission, both granted by the administrator.',
            'files_unshare removes one share you created (by the shareId of files_list_shares, or by path plus '
                . 'with); the plan says who loses access, and the file itself is never touched. It cannot remove what '
                . 'somebody else shared, a share of a file you do not own or a Talk attachment, and answers "not found" '
                . 'for all of them. Confirm with the plan_state of the plan, as for files_share.',
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
            'files_edit' => $this->write($root, $userId, $arguments['path'], $arguments['content'], $arguments['etag'] ?? null, $confirmed),
            'files_replace' => $this->replace($root, $userId, $arguments['path'], $arguments['old'], $arguments['new'], $arguments['etag'] ?? null, $confirmed),
            'files_checkout' => $this->checkoutOut($root, $userId, $arguments['path'], $arguments['etag'] ?? null, $confirmed),
            'files_versions_list' => ToolResult::json($this->versions->list($root, $this->file($root, $arguments['path']), PathGuard::normalize($arguments['path']), $arguments['limit'], $userId)),
            'files_version_read' => ToolResult::json($this->versions->read($this->file($root, $arguments['path']), PathGuard::normalize($arguments['path']), $arguments['version'], $userId)),
            'files_version_restore' => $this->restore($root, $userId, $arguments['path'], $arguments['version'], $confirmed),
            'files_image_view' => $this->images->view($root, $userId, $arguments['path'], $arguments['max_size']),
            'files_images_view' => $this->images->viewMany($root, $userId, $arguments['paths'] ?? null, $arguments['folder'] ?? null, $arguments['limit'], $arguments['max_size']),
            'files_image_search' => ToolResult::json($this->images->search($root, $userId, $arguments)),
            'files_list_shares' => ToolResult::json($this->listShares($root, $userId, $arguments['path'] ?? null, (int)$arguments['offset'])),
            'files_share' => self::shareResult($this->shareWriter()->apply($root, $userId, $arguments)),
            'files_unshare' => ToolResult::json($this->shareRemover()->apply($root, $userId, $arguments)),
            'files_upload' => ToolResult::json($this->creation()->upload($root, $userId, $arguments['path'], $arguments['size'] ?? null, $confirmed)),
            'files_create' => ToolResult::json($this->creation()->create($root, $userId, $arguments['path'], $arguments['content'], $confirmed)),
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

    /**
     * {@inheritDoc}
     *
     * Every plan runs the same checks the write runs and reads the same state, so what the user is shown is
     * what the confirmed call would do — including the refusals, which arrive as errors before anything is
     * asked. Nothing here writes: no backup, no folder, no checkout token.
     */
    public function preview(string $name, array $arguments, string $userId): array {
        $root = $this->rootFolder->getUserFolder($userId);
        $etag = $arguments['etag'] ?? null;
        return NodeAccess::run(fn (): array => match ($name) {
            'files_mkdir' => $this->reorganization->planMkdir($root, $userId, $arguments['path']),
            'files_copy' => $this->reorganization->planCopy($root, $userId, $arguments['from'], $arguments['to'], $etag),
            'files_move' => $this->reorganization->planMove($root, $userId, $arguments['from'], $arguments['to'], $etag),
            'files_move_batch' => $this->reorganization->planBatch($root, $userId, $arguments['moves'], $arguments['mkdirs'] ?? [], $this->planner),
            'files_undo_batch' => $this->reorganization->planUndoBatch($root, $userId, $arguments['batch_id'], $this->batches, $this->time->getTime()),
            'files_edit' => $this->planWrite($root, $userId, $arguments['path'], $arguments['content'], $etag),
            'files_replace' => $this->planReplace($root, $userId, $arguments['path'], $arguments['old'], $arguments['new'], $etag),
            'files_checkout' => $this->planCheckout($root, $userId, $arguments['path'], $etag),
            'files_version_restore' => $this->planRestore($root, $userId, $arguments['path'], $arguments['version']),
            'files_share' => $this->shareWriter()->plan($root, $userId, $arguments),
            'files_unshare' => $this->shareRemover()->plan($root, $userId, $arguments),
            'files_upload' => $this->creation()->planUpload($root, $userId, $arguments['path'], $arguments['size'] ?? null),
            'files_create' => $this->creation()->planCreate($root, $userId, $arguments['path'], $arguments['content']),
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
     * The plan of `files_edit`: the diff the write would produce and the backup it would take first.
     *
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param string $path file to overwrite, user-relative
     * @param string $content new complete content
     * @param string|null $etag ETag the caller read before, null to skip the check
     * @return array<string, mixed>
     * @throws ToolFailure for a non-text file, an oversized content, a stale ETag or a denied write
     */
    private function planWrite(Folder $root, string $userId, string $path, string $content, ?string $etag): array {
        $path = PathGuard::normalize($path);
        $this->assertEditable($root, $path);
        if (strlen($content) > self::MAX_EDIT_BYTES) {
            throw new ToolFailure(FilesMessages::editTooLarge(self::MAX_EDIT_BYTES));
        }
        $file = $this->file($root, $path);
        NodeAccess::checkEtag($file, $etag);
        $diff = UnifiedDiff::between($this->extractor->extract($file), $content);

        return $this->planContent($file, $userId, $path, [
            'action' => 'files_edit',
            'size' => ['before' => (int)$file->getSize(), 'after' => strlen($content)],
            'diff' => $diff,
            'message' => FilesMessages::planEdit(),
        ]);
    }

    /**
     * The plan of `files_replace`: the same diff, from the same snippet check the write performs.
     *
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param string $path file to overwrite, user-relative
     * @param string $old snippet that must appear exactly once
     * @param string $new replacement text
     * @param string|null $etag ETag the caller read before, null to skip the check
     * @return array<string, mixed>
     * @throws ToolFailure when the snippet is absent or ambiguous, or the write would be refused
     */
    private function planReplace(Folder $root, string $userId, string $path, string $old, string $new, ?string $etag): array {
        $path = PathGuard::normalize($path);
        $this->assertEditable($root, $path);
        if (strlen($new) > self::MAX_EDIT_BYTES) {
            throw new ToolFailure(FilesMessages::editTooLarge(self::MAX_EDIT_BYTES));
        }
        if (!mb_check_encoding($old, 'UTF-8')) {
            throw new ToolFailure(FilesMessages::snippetNotUtf8());
        }
        $file = $this->file($root, $path);
        NodeAccess::checkEtag($file, $etag);
        $raw = (string)$file->getContent();
        $occurrences = substr_count($raw, $old);
        if ($occurrences === 0) {
            throw new ToolFailure(FilesMessages::snippetMissing());
        }
        if ($occurrences > 1) {
            throw new ToolFailure(FilesMessages::snippetAmbiguous($occurrences));
        }
        $updated = str_replace($old, $new, $raw, $count);

        return $this->planContent($file, $userId, $path, [
            'action' => 'files_replace',
            'snippet' => ['occurrences' => $occurrences, 'replaced' => $count],
            'size' => ['before' => (int)$file->getSize(), 'after' => strlen($updated)],
            'diff' => UnifiedDiff::between($this->extractor->extract($file), $updated),
            'message' => FilesMessages::planReplace(),
        ]);
    }

    /**
     * The fields every content write shares: who owns the file, whether other people are reached and the
     * backup the write takes before it writes.
     *
     * @param File $file file about to be overwritten
     * @param string $userId authenticated user
     * @param string $path normalized user-relative path of $file
     * @param array<string, mixed> $plan what the specific tool would do
     * @return array<string, mixed>
     * @throws ToolFailure when Nextcloud refuses the update, with or without the shared confirmation
     */
    private function planContent(File $file, string $userId, string $path, array $plan): array {
        $shared = $this->guard->guard($file, $userId, $path, false);
        return $plan + [
            'path' => $path,
            'etag' => (string)$file->getEtag(),
            'backup' => '/' . self::BACKUP_FOLDER,
            'access' => $this->accessInfo->describe($file, $userId),
            'shared' => $shared === null ? [] : [$shared],
            'requiresSharedConfirmation' => $shared !== null,
            'recoverable' => true,
            'consequence' => FilesMessages::planBackupConsequence(),
        ];
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
            'backup' => '/' . self::BACKUP_FOLDER,
            'access' => $this->accessInfo->describe($file, $userId),
            'shared' => $shared === null ? [] : [$shared],
            'requiresSharedConfirmation' => $shared !== null,
            'recoverable' => true,
            'linksAfterConfirmation' => true,
            'consequence' => FilesMessages::planCheckoutConsequence(),
            'message' => FilesMessages::planCheckout(),
        ];
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
    private function planRestore(Folder $root, string $userId, string $path, string $version): array {
        $path = PathGuard::normalize($path);
        if (FileBackup::isBackupPath($path)) {
            throw new ToolFailure(FilesMessages::backupPath());
        }
        $file = $this->file($root, $path);
        $shared = $this->guard->guard($file, $userId, $path, false);
        return [
            'action' => 'files_version_restore',
            'path' => $path,
            'version' => $this->versions->describe($file, $version, $userId),
            'timezone' => $this->timezone?->forUser($userId)->getName(),
            'current' => ['etag' => (string)$file->getEtag(), 'size' => (int)$file->getSize()],
            'backup' => '/' . self::BACKUP_FOLDER,
            'access' => $this->accessInfo->describe($file, $userId),
            'shared' => $shared === null ? [] : [$shared],
            'requiresSharedConfirmation' => $shared !== null,
            'recoverable' => true,
            'consequence' => FilesMessages::planBackupConsequence(),
            'message' => FilesMessages::planRestore(),
        ];
    }

    /** @return list<array{name:string, path:string, isDir:bool, size:int, mtime:string, contentType:string, access:array<string, mixed>}> */
    private function list(Folder $root, string $userId, string $path): array {
        $folder = NodeAccess::get($root, $path, $this->visibilityGuard);
        if (!$folder instanceof Folder) {
            throw new ToolFailure(FilesMessages::notAFolder());
        }
        $listing = $folder->getDirectoryListing();
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
        return NodeAccess::requireFile(NodeAccess::get($root, $path, $this->visibilityGuard));
    }

    /**
     * The only refusal files_checkout shares with an edit: the folder a user recovers from is never written.
     * The checkout never reads the bytes, so its type does not matter; the upload limit lives in CheckoutService.
     *
     * @param string $path normalized user-relative path
     * @throws ToolFailure when the path is inside the backup folder
     */
    private function assertNotBackup(string $path): void {
        if (FileBackup::isBackupPath($path)) {
            throw new ToolFailure(FilesMessages::backupPath());
        }
    }

    /**
     * files_edit and files_replace write text through the model, so they also need a text file.
     *
     * @param Folder $root the user's folder
     * @param string $path normalized user-relative path
     * @throws ToolFailure when the path is the backup folder or does not hold editable text
     */
    private function assertEditable(Folder $root, string $path): void {
        $this->assertNotBackup($path);
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
