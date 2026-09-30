<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Notes;

use InvalidArgumentException;
use OCA\Mcp\Tools\Common\NodeAccess;
use OCA\Mcp\Tools\Common\PathGuard;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolModule;
use OCA\Mcp\Tools\ToolResult;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUserManager;

/**
 * Notes tools working directly on the Notes app's files through the user's IRootFolder view.
 * A note is a .md/.txt file below the user's notes folder; its category is the relative subfolder
 * and its id is the file id. Nodes outside the notes folder are treated as not found.
 */
class NotesModule implements ToolModule {
    /** Maximum number of notes returned by notes_list. */
    public const MAX_NOTES = 2000;
    /** Maximum note size read or written, in bytes. */
    public const MAX_BYTES = 1024 * 1024;
    /** Notes app default folder when the user never changed the setting. */
    public const DEFAULT_FOLDER = 'Notes';
    /** File extensions the Notes app treats as notes. */
    private const EXTENSIONS = ['md', 'txt'];

    public function __construct(
        private IRootFolder $rootFolder,
        private IConfig $config,
        private IAppManager $appManager,
        private IUserManager $userManager,
    ) {}

    /** @return list<array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app:string}> */
    public function definitions(): array {
        $id = ['type' => 'integer', 'minimum' => 1, 'description' => 'id da nota'];
        $etag = ['type' => 'string', 'description' => 'ETag lido antes; se divergir, nada é alterado'];
        return [
            self::tool('notes_list', 'read', 'Lista as notas (app Notes) do usuário.', []),
            self::tool('notes_read', 'read', 'Lê o conteúdo de uma nota pelo id.', ['id' => $id], ['id']),
            self::tool('notes_create', 'create', 'Cria uma nota; nunca sobrescreve uma existente.', [
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'description' => 'Título (nome do arquivo)'],
                'content' => ['type' => 'string', 'default' => '', 'description' => 'Conteúdo em Markdown'],
                'category' => ['type' => 'string', 'default' => '', 'description' => 'Categoria (subpasta); vazio para a raiz'],
            ], ['title']),
            self::tool('notes_edit', 'edit', 'Altera o conteúdo e/ou o título de uma nota.', [
                'id' => $id,
                'content' => ['type' => 'string', 'description' => 'Novo conteúdo completo'],
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'description' => 'Novo título'],
                'etag' => $etag,
            ], ['id']),
            self::tool('notes_move', 'move', 'Move uma nota para outra categoria.', [
                'id' => $id,
                'category' => ['type' => 'string', 'description' => 'Categoria de destino; vazio para a raiz'],
                'etag' => $etag,
            ], ['id', 'category']),
            self::tool('notes_delete', 'delete', 'Exclui uma nota para a lixeira do Nextcloud. Exige confirm=true.', [
                'id' => $id,
                'confirm' => ['type' => 'boolean', 'const' => true, 'description' => 'Precisa ser true para confirmar a exclusão'],
                'etag' => $etag,
            ], ['id', 'confirm']),
        ];
    }

    /**
     * @param array<string, mixed> $arguments already validated against the tool schema
     * @return array{content: list<array{type:string, text:string}>, isError?: bool}
     * @throws InvalidArgumentException for an unknown tool or an edit without changes
     * @throws ToolFailure for client-safe failures (not found, forbidden, conflict, precondition)
     */
    public function call(string $name, array $arguments, string $userId): array {
        return NodeAccess::run(function () use ($name, $arguments, $userId): array {
            $root = $this->notesFolder($userId, $name === 'notes_create');
            return match ($name) {
                'notes_list' => ToolResult::json($root === null ? [] : $this->list($root)),
                'notes_read' => ToolResult::json($this->read($this->note($root, $arguments['id']), $root)),
                'notes_create' => ToolResult::json($this->create($root, $arguments['title'], $arguments['content'], $arguments['category'])),
                'notes_edit' => ToolResult::json($this->edit($root, $arguments)),
                'notes_move' => ToolResult::json($this->move($root, $arguments)),
                'notes_delete' => ToolResult::json($this->delete($root, $userId, $arguments)),
                default => throw new InvalidArgumentException('Unknown tool'),
            };
        });
    }

    /** Resolves the user's notes folder, creating it only when a note is being created. */
    private function notesFolder(string $userId, bool $create): ?Folder {
        $userFolder = $this->rootFolder->getUserFolder($userId);
        $path = PathGuard::normalize($this->config->getUserValue($userId, 'notes', 'notesPath', self::DEFAULT_FOLDER) ?: self::DEFAULT_FOLDER);
        if ($create) {
            return NodeAccess::ensureFolder($userFolder, $path);
        }
        if (!$userFolder->nodeExists(ltrim($path, '/'))) {
            return null;
        }
        $folder = $userFolder->get(ltrim($path, '/'));
        return $folder instanceof Folder ? $folder : null;
    }

    /** @return list<array{id:int, title:string, category:string, modified:int, etag:string}> */
    private function list(Folder $root): array {
        $notes = [];
        $pending = [$root];
        while ($pending !== [] && count($notes) < self::MAX_NOTES) {
            foreach (array_shift($pending)->getDirectoryListing() as $node) {
                if ($node instanceof Folder) {
                    $pending[] = $node;
                } elseif ($node instanceof File && self::isNote($node) && count($notes) < self::MAX_NOTES) {
                    $notes[] = $this->info($node, $root);
                }
            }
        }
        usort($notes, static fn (array $a, array $b) => $b['modified'] <=> $a['modified']);
        return $notes;
    }

    /** @return array{id:int, title:string, category:string, modified:int, etag:string, content:string} */
    private function read(File $note, Folder $root): array {
        if ($note->getSize() > self::MAX_BYTES) {
            throw new ToolFailure('Nota excede o limite de leitura de ' . self::MAX_BYTES . ' bytes.');
        }
        return $this->info($note, $root) + ['content' => mb_scrub((string)$note->getContent(), 'UTF-8')];
    }

    /** @return array{id:int, title:string, category:string, modified:int, etag:string} */
    private function create(Folder $root, string $title, string $content, string $category): array {
        $this->checkSize($content);
        $folder = NodeAccess::ensureFolder($root, self::category($category));
        $base = self::title($title);
        $name = $base . '.md';
        for ($i = 2; $folder->nodeExists($name); $i++) {
            if ($i > 100) {
                throw new ToolFailure('Já existem notas demais com este título.');
            }
            $name = $base . ' (' . $i . ').md';
        }
        return $this->info($folder->newFile($name, $content), $root);
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
            $this->checkSize($arguments['content']);
            $note->putContent($arguments['content']);
        }
        if (isset($arguments['title'])) {
            $name = self::title($arguments['title']) . '.' . strtolower(pathinfo($note->getName(), PATHINFO_EXTENSION));
            if ($name !== $note->getName()) {
                $parent = $note->getParent();
                if ($parent->nodeExists($name)) {
                    throw new ToolFailure('Já existe uma nota com este título nesta categoria.');
                }
                $note = $note->move($parent->getPath() . '/' . $name);
            }
        }
        return $this->info($this->note($root, $arguments['id']), $root);
    }

    /**
     * @param array{id:int, category:string, etag?:string} $arguments
     * @return array{id:int, title:string, category:string, modified:int, etag:string}
     */
    private function move(?Folder $root, array $arguments): array {
        $note = $this->writable($root, $arguments);
        $target = NodeAccess::ensureFolder($root, self::category($arguments['category']));
        if ($target->getPath() !== $note->getParent()->getPath()) {
            if ($target->nodeExists($note->getName())) {
                throw new ToolFailure('Já existe uma nota com este título na categoria de destino.');
            }
            $note->move($target->getPath() . '/' . $note->getName());
        }
        return $this->info($this->note($root, $arguments['id']), $root);
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
            throw new ToolFailure('Exclusão bloqueada: a lixeira (files_trashbin) não está ativa, então a nota não seria recuperável.');
        }
        $note = $this->writable($root, $arguments);
        if (!$note->isDeletable()) {
            throw new ToolFailure(ToolFailure::FORBIDDEN);
        }
        $note->delete();
        return ['id' => $arguments['id'], 'deleted' => true, 'trash' => true];
    }

    /** @param array{id:int, etag?:string} $arguments */
    private function writable(?Folder $root, array $arguments): File {
        $note = $this->note($root, $arguments['id']);
        if (!$note->isUpdateable()) {
            throw new ToolFailure(ToolFailure::FORBIDDEN);
        }
        NodeAccess::checkEtag($note, $arguments['etag'] ?? null);
        return $note;
    }

    /** Finds a note by file id, only inside the notes folder (Folder::getById is scoped to it). */
    private function note(?Folder $root, int $id): File {
        foreach ($root?->getById($id) ?? [] as $node) {
            if ($node instanceof File && self::isNote($node) && $node->isReadable()) {
                return $node;
            }
        }
        throw new ToolFailure(ToolFailure::NOT_FOUND);
    }

    /** @return array{id:int, title:string, category:string, modified:int, etag:string} */
    private function info(File $note, Folder $root): array {
        $relative = (string)$root->getRelativePath($note->getParent()->getPath());
        return [
            'id' => (int)$note->getId(),
            'title' => pathinfo($note->getName(), PATHINFO_FILENAME),
            'category' => trim($relative, '/'),
            'modified' => (int)$note->getMTime(),
            'etag' => (string)$note->getEtag(),
        ];
    }

    private function checkSize(string $content): void {
        if (strlen($content) > self::MAX_BYTES) {
            throw new ToolFailure('Nota excede o limite de ' . self::MAX_BYTES . ' bytes.');
        }
    }

    private static function isNote(File $file): bool {
        return in_array(strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }

    /** @throws InvalidArgumentException when nothing usable is left after sanitizing */
    private static function title(string $title): string {
        $clean = trim((string)preg_replace('/[\/\\\\\x00-\x1F\x7F]+/', ' ', $title), " .\t");
        if ($clean === '') {
            throw new InvalidArgumentException('Invalid argument: title');
        }
        return mb_substr($clean, 0, 200);
    }

    /** @throws InvalidArgumentException on traversal or control characters */
    private static function category(string $category): string {
        try {
            return PathGuard::normalize($category);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('Invalid argument: category');
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
