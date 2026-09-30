<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Notes;

use InvalidArgumentException;
use OCA\Mcp\Tools\Common\NodeAccess;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolModule;
use OCA\Mcp\Tools\ToolResult;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUserManager;

/**
 * Notes tools: definitions and one short method per operation. File access, lookup and naming live in
 * NotesRepository; this class adds the operation rules (size, etag, ACL, confirmation, recoverability).
 */
class NotesModule implements ToolModule {
    /** Maximum note size read or written, in bytes. */
    public const MAX_BYTES = 1024 * 1024;
    /** Storage wrapper files_trashbin puts around storages whose deletions go to the trash bin. */
    public const TRASH_STORAGE = 'OCA\\Files_Trashbin\\Storage';
    public function __construct(
        private NotesRepository $notes,
        private IAppManager $appManager,
        private IUserManager $userManager,
    ) {}

    /** @return list<array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app:string}> */
    public function definitions(): array {
        $id = ['type' => 'integer', 'minimum' => 1, 'description' => NotesMessages::PARAM_ID];
        $etag = ['type' => 'string', 'description' => NotesMessages::PARAM_ETAG];
        return [
            self::tool('notes_list', 'read', NotesMessages::TOOL_LIST_DESCRIPTION, []),
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
            ], ['id']),
            self::tool('notes_move', 'move', NotesMessages::TOOL_MOVE_DESCRIPTION, [
                'id' => $id,
                'category' => ['type' => 'string', 'description' => NotesMessages::PARAM_CATEGORY_TARGET],
                'etag' => $etag,
            ], ['id', 'category']),
            self::tool('notes_delete', 'delete', NotesMessages::TOOL_DELETE_DESCRIPTION, [
                'id' => $id,
                'confirm' => ['type' => 'boolean', 'const' => true, 'description' => NotesMessages::PARAM_CONFIRM],
                'etag' => $etag,
            ], ['id', 'confirm']),
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
                'notes_list' => $this->list($root),
                'notes_read' => $this->read($root, $arguments['id']),
                'notes_create' => $this->create($root, $arguments['title'], $arguments['content'], $arguments['category']),
                'notes_edit' => $this->edit($root, $arguments),
                'notes_move' => $this->move($root, $arguments),
                'notes_delete' => $this->delete($root, $userId, $arguments),
                default => throw new InvalidArgumentException('Unknown tool'),
            });
        });
    }

    /** @return list<array{id:int, title:string, category:string, modified:int, etag:string}> */
    private function list(?Folder $root): array {
        if ($root === null) {
            return [];
        }
        $notes = array_map(fn (File $note) => $this->notes->info($note, $root), $this->notes->all($root));
        usort($notes, static fn (array $a, array $b) => $b['modified'] <=> $a['modified']);
        return $notes;
    }

    /** @return array{id:int, title:string, category:string, modified:int, etag:string, content:string} */
    private function read(?Folder $root, int $id): array {
        $note = $this->notes->find($root, $id);
        if ($note->getSize() > self::MAX_BYTES) {
            throw new ToolFailure(NotesMessages::noteTooLargeForReading(self::MAX_BYTES));
        }
        return $this->notes->info($note, $root) + ['content' => mb_scrub((string)$note->getContent(), 'UTF-8')];
    }

    /** @return array{id:int, title:string, category:string, modified:int, etag:string} */
    private function create(Folder $root, string $title, string $content, string $category): array {
        self::checkSize($content);
        $folder = $this->notes->category($root, $category);
        return $this->notes->info($folder->newFile($this->notes->freeName($folder, $title), $content), $root);
    }

    /**
     * @param array{id:int, content?:string, title?:string, etag?:string} $arguments
     * @return array{id:int, title:string, category:string, modified:int, etag:string}
     */
    private function edit(?Folder $root, array $arguments): array {
        if (!isset($arguments['content']) && !isset($arguments['title'])) {
            throw new InvalidArgumentException('Missing argument: content');
        }
        $note = $this->writable($root, $arguments);
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
        return $this->notes->info($this->notes->find($root, $arguments['id']), $root);
    }

    /**
     * @param array{id:int, category:string, etag?:string} $arguments
     * @return array{id:int, title:string, category:string, modified:int, etag:string}
     */
    private function move(?Folder $root, array $arguments): array {
        $note = $this->writable($root, $arguments);
        $target = $this->notes->category($root, $arguments['category']);
        if ($target->getPath() !== $note->getParent()->getPath()) {
            if ($target->nodeExists($note->getName())) {
                throw new ToolFailure(NotesMessages::titleExistsInTargetCategory());
            }
            $note->move($target->getPath() . '/' . $note->getName());
        }
        return $this->notes->info($this->notes->find($root, $arguments['id']), $root);
    }

    /**
     * @param array{id:int, confirm:bool, etag?:string} $arguments
     * @return array{id:int, deleted:bool, trash:bool}
     */
    private function delete(?Folder $root, string $userId, array $arguments): array {
        if (($arguments['confirm'] ?? false) !== true) {
            throw new InvalidArgumentException('Invalid argument: confirm');
        }
        $user = $this->userManager->get($userId);
        if ($user === null || !$this->appManager->isEnabledForUser('files_trashbin', $user)) {
            throw new ToolFailure(NotesMessages::notRecoverable());
        }
        $note = $this->writable($root, $arguments);
        if (!$note->isDeletable()) {
            throw new ToolFailure(ToolFailure::FORBIDDEN);
        }
        // An enabled app does not cover every mount: external or excluded storages delete permanently.
        if (!$note->getStorage()->instanceOfStorage(self::TRASH_STORAGE)) {
            throw new ToolFailure(NotesMessages::notRecoverable());
        }
        $note->delete();
        return ['id' => $arguments['id'], 'deleted' => true, 'trash' => true];
    }

    /** @param array{id:int, etag?:string} $arguments */
    private function writable(?Folder $root, array $arguments): File {
        $note = $this->notes->find($root, $arguments['id']);
        if (!$note->isUpdateable()) {
            throw new ToolFailure(ToolFailure::FORBIDDEN);
        }
        NodeAccess::checkEtag($note, $arguments['etag'] ?? null);
        return $note;
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
