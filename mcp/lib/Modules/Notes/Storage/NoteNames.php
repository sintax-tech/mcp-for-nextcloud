<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Storage;

use InvalidArgumentException;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\File;
use OCP\Files\Folder;
class NoteNames
{
    private const MAX_SUFFIX = 100;

    /**
     * @param Folder $folder category folder
     * @param string $title raw title
     * @return string "<title>.md", or "<title> (n).md" when taken; never an existing name
     * @throws InvalidArgumentException when the title is empty after sanitizing
     * @throws ToolFailure when MAX_SUFFIX names are already taken
     */

    public function freeName(Folder $folder, string $title): string
    {
        $base = self::title($title);
        $name = $base . '.md';
        for ($i = 2; $folder->nodeExists($name); $i++) {
            if ($i > self::MAX_SUFFIX) {
                throw new ToolFailure(\OCA\Mcp\Modules\Notes\Storage\NamingMessages::tooManyNotesWithSameTitle());
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

    public function renamedName(File $note, string $title): string
    {
        return self::title($title) . '.' . strtolower(pathinfo($note->getName(), PATHINFO_EXTENSION));
    }

    /** @throws InvalidArgumentException when nothing usable is left after sanitizing */

    private static function title(string $title): string
    {
        $clean = trim((string) preg_replace('/[\/\\\\\\x00-\x1F\x7F]+/', ' ', $title), " .\t");
        if ($clean === '') {
            throw new InvalidArgumentException('Invalid argument: title');
        }
        return mb_substr($clean, 0, 200);
    }
}
