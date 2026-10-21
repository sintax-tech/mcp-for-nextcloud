<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Reading;

use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCP\Files\File;
class NoteLister
{

    public function __construct(
        private \OCA\Mcp\Modules\Notes\Storage\NotesFolders $folders,
        private \OCA\Mcp\Modules\Notes\Storage\NoteLookup $lookup,
        private \OCA\Mcp\Modules\Notes\Storage\NoteMetadata $metadata,
        private NodeAccessInfo $accessInfo,
    )
    {
    }

    /** @return list<array{id:int, title:string, category:string, modified:int, etag:string, access:array<string, mixed>}> */

    public function list(string $userId): array
    {
        $root = $this->folders->folder($userId, false);
        if ($root === null) {
            return [];
        }
        $notes = array_map(fn(File $note) => $this->metadata->info($note, $root) + ['access' => $this->accessInfo->describe($note, $userId)], $this->lookup->all($root));
        usort($notes, static fn(array $a, array $b) => $b['modified'] <=> $a['modified']);
        return $notes;
    }
}
