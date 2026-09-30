<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCA\Mcp\Tools\Common\NodeAccess;
use OCA\Mcp\Tools\Common\PathGuard;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolModule;
use OCA\Mcp\Tools\ToolResult;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IUserManager;

/** Files tools: list, search, read and protected edit. There is deliberately no delete, move or rename. */
class FilesModule implements ToolModule {
    /** Characters returned by files_read before the text is truncated. */
    public const MAX_CHARS = 100000;
    /** Maximum size of the new content accepted by files_edit, in bytes. */
    public const MAX_EDIT_BYTES = 10 * 1024 * 1024;
    /** Folder in the user's root that receives a copy of every file before files_edit writes it. */
    public const BACKUP_FOLDER = 'MCP backups';

    public function __construct(
        private IRootFolder $rootFolder,
        private TextExtractor $extractor,
        private IAppManager $appManager,
        private IUserManager $userManager,
        private ITimeFactory $time,
    ) {}

    /** @return list<array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string}> */
    public function definitions(): array {
        return [
            ['name' => 'files_list', 'module' => 'files', 'operation' => 'read',
                'description' => 'Lista arquivos e pastas de um diretório do Nextcloud do usuário.',
                'inputSchema' => self::schema(['path' => ['type' => 'string', 'default' => '/', 'description' => 'Caminho da pasta, ex.: /Documentos']])],
            ['name' => 'files_search', 'module' => 'files', 'operation' => 'read',
                'description' => 'Busca arquivos pelo nome na pasta do usuário no Nextcloud.',
                'inputSchema' => self::schema([
                    'query' => ['type' => 'string', 'minLength' => 1, 'description' => 'Termo a buscar'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
                ], ['query'])],
            ['name' => 'files_read', 'module' => 'files', 'operation' => 'read',
                'description' => 'Lê o texto de um arquivo do Nextcloud (txt/md direto; PDF/DOCX/ODT com extração de texto).',
                'inputSchema' => self::schema(['path' => ['type' => 'string', 'minLength' => 1, 'description' => 'Caminho do arquivo, ex.: /Documentos/relatorio.pdf']], ['path'])],
            ['name' => 'files_edit', 'module' => 'files', 'operation' => 'edit',
                'description' => 'Substitui o conteúdo de um arquivo de texto existente. Antes de gravar, exige versionamento ativo e cria uma cópia em "/MCP backups". Nunca cria, move ou exclui arquivos.',
                'inputSchema' => self::schema([
                    'path' => ['type' => 'string', 'minLength' => 1, 'description' => 'Caminho do arquivo de texto'],
                    'content' => ['type' => 'string', 'description' => 'Novo conteúdo completo (UTF-8)'],
                    'etag' => ['type' => 'string', 'description' => 'ETag lido antes; se divergir, nada é gravado'],
                ], ['path', 'content'])],
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
        return NodeAccess::run(fn () => match ($name) {
            'files_list' => ToolResult::json($this->list($root, $arguments['path'])),
            'files_search' => ToolResult::json($this->search($root, $arguments['query'], $arguments['limit'])),
            'files_read' => ToolResult::text($this->read($root, $arguments['path'])),
            'files_edit' => ToolResult::json($this->edit($root, $userId, $arguments['path'], $arguments['content'], $arguments['etag'] ?? null)),
            default => throw new \InvalidArgumentException('Unknown tool'),
        });
    }

    /** @return list<array{name:string, path:string, isDir:bool, size:int, mtime:string, contentType:string}> */
    private function list(Folder $root, string $path): array {
        $folder = NodeAccess::get($root, $path);
        if (!$folder instanceof Folder) {
            throw new ToolFailure('O caminho informado não é uma pasta.');
        }
        $entries = array_map(fn (Node $node) => $this->entry($root, $node), $folder->getDirectoryListing());
        usort($entries, static fn (array $a, array $b) => [$b['isDir'], $a['name']] <=> [$a['isDir'], $b['name']]);
        return $entries;
    }

    /** @return list<array{name:string, path:string, isDir:bool, size:int, mtime:string, contentType:string}> */
    private function search(Folder $root, string $query, int $limit): array {
        $out = [];
        foreach ($root->search($query) as $node) {
            if ($node->getPath() === $root->getPath() || !$node->isReadable()) {
                continue;
            }
            $out[] = $this->entry($root, $node);
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    private function read(Folder $root, string $path): string {
        $text = $this->extractor->extract(NodeAccess::requireFile(NodeAccess::get($root, $path)));
        return mb_strlen($text) > self::MAX_CHARS ? mb_substr($text, 0, self::MAX_CHARS) . "\n\n[conteúdo truncado]" : $text;
    }

    /** @return array{path:string, size:int, etag:string, backup:string} */
    private function edit(Folder $root, string $userId, string $path, string $content, ?string $etag): array {
        $path = PathGuard::normalize($path);
        if ($path === '/' . self::BACKUP_FOLDER || str_starts_with($path, '/' . self::BACKUP_FOLDER . '/')) {
            throw new ToolFailure('Arquivos em "/' . self::BACKUP_FOLDER . '" não podem ser editados pelo MCP.');
        }
        $file = NodeAccess::requireFile(NodeAccess::get($root, $path));
        if (!TextExtractor::isText($file)) {
            throw new ToolFailure('Somente arquivos de texto podem ser editados pelo MCP.');
        }
        if (strlen($content) > self::MAX_EDIT_BYTES) {
            throw new ToolFailure('Conteúdo excede o limite de edição de ' . self::MAX_EDIT_BYTES . ' bytes.');
        }
        // Preconditions, in order; any failure leaves the original untouched.
        $user = $this->userManager->get($userId);
        if ($user === null || !$this->appManager->isEnabledForUser('files_versions', $user)) {
            throw new ToolFailure('Edição bloqueada: o versionamento de arquivos (files_versions) não está ativo.');
        }
        if (!$file->isUpdateable()) {
            throw new ToolFailure(ToolFailure::FORBIDDEN);
        }
        NodeAccess::checkEtag($file, $etag);
        $backup = $this->backup($root, $file, $path);

        try {
            $file->putContent($content);
        } catch (\Throwable) {
            throw new ToolFailure('Falha ao gravar o arquivo; o original foi preservado em ' . $backup . '.');
        }
        $saved = $root->get(ltrim($path, '/'));
        return ['path' => $path, 'size' => strlen($content), 'etag' => $saved->getEtag(), 'backup' => $backup];
    }

    /** Copies the original into the user's backup folder and verifies the copy before any write. */
    private function backup(Folder $root, File $file, string $path): string {
        try {
            $folder = NodeAccess::ensureFolder($root, self::BACKUP_FOLDER . dirname($path));
            $base = $file->getName() . '.' . gmdate('Ymd-His', $this->time->getTime());
            $name = $base . '.bak';
            for ($i = 2; $folder->nodeExists($name); $i++) {
                $name = $base . '-' . $i . '.bak';
            }
            $copy = $file->copy($folder->getPath() . '/' . $name);
            $valid = $copy instanceof File && $copy->getSize() === $file->getSize();
        } catch (\Throwable) {
            $valid = false;
        }
        if (!$valid) {
            throw new ToolFailure('Edição bloqueada: não foi possível criar a cópia de segurança do original.');
        }
        return $root->getRelativePath($copy->getPath()) ?? '';
    }

    /** @return array{name:string, path:string, isDir:bool, size:int, mtime:string, contentType:string} */
    private function entry(Folder $root, Node $node): array {
        $isDir = NodeAccess::isFolder($node);
        return [
            'name' => $node->getName(),
            'path' => $root->getRelativePath($node->getPath()) ?? '',
            'isDir' => $isDir,
            'size' => $isDir ? 0 : (int)$node->getSize(),
            'mtime' => gmdate('D, d M Y H:i:s \G\M\T', (int)$node->getMTime()),
            'contentType' => $isDir ? 'httpd/unix-directory' : (string)$node->getMimetype(),
        ];
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
