<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Resources;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
class NextcloudNoteResourceReader implements \OCA\Mcp\Modules\Notes\Contracts\NoteResourceReader
{

    public function __construct(
        private \OCA\Mcp\Modules\Notes\Storage\NotesFolders $folders,
        private \OCA\Mcp\Modules\Notes\Storage\NoteLookup $lookup,
        private \OCA\Mcp\Modules\Notes\Content\NoteContent $content,
        private VisibilityGuard $visibilityGuard,
    )
    {
    }

    public function read(string $uri, string $userId): array
    {
        $rawId = substr($uri, strlen('nc://notes/'));
        if (!ctype_digit($rawId) || $rawId === '0') {
            throw new ToolFailure(FileAccessMessages::notFound());
        }
        $id = (int) $rawId;
        return NodeAccess::run(function () use ($uri, $id, $userId): array {
            $notesFolder = $this->folders->folder($userId, false);
            $note = $this->lookup->find($notesFolder, $id);
            $this->visibilityGuard->assertVisible($note);
            return ['contents' => [['uri' => $uri, 'mimeType' => 'text/markdown', 'text' => $this->content->read($note)]]];
        });
    }
}
