<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Storage;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\File;
use OCP\Files\Folder;
class NoteLookup
{
    public const MAX_NOTES = 2000;
    private const EXTENSIONS = ['md', 'txt'];

    public function __construct(private ?VisibilityGuard $visibilityGuard = null)
    {
    }

    /**
     * @param Folder $root notes folder
     * @return list<File> notes below $root, recursively, at most MAX_NOTES
     */

    public function all(Folder $root): array
    {
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

    public function find(?Folder $root, int $id): File
    {
        foreach ($root?->getById($id) ?? [] as $node) {
            if ($node instanceof File && self::isNote($node) && $node->isReadable() && ($this->visibilityGuard === null || $this->visibilityGuard->isVisible($node))) {
                return $node;
            }
        }
        throw new ToolFailure(FileAccessMessages::notFound());
    }

    /** @return bool whether the file has a note extension */

    public static function isNote(File $file): bool
    {
        return in_array(strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }
}
