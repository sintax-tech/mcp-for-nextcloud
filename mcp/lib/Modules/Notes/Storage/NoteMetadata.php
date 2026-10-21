<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Storage;

use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCP\Files\File;
use OCP\Files\Folder;
class NoteMetadata
{

    public function __construct(private ?VisibilityGuard $visibilityGuard = null)
    {
    }

    /**
     * @param File $note note file
     * @param Folder $root notes folder
     * @return array{id:int, title:string, category:string, modified:int, etag:string}
     */

    public function info(File $note, Folder $root): array
    {
        $this->visibilityGuard?->assertVisible($note);
        $relative = (string) $root->getRelativePath($note->getParent()->getPath());
        return ['id' => (int) $note->getId(), 'title' => pathinfo($note->getName(), PATHINFO_FILENAME), 'category' => trim($relative, '/'), 'modified' => (int) $note->getMTime(), 'etag' => (string) $note->getEtag()];
    }
}
