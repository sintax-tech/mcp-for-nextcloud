<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Editing;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use InvalidArgumentException;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\File;
class RenameTarget
{

    public function __construct(private \OCA\Mcp\Modules\Notes\Storage\NoteNames $names)
    {
    }

    /**
     * Where a note goes when it gets a new title.
     *
     * @param File $note note being renamed
     * @param string $title raw new title
     * @return string|null the new path, null when the name does not change
     * @throws InvalidArgumentException when nothing usable is left of the title
     * @throws ToolFailure when the rename is not permitted or another note in the category already has that name
     */

    public function resolve(File $note, string $title): ?string
    {
        $name = $this->names->renamedName($note, $title);
        if ($name === $note->getName()) {
            return null;
        }
        $parent = $note->getParent();
        // A new title is a move inside the folder, and Nextcloud allows a move only with update on the
        // node, delete at the source and create at the destination (the same folder).
        if (!$note->isUpdateable() || !$note->isDeletable() || !$parent->isCreatable()) {
            throw new ToolFailure(FileAccessMessages::forbidden());
        }
        if ($parent->nodeExists($name)) {
            throw new ToolFailure(\OCA\Mcp\Modules\Notes\Storage\NamingMessages::titleExistsInCategory());
        }
        return $parent->getPath() . '/' . $name;
    }
}
