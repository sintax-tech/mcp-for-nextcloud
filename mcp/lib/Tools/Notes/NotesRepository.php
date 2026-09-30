<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Notes;

use InvalidArgumentException;
use OCA\Mcp\Tools\Common\NodeAccess;
use OCA\Mcp\Tools\Common\PathGuard;
use OCA\Mcp\Tools\ToolFailure;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;

/**
 * The Notes app's files seen through the user's IRootFolder view: the notes folder, lookup by file id
 * scoped to it, categories as subfolders and collision-free names. Nothing here bypasses Nextcloud ACLs.
 */
class NotesRepository {
    /** Maximum number of notes returned by all(). */
    public const MAX_NOTES = 2000;
    /** Notes app default folder when the user never changed the setting. */
    public const DEFAULT_FOLDER = 'Notes';
    /** File extensions the Notes app treats as notes. */
    private const EXTENSIONS = ['md', 'txt'];
    /** Highest " (n)" suffix tried before giving up on a title. */
    private const MAX_SUFFIX = 100;

    public function __construct(
        private IRootFolder $rootFolder,
        private IConfig $config,
    ) {}

    /**
     * @param string $userId authenticated user
     * @param bool $create create the folder when missing (only for note creation)
     * @return Folder|null the notes folder, null when it does not exist and $create is false
     * @throws InvalidArgumentException when the configured path contains traversal
     */
    public function folder(string $userId, bool $create): ?Folder {
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

    /**
     * @param Folder $root notes folder
     * @return list<File> notes below $root, recursively, at most MAX_NOTES
     */
    public function all(Folder $root): array {
        $notes = [];
        $pending = [$root];
        while ($pending !== [] && count($notes) < self::MAX_NOTES) {
            foreach (array_shift($pending)->getDirectoryListing() as $node) {
                if ($node instanceof Folder) {
                    $pending[] = $node;
                } elseif ($node instanceof File && self::isNote($node) && count($notes) < self::MAX_NOTES) {
                    $notes[] = $node;
                }
            }
        }
        return $notes;
    }

    /**
     * Finds a note by file id, only inside the notes folder (Folder::getById is scoped to it).
     *
     * @param Folder|null $root notes folder, null when it does not exist
     * @param int $id file id
     * @return File readable note
     * @throws ToolFailure NOT_FOUND when missing, outside the folder or not a note
     */
    public function find(?Folder $root, int $id): File {
        foreach ($root?->getById($id) ?? [] as $node) {
            if ($node instanceof File && self::isNote($node) && $node->isReadable()) {
                return $node;
            }
        }
        throw new ToolFailure(ToolFailure::NOT_FOUND);
    }

    /**
     * @param File $note note file
     * @param Folder $root notes folder
     * @return array{id:int, title:string, category:string, modified:int, etag:string}
     */
    public function info(File $note, Folder $root): array {
        $relative = (string)$root->getRelativePath($note->getParent()->getPath());
        return [
            'id' => (int)$note->getId(),
            'title' => pathinfo($note->getName(), PATHINFO_FILENAME),
            'category' => trim($relative, '/'),
            'modified' => (int)$note->getMTime(),
            'etag' => (string)$note->getEtag(),
        ];
    }

    /**
     * @param Folder $root notes folder
     * @param string $category subfolder path; '' is the root
     * @return Folder existing or newly created category folder
     * @throws InvalidArgumentException on traversal or control characters
     * @throws ToolFailure when a file already uses one of the folder names
     */
    public function category(Folder $root, string $category): Folder {
        try {
            $relative = PathGuard::normalize($category);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('Invalid argument: category');
        }
        return NodeAccess::ensureFolder($root, $relative);
    }

    /**
     * @param Folder $folder category folder
     * @param string $title raw title
     * @return string "<title>.md", or "<title> (n).md" when taken; never an existing name
     * @throws InvalidArgumentException when the title is empty after sanitizing
     * @throws ToolFailure when MAX_SUFFIX names are already taken
     */
    public function freeName(Folder $folder, string $title): string {
        $base = self::title($title);
        $name = $base . '.md';
        for ($i = 2; $folder->nodeExists($name); $i++) {
            if ($i > self::MAX_SUFFIX) {
                throw new ToolFailure('Já existem notas demais com este título.');
            }
            $name = $base . ' (' . $i . ').md';
        }
        return $name;
    }

    /**
     * @param File $note note being renamed
     * @param string $title raw new title
     * @return string new file name keeping the note's extension
     * @throws InvalidArgumentException when the title is empty after sanitizing
     */
    public function renamedName(File $note, string $title): string {
        return self::title($title) . '.' . strtolower(pathinfo($note->getName(), PATHINFO_EXTENSION));
    }

    /** @return bool whether the file has a note extension */
    public static function isNote(File $file): bool {
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
}
