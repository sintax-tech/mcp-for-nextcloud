<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Notes;

use InvalidArgumentException;
use OCA\Mcp\Service\VisibilityGuard;
use OCA\Mcp\Tools\Common\NodeAccess;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\Common\PathGuard;
use OCA\Mcp\Tools\Common\SharedWriteGuard;
use OCA\Mcp\Tools\PreviewsWrites;
use OCA\Mcp\Tools\RendersPlans;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolGuideNotes;
use OCA\Mcp\Tools\ToolModule;
use OCA\Mcp\Tools\ToolResult;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUserManager;

/**
 * Notes tools: definitions and one short method per operation. File access, lookup and naming live in
 * NotesRepository; this class adds the operation rules (size, etag, ACL, confirmation, recoverability).
 *
 * The four writing tools also answer {@see self::preview()}, which the registry asks for whenever a write
 * arrives without `confirm: true`: the note as it is now, what the call would do to it, and whether the
 * trash bin would take it back. A preview creates no category folder and writes nothing.
 */
class NotesModule implements ToolModule, PreviewsWrites, ToolGuideNotes, RendersPlans {
    /** Maximum note size read or written, in bytes. */
    public const MAX_BYTES = NotesRepository::MAX_BYTES;
    /** Storage wrapper files_trashbin puts around storages whose deletions go to the trash bin. */
    public const TRASH_STORAGE = 'OCA\\Files_Trashbin\\Storage';
    /** Characters of the content a plan shows, so the user reads the note and not a wall of text. */
    private const EXCERPT_CHARS = 400;

    public function __construct(
        private NotesRepository $notes,
        private IAppManager $appManager,
        private IUserManager $userManager,
        private SharedWriteGuard $guard,
        private NodeAccessInfo $accessInfo,
        private ?VisibilityGuard $visibilityGuard = null,
    ) {}

    /**
     * What the schemas cannot say: how a note is named and what the module refuses to do.
     *
     * @return list<string>
     */
    public function guideNotes(): array {
        return [
            'A note is addressed by the `id` notes_list or notes_search returns, not by its title; the title is the file name and '
                . 'the category is the subfolder it sits in.',
            'Use notes_search to find notes by keyword in title or Markdown content.',
            'notes_create never overwrites: when the title is taken in that category it picks a free name, and the '
                . 'response says which name it used.',
            'notes_delete only deletes where the Nextcloud trash bin takes the note back; anywhere else it refuses '
                . 'instead of removing it for good.',
            'Notes are capped at ' . self::MAX_BYTES . ' bytes read or written, and a title at 200 characters.',
            'Pass `etag` to make a change fail instead of overwriting somebody else\'s version of the same note.',
            'Some notes or categories may be hidden by administrator policy and will appear as if they do not exist.',
        ];
    }

