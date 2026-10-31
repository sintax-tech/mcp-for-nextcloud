<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Notes;

use InvalidArgumentException;
use OCA\Mcp\Tools\Common\NodeAccess;
use OCA\Mcp\Tools\Common\CommonMessages;
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
    /** Maximum note content size in bytes (1 MiB). */
    public const MAX_BYTES = 1024 * 1024;
    /** Notes app built-in default folder; {@see NotesFolderResolver} resolves the one a user actually has. */
    public const DEFAULT_FOLDER = NotesFolderResolver::DEFAULT_FOLDER;
    /** File extensions the Notes app treats as notes. */
    private const EXTENSIONS = ['md', 'txt'];
    /** Highest " (n)" suffix tried before giving up on a title. */
    private const MAX_SUFFIX = 100;

    public function __construct(
        private IRootFolder $rootFolder,
        private IConfig $config,
        private ?\OCA\Mcp\Service\VisibilityGuard $visibilityGuard = null,
        private ?NotesFolderResolver $folders = null,
    ) {
        $this->folders ??= new NotesFolderResolver($rootFolder, $config);
    }

    /**
     * @param string $userId authenticated user
     * @param bool $create create the folder when missing (only for note creation)
     * @return Folder|null the notes folder, null when it does not exist and $create is false
     * @throws InvalidArgumentException when the configured path contains traversal
     */
    public function folder(string $userId, bool $create): ?Folder {
        $userFolder = $this->rootFolder->getUserFolder($userId);
        $path = PathGuard::normalize($this->folders->path($userId));
        if ($create) {
            return NodeAccess::ensureFolder($userFolder, $path, $this->visibilityGuard);
        }
        if (!$userFolder->nodeExists(ltrim($path, '/'))) {
            return null;
        }
        $folder = $userFolder->get(ltrim($path, '/'));
        return $folder instanceof Folder ? $folder : null;
    }

    /**
     * The deepest folder that already exists on the way to a category, which is where a creation lands:
     * the notes folder or a category in it when they exist, otherwise the nearest one that does. The
     * walk stops at anything hidden or not a folder, so what it returns is always something the caller
     * may be told about. Nothing is created.
     *
     * @param string $userId authenticated user
     * @param string $category requested category; '' is the notes folder
     * @return Folder the existing folder the write would reach
     * @throws InvalidArgumentException on traversal or control characters
     */
    public function existingAncestor(string $userId, string $category): Folder {
        $folder = $this->rootFolder->getUserFolder($userId);
        $notes = PathGuard::normalize($this->folders->path($userId));
        try {
            $below = PathGuard::normalize($category);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('Invalid argument: category');
        }
        foreach (PathGuard::segments($notes . '/' . $below) as $segment) {
            if (!$folder->nodeExists($segment)) {
                break;
            }
            $next = $folder->get($segment);
            if (!$next instanceof Folder || ($this->visibilityGuard !== null && !$this->visibilityGuard->isVisible($next))) {
                break;
            }
            $folder = $next;
        }
        return $folder;
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
                if ($this->visibilityGuard !== null && !$this->visibilityGuard->isVisible($node)) {
                    continue;
                }
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
            if ($node instanceof File && self::isNote($node) && $node->isReadable() && ($this->visibilityGuard === null || $this->visibilityGuard->isVisible($node))) {
                return $node;
            }
        }
        throw new ToolFailure(CommonMessages::notFound());
    }

    /**
     * Reads a note's text content, checking the byte limit.
     * Shared by notes_read and nc://notes/{id}.
     *
     * @param File $note readable note file
     * @return string UTF-8 scrubbed content
     * @throws ToolFailure when the note exceeds MAX_BYTES
     */
    public function read(File $note): string {
        return mb_scrub($this->raw($note), 'UTF-8');
    }

    /**
     * A note's content exactly as stored, for a change applied on top of it: scrubbing it would rewrite bytes
     * the change never touched.
     *
     * @param File $note readable note file
     * @return string raw content
     * @throws ToolFailure when the note exceeds MAX_BYTES
     */
    public function raw(File $note): string {
        if ($note->getSize() > self::MAX_BYTES) {
            throw new ToolFailure(NotesMessages::noteTooLargeForReading(self::MAX_BYTES));
        }
        return (string)$note->getContent();
    }

    /**
     * @param File $note note file
     * @param string $userId authenticated user
     * @return string the note's path relative to the user's folder, as the Files tools take it
     */
    public function path(File $note, string $userId): string {
        return (string)$this->rootFolder->getUserFolder($userId)->getRelativePath($note->getPath());
    }

    /**
     * @param File $note note file
     * @param Folder $root notes folder
     * @return array{id:int, title:string, category:string, modified:int, etag:string}
     */
    public function info(File $note, Folder $root): array {
        $this->visibilityGuard?->assertVisible($note);
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
     * Walks the category tree as it exists today, without creating anything: the notes root and every
     * ancestor of the requested category that already exists must be visible. A hidden one refuses exactly
     * like a category that does not exist, even for the empty category. The walk stops at the first missing
     * segment, because what is below it would be created under a checked, visible parent
     * ({@see NodeAccess::ensureFolder} with the guard does the same walk when it is time to write).
     *
     * @param Folder $root notes folder
     * @param string $category requested category; '' checks the notes root itself
     * @throws InvalidArgumentException on traversal or control characters
     * @throws ToolFailure when the root or an existing ancestor is hidden
     */
    public function assertCategoryVisible(Folder $root, string $category): void {
        try {
            $relative = PathGuard::normalize($category);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('Invalid argument: category');
        }
        if ($this->visibilityGuard === null) {
            return;
        }
        $this->visibilityGuard->assertVisible($root);
        $folder = $root;
        foreach (PathGuard::segments($relative) as $segment) {
            if (!$folder->nodeExists($segment)) {
                return;
            }
            $next = $folder->get($segment);
            $this->visibilityGuard->assertVisible($next);
            if (!$next instanceof Folder) {
                return;
            }
            $folder = $next;
        }
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
        $folder = NodeAccess::ensureFolder($root, $relative, $this->visibilityGuard);
        if ($this->visibilityGuard !== null) {
            $this->visibilityGuard->assertVisible($folder);
        }
        return $folder;
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
                throw new ToolFailure(NotesMessages::tooManyNotesWithSameTitle());
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
    public static function title(string $title): string {
        $clean = trim((string)preg_replace('/[\/\\\\\x00-\x1F\x7F]+/', ' ', $title), " .\t");
        if ($clean === '') {
            throw new InvalidArgumentException('Invalid argument: title');
        }
        return mb_substr($clean, 0, 200);
    }
}
