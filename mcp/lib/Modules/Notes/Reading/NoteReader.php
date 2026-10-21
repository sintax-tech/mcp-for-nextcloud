<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Reading;

use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
class NoteReader
{

    public function __construct(
        private \OCA\Mcp\Modules\Notes\Storage\NotesFolders $folders,
        private \OCA\Mcp\Modules\Notes\Storage\NoteLookup $lookup,
        private \OCA\Mcp\Modules\Notes\Storage\NoteMetadata $metadata,
        private \OCA\Mcp\Modules\Notes\Content\NoteContent $content,
        private NodeAccessInfo $accessInfo,
    )
    {
    }

    /** @return array{id:int, title:string, category:string, modified:int, etag:string, content:string, access:array<string, mixed>} */

    public function read(string $userId, int $id): array
    {
        $root = $this->folders->folder($userId, false);
        $note = $this->lookup->find($root, $id);
        return $this->metadata->info($note, $root) + ['content' => $this->content->read($note), 'access' => $this->accessInfo->describe($note, $userId)];
    }
}