    /** @return list<array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app:string}> */
    public function definitions(): array {
        $id = ['type' => 'integer', 'minimum' => 1, 'description' => NotesMessages::PARAM_ID];
        $etag = ['type' => 'string', 'description' => NotesMessages::PARAM_ETAG];
        // Notes live inside the user's own folder by construction, so this only ever fires when an
        // administrator pointed the notes path at a share, a team folder or an external storage.
        $confirmShared = ['type' => 'boolean', 'description' => NotesMessages::PARAM_CONFIRM_SHARED];
        return [
            self::tool('notes_list', 'read', NotesMessages::TOOL_LIST_DESCRIPTION, []),
            self::tool('notes_search', 'read', NotesMessages::TOOL_SEARCH_DESCRIPTION, [
                'query' => ['type' => 'string', 'minLength' => 1, 'description' => NotesMessages::PARAM_SEARCH_QUERY],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20, 'description' => NotesMessages::PARAM_SEARCH_LIMIT],
                'category' => ['type' => 'string', 'default' => '', 'description' => NotesMessages::PARAM_SEARCH_CATEGORY],
            ], ['query']),
            self::tool('notes_read', 'read', NotesMessages::TOOL_READ_DESCRIPTION, ['id' => $id], ['id']),
            self::tool('notes_create', 'create', NotesMessages::TOOL_CREATE_DESCRIPTION, [
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'description' => NotesMessages::PARAM_TITLE],
                'content' => ['type' => 'string', 'default' => '', 'description' => NotesMessages::PARAM_CONTENT],
                'category' => ['type' => 'string', 'default' => '', 'description' => NotesMessages::PARAM_CATEGORY],
            ], ['title']),
            self::tool('notes_edit', 'edit', NotesMessages::TOOL_EDIT_DESCRIPTION, [
                'id' => $id,
                'content' => ['type' => 'string', 'description' => NotesMessages::PARAM_NEW_CONTENT],
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'description' => NotesMessages::PARAM_NEW_TITLE],
                'etag' => $etag,
                'confirm_shared' => $confirmShared,
            ], ['id']),
            self::tool('notes_move', 'move', NotesMessages::TOOL_MOVE_DESCRIPTION, [
                'id' => $id,
                'category' => ['type' => 'string', 'description' => NotesMessages::PARAM_CATEGORY_TARGET],
                'etag' => $etag,
                'confirm_shared' => $confirmShared,
            ], ['id', 'category']),
            self::tool('notes_delete', 'delete', NotesMessages::TOOL_DELETE_DESCRIPTION, [
                'id' => $id,
                'etag' => $etag,
                'confirm_shared' => $confirmShared,
            ], ['id']),
        ];
    }

    /**
     * @param string $name one of the names from definitions()
     * @param array<string, mixed> $arguments already validated against the tool schema, defaults applied
     * @param string $userId authenticated user
     * @return array{content: list<array{type:string, text:string}>, isError?: bool}
     * @throws InvalidArgumentException for an unknown tool or an edit without changes
     * @throws ToolFailure for client-safe failures (not found, forbidden, conflict, precondition)
     */
    public function call(string $name, array $arguments, string $userId): array {
        return NodeAccess::run(function () use ($name, $arguments, $userId): array {
            $root = $this->notes->folder($userId, $name === 'notes_create');
            return ToolResult::json(match ($name) {
                'notes_list' => $this->list($root, $userId),
                'notes_search' => $this->search($root, $userId, $arguments['query'], (int)$arguments['limit'], (string)($arguments['category'] ?? '')),
                'notes_read' => $this->read($root, $userId, $arguments['id']),
                'notes_create' => $this->create($root, $arguments['title'], $arguments['content'], $arguments['category']),
                'notes_edit' => $this->edit($root, $userId, $arguments),
                'notes_move' => $this->move($root, $userId, $arguments),
                'notes_delete' => $this->delete($root, $userId, $arguments),
                default => throw new InvalidArgumentException('Unknown tool'),
            });
        });
    }

    /** @return list<array{id:int, title:string, category:string, modified:int, etag:string, access:array<string, mixed>}> */
    private function list(?Folder $root, string $userId): array {
        if ($root === null) {
            return [];
        }
        $notes = array_map(fn (File $note) => $this->notes->info($note, $root) + ['access' => $this->accessInfo->describe($note, $userId)], $this->notes->all($root));
        usort($notes, static fn (array $a, array $b) => $b['modified'] <=> $a['modified']);
        return $notes;
    }

    /** @return list<array{id:int, title:string, category:string, modified:int, etag:string, snippet:string, access:array<string, mixed>}> */
    private function search(?Folder $root, string $userId, string $query, int $limit, string $category): array {
        if ($root === null || $query === '') {
            return [];
        }

        $targetFolder = $this->existingCategory($root, $category);
        if ($targetFolder === null) {
            return [];
        }

        $allNotes = $this->notes->all($targetFolder);
        usort($allNotes, static fn (File $a, File $b) => $b->getMTime() <=> $a->getMTime());
        $visibleNotes = ($this->visibilityGuard?->filter($allNotes) ?? array_values($allNotes));

        $results = [];
        foreach ($visibleNotes as $note) {
            if (!$note instanceof File || !NotesRepository::isNote($note) || !$note->isReadable()) {
                continue;
            }

            $title = pathinfo($note->getName(), PATHINFO_FILENAME);
            $titleMatch = mb_stripos($title, $query) !== false;

            $contentMatch = false;
            $contentPos = false;
            $content = '';
            if ($note->getSize() <= self::MAX_BYTES) {
                $content = mb_scrub((string)$note->getContent(), 'UTF-8');
                $contentPos = mb_stripos($content, $query);
                $contentMatch = $contentPos !== false;
            }

            if (!$titleMatch && !$contentMatch) {
                continue;
            }

            if ($contentMatch && $contentPos !== false) {
                $start = max(0, $contentPos - 50);
                $length = 120 + mb_strlen($query);
                $snippet = mb_substr($content, $start, $length);
                $snippet = trim((string)preg_replace('/\s+/', ' ', $snippet));
                if ($start > 0) {
                    $snippet = '...' . $snippet;
                }
                if ($start + $length < mb_strlen($content)) {
                    $snippet .= '...';
                }
            } else {
                $snippet = trim((string)preg_replace('/\s+/', ' ', mb_substr($content, 0, 100)));
                if (mb_strlen($content) > 100) {
                    $snippet .= '...';
                }
            }

            $results[] = $this->notes->info($note, $root) + [
                'snippet' => $snippet,
                'access' => $this->accessInfo->describe($note, $userId),
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /** @return array{id:int, title:string, category:string, modified:int, etag:string, content:string, access:array<string, mixed>} */
    private function read(?Folder $root, string $userId, int $id): array {
        $note = $this->notes->find($root, $id);
        return $this->notes->info($note, $root) + [
            'content' => $this->notes->read($note),
            'access' => $this->accessInfo->describe($note, $userId),
        ];
    }

    /** @return array{id:int, title:string, category:string, modified:int, etag:string} */
    private function create(Folder $root, string $title, string $content, string $category): array {
        self::checkSize($content);
        $folder = $this->notes->category($root, $category);
        return $this->notes->info($folder->newFile($this->notes->freeName($folder, $title), $content), $root);
    }

    /**
     * @param array{id:int, content?:string, title?:string, etag?:string, confirm_shared?:bool} $arguments
     * @return array<string, mixed> the note, or the shared-write confirmation without writing
     */
    private function edit(?Folder $root, string $userId, array $arguments): array {
        if (!isset($arguments['content']) && !isset($arguments['title'])) {
            throw new InvalidArgumentException('Missing argument: content');
        }
        if (($payload = $this->writable($root, $userId, $arguments)) !== null) {
            return $payload;
        }
        $note = $this->notes->find($root, $arguments['id']);
        if (isset($arguments['content'])) {
            self::checkSize($arguments['content']);
            $note->putContent($arguments['content']);
        }
        if (isset($arguments['title'])) {
            $name = $this->notes->renamedName($note, $arguments['title']);
            if ($name !== $note->getName()) {
                $parent = $note->getParent();
                if ($parent->nodeExists($name)) {
                    throw new ToolFailure(NotesMessages::titleExistsInCategory());
                }
                $note->move($parent->getPath() . '/' . $name);
            }
        }
        return $this->described($root, $userId, $arguments);
    }

    /**
     * @param array{id:int, category:string, etag?:string, confirm_shared?:bool} $arguments
     * @return array<string, mixed> the note, or the shared-write confirmation without moving
     */
    private function move(?Folder $root, string $userId, array $arguments): array {
        if (($payload = $this->writable($root, $userId, $arguments)) !== null) {
            return $payload;
        }
        $note = $this->notes->find($root, $arguments['id']);
        $target = $this->notes->category($root, $arguments['category']);
        if ($target->getPath() !== $note->getParent()->getPath()) {
            if ($target->nodeExists($note->getName())) {
                throw new ToolFailure(NotesMessages::titleExistsInTargetCategory());
            }
            $note->move($target->getPath() . '/' . $note->getName());
        }
        return $this->described($root, $userId, $arguments);
    }

    /**
     * @param array{id:int, confirm?:bool, etag?:string, confirm_shared?:bool} $arguments
     * @return array<string, mixed> the deletion receipt, or the shared-write confirmation without deleting
     */
    private function delete(?Folder $root, string $userId, array $arguments): array {
        $this->assertRecoverable($userId);
        if (($payload = $this->writable($root, $userId, $arguments)) !== null) {
            return $payload;
        }
        $note = $this->notes->find($root, $arguments['id']);
        if (!$note->isDeletable()) {
            throw new ToolFailure(CommonMessages::forbidden());
        }
        // An enabled app does not cover every mount: external or excluded storages delete permanently.
        if (!$note->getStorage()->instanceOfStorage(self::TRASH_STORAGE)) {
            throw new ToolFailure(NotesMessages::notRecoverable());
        }
        $note->delete();
        return ['id' => $arguments['id'], 'deleted' => true, 'trash' => true];
    }

    /**
     * {@inheritDoc}
     *
     * The plan says what the note looks like now and what the call does to it: the title and category it
     * would end up in, an excerpt of the content, and whether the trash bin would bring it back. A note
     * that would not be recoverable is refused here with the same message the write would give, so the
     * model never asks the user about a deletion the server will not perform.
     */
    public function preview(string $name, array $arguments, string $userId): array {
        $root = $this->notes->folder($userId, false);
        return match ($name) {
            'notes_create' => $this->previewCreate($root, $arguments),
            'notes_edit' => $this->previewEdit($root, $userId, $arguments),
            'notes_move' => $this->previewMove($root, $userId, $arguments),
            'notes_delete' => $this->previewDelete($root, $userId, $arguments),
            default => throw new InvalidArgumentException('Unknown tool'),
        };
    }

    /**
     * {@inheritDoc}
     */
    public function renderPlan(string $tool, array $plan): ?string {
        return (new NotesPlanRenderer())->render($tool, $plan);
    }

    /**
     * @param Folder|null $root notes folder
     * @param array{title:string, content?:string, category?:string} $arguments
     * @return array<string, mixed> the note the call would create
     */
    private function previewCreate(?Folder $root, array $arguments): array {
        self::checkSize((string)($arguments['content'] ?? ''));
        $category = (string)($arguments['category'] ?? '');
        if ($root !== null && trim($category, '/') !== '') {
            try {
                $relative = ltrim(PathGuard::normalize($category), '/');
                if ($root->nodeExists($relative)) {
                    $node = $root->get($relative);
                    if ($this->visibilityGuard !== null && !$this->visibilityGuard->isVisible($node)) {
                        throw new ToolFailure(CommonMessages::notFound());
                    }
                }
            } catch (InvalidArgumentException) {
                throw new InvalidArgumentException('Invalid argument: category');
            }
        }
        $folder = $this->existingCategory($root, $category);
        if ($root === null || $folder === null) {
            // The category folder itself does not exist yet: the write creates it, and the plan says so
            // rather than creating anything now.
            return [
                'action' => 'notes_create',
                'note' => ['title' => (string)$arguments['title'], 'category' => trim($category, '/'), 'categoryCreated' => $category !== ''],
                'recoverable' => true,
                'message' => NotesMessages::planCreate(),
            ];
        }
        return [
            'action' => 'notes_create',
            'note' => [
                'title' => (string)$arguments['title'],
                'fileName' => $this->notes->freeName($folder, (string)$arguments['title']),
                'category' => $this->categoryName($root, $folder),
                'categoryCreated' => false,
                'content' => self::excerpt((string)($arguments['content'] ?? '')),
                'bytes' => strlen((string)($arguments['content'] ?? '')),
            ],
            'recoverable' => true,
            'message' => NotesMessages::planCreate(),
        ];
    }

    /**
     * @param Folder|null $root notes folder
     * @param string $userId authenticated user
     * @param array{id:int, content?:string, title?:string, etag?:string, confirm_shared?:bool} $arguments
     * @return array<string, mixed> the note before and after the change
     */
    private function previewEdit(?Folder $root, string $userId, array $arguments): array {
        if (!isset($arguments['content']) && !isset($arguments['title'])) {
            throw new InvalidArgumentException('Missing argument: content');
        }
        $note = $this->notes->find($root, $arguments['id']);
        if (isset($arguments['content'])) {
            self::checkSize($arguments['content']);
        }
        NodeAccess::checkEtag($note, $arguments['etag'] ?? null);
        if (!$note->isUpdateable()) {
            throw new ToolFailure(CommonMessages::forbidden());
        }
        $before = $this->snapshot($root, $note);
        $after = $before;
        $after['title'] = isset($arguments['title'])
            ? pathinfo($this->notes->renamedName($note, $arguments['title']), PATHINFO_FILENAME)
            : $before['title'];
        if (isset($arguments['content'])) {
            $after['content'] = self::excerpt($arguments['content']);
            $after['bytes'] = strlen($arguments['content']);
        }
        return [
            'action' => 'notes_edit',
            'note' => ['id' => (int)$note->getId(), 'before' => $before, 'after' => $after],
            'changed' => array_values(array_filter(
                ['title', 'content'],
                static fn (string $field): bool => $before[$field] !== $after[$field],
            )),
            'access' => $this->accessInfo->describe($note, $userId),
            'shared' => $this->shared($note, $userId),
            'recoverable' => true,
            'message' => NotesMessages::planEdit(),
        ];
    }

    /**
     * @param Folder|null $root notes folder
     * @param string $userId authenticated user
     * @param array{id:int, category:string, etag?:string, confirm_shared?:bool} $arguments
     * @return array<string, mixed> where the note goes
     */
    private function previewMove(?Folder $root, string $userId, array $arguments): array {
        $note = $this->notes->find($root, $arguments['id']);
        NodeAccess::checkEtag($note, $arguments['etag'] ?? null);
        if (!$note->isUpdateable()) {
            throw new ToolFailure(CommonMessages::forbidden());
        }
        $category = (string)$arguments['category'];
        if ($root !== null && trim($category, '/') !== '') {
            try {
                $relative = ltrim(PathGuard::normalize($category), '/');
                if ($root->nodeExists($relative)) {
                    $node = $root->get($relative);
                    if ($this->visibilityGuard !== null && !$this->visibilityGuard->isVisible($node)) {
                        throw new ToolFailure(CommonMessages::notFound());
                    }
                }
            } catch (InvalidArgumentException) {
                throw new InvalidArgumentException('Invalid argument: category');
            }
        }
        $folder = $this->existingCategory($root, $category);
        return [
            'action' => 'notes_move',
            'note' => $this->snapshot($root, $note),
            'from' => $this->snapshot($root, $note)['category'],
            'to' => trim($category, '/'),
            'categoryCreated' => $folder === null && trim($category, '/') !== '',
            'titleTaken' => (function () use ($folder, $note): bool {
                if ($folder === null || !$folder->nodeExists($note->getName())) {
                    return false;
                }
                $existing = $folder->get($note->getName());
                return $this->visibilityGuard === null || $this->visibilityGuard->isVisible($existing);
            })(),
            'access' => $this->accessInfo->describe($note, $userId),
            'shared' => $this->shared($note, $userId),
            'recoverable' => true,
            'message' => NotesMessages::planMove(),
        ];
    }

    /**
     * @param Folder|null $root notes folder
     * @param string $userId authenticated user
     * @param array{id:int, etag?:string, confirm_shared?:bool} $arguments
     * @return array<string, mixed> the note that would go to the trash bin
     */
    private function previewDelete(?Folder $root, string $userId, array $arguments): array {
        $this->assertRecoverable($userId);
        $note = $this->notes->find($root, $arguments['id']);
        NodeAccess::checkEtag($note, $arguments['etag'] ?? null);
        if (!$note->isDeletable()) {
            throw new ToolFailure(CommonMessages::forbidden());
        }
        if (!$note->getStorage()->instanceOfStorage(self::TRASH_STORAGE)) {
            throw new ToolFailure(NotesMessages::notRecoverable());
        }
        return [
            'action' => 'notes_delete',
            'note' => $this->snapshot($root, $note),
            'access' => $this->accessInfo->describe($note, $userId),
            'shared' => $this->shared($note, $userId),
            'trash' => true,
            'recoverable' => true,
            'consequence' => NotesMessages::planDeleteConsequence(),
            'message' => NotesMessages::planDelete(),
        ];
    }

    /**
     * The note as a plan shows it: where it is and what it says, without reading it whole.
     *
     * @param Folder $root notes folder
     * @param File $note note to describe
     * @return array<string, mixed>
     * @throws ToolFailure when the note is over the read limit
     */
    private function snapshot(Folder $root, File $note): array {
        $size = (int)$note->getSize();
        if ($size > self::MAX_BYTES) {
            throw new ToolFailure(NotesMessages::noteTooLargeForReading(self::MAX_BYTES));
        }
        return $this->notes->info($note, $root) + [
            'content' => self::excerpt((string)$note->getContent()),
            'bytes' => $size,
        ];
    }

    /**
     * The category folder as it is now, without creating it.
     *
     * @param Folder|null $root notes folder
     * @param string $category requested category
     * @return Folder|null the folder, or null when it does not exist yet
     * @throws InvalidArgumentException on traversal or control characters
     */
    private function existingCategory(?Folder $root, string $category): ?Folder {
        if ($root === null || trim($category, '/') === '') {
            return $root;
        }
        try {
            $relative = ltrim(PathGuard::normalize($category), '/');
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('Invalid argument: category');
        }
        $found = null;
        if ($root->nodeExists($relative)) {
            $node = $root->get($relative);
            if ($this->visibilityGuard === null || $this->visibilityGuard->isVisible($node)) {
                $found = $node;
            }
        }
        return $found instanceof Folder ? $found : null;
    }

    /**
     * @param Folder $root notes folder
     * @param Folder $folder category folder below it
     * @return string the category as the other tools name it
     */
    private function categoryName(Folder $root, Folder $folder): string {
        return trim((string)$root->getRelativePath($folder->getPath()), '/');
    }

    /**
     * @param File $note note about to be changed
     * @param string $userId authenticated user
     * @return array<string, mixed> the ownership description when the note reaches other people
     */
    private function shared(File $note, string $userId): array {
        $access = $this->accessInfo->describe($note, $userId);
        return $access['scope'] === NodeAccessInfo::PERSONAL ? [] : [$access];
    }

    /**
     * A deletion only happens when the trash bin would take the note back.
     *
     * @param string $userId authenticated user
     * @throws ToolFailure when files_trashbin is off for this account
     */
    private function assertRecoverable(string $userId): void {
        $user = $this->userManager->get($userId);
        if ($user === null || !$this->appManager->isEnabledForUser('files_trashbin', $user)) {
            throw new ToolFailure(NotesMessages::notRecoverable());
        }
    }

    /**
     * @param string $content full content of a note
     * @return string the beginning of it, marked when there is more
     */
    private static function excerpt(string $content): string {
        return mb_strlen($content) > self::EXCERPT_CHARS
            ? mb_substr($content, 0, self::EXCERPT_CHARS) . NotesMessages::excerptTruncated()
            : $content;
    }

    /**
     * Resolves the note, checks the ETag and runs the shared-write guard.
     *
     * @param Folder|null $root notes folder
     * @param string $userId authenticated user
     * @param array{id:int, etag?:string, confirm_shared?:bool} $arguments validated tool arguments
     * @return array<string, mixed>|null the confirmation payload, or null when the change may proceed
     * @throws ToolFailure when the note is not there, not writable or the ETag diverged
     */
    private function writable(?Folder $root, string $userId, array $arguments): ?array {
        $note = $this->notes->find($root, $arguments['id']);
        if (!$note->isUpdateable()) {
            throw new ToolFailure(CommonMessages::forbidden());
        }
        NodeAccess::checkEtag($note, $arguments['etag'] ?? null);
        return $this->guard->guard($note, $userId, (string)$arguments['id'], (bool)($arguments['confirm_shared'] ?? false));
    }

    /**
     * @param Folder|null $root notes folder
     * @param string $userId authenticated user
     * @param array{id:int} $arguments validated tool arguments
     * @return array<string, mixed> the note as the other write tools return it
     */
    private function described(?Folder $root, string $userId, array $arguments): array {
        $note = $this->notes->find($root, $arguments['id']);
        return $this->notes->info($note, $root) + ['access' => $this->accessInfo->describe($note, $userId)];
    }

    /** @throws ToolFailure when the content is over MAX_BYTES */
    private static function checkSize(string $content): void {
        if (strlen($content) > self::MAX_BYTES) {
            throw new ToolFailure(NotesMessages::noteTooLarge(self::MAX_BYTES));
        }
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string> $required
     * @return array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app:string}
     */
    private static function tool(string $name, string $operation, string $description, array $properties, array $required = []): array {
        $schema = ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties, 'additionalProperties' => false];
        return ['name' => $name, 'description' => $description, 'module' => 'notes', 'operation' => $operation, 'app' => 'notes',
            'inputSchema' => $required === [] ? $schema : $schema + ['required' => $required]];
    }
}
