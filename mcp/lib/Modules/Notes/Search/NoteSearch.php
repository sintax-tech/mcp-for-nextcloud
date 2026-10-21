<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Search;

use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCP\Files\File;
class NoteSearch
{

    public function __construct(
        private \OCA\Mcp\Modules\Notes\Storage\NotesFolders $folders,
        private \OCA\Mcp\Modules\Notes\Storage\NoteLookup $lookup,
        private \OCA\Mcp\Modules\Notes\Storage\NoteMetadata $metadata,
        private NodeAccessInfo $accessInfo,
        private ?VisibilityGuard $visibilityGuard = null,
    )
    {
    }

    /** @return list<array{id:int, title:string, category:string, modified:int, etag:string, snippet:string, access:array<string, mixed>}> */

    public function search(string $userId, string $query, int $limit, string $category): array
    {
        $root = $this->folders->folder($userId, false);
        if ($root === null || $query === '') {
            return [];
        }
        $targetFolder = $this->folders->existingCategory($root, $category);
        if ($targetFolder === null) {
            return [];
        }
        $allNotes = $this->lookup->all($targetFolder);
        usort($allNotes, static fn(File $a, File $b) => $b->getMTime() <=> $a->getMTime());
        $visibleNotes = $this->visibilityGuard?->filter($allNotes) ?? array_values($allNotes);
        $results = [];
        foreach ($visibleNotes as $note) {
            if (!$note instanceof File || !\OCA\Mcp\Modules\Notes\Storage\NoteLookup::isNote($note) || !$note->isReadable()) {
                continue;
            }
            $title = pathinfo($note->getName(), PATHINFO_FILENAME);
            $titleMatch = mb_stripos($title, $query) !== false;
            $contentMatch = false;
            $contentPos = false;
            $content = '';
            if ($note->getSize() <= \OCA\Mcp\Modules\Notes\Content\NoteContent::MAX_BYTES) {
                $content = mb_scrub((string) $note->getContent(), 'UTF-8');
                $contentPos = mb_stripos($content, $query);
                $contentMatch = $contentPos !== false;
            }
            if (!$titleMatch && !$contentMatch) {
                continue;
            }
            if ($contentMatch && $contentPos !== false) {
                $start = max(0, $contentPos - 50);
                $length = 120 + mb_strlen($query);
                $snippet = mb_substr($content, $start, $length);
                $snippet = trim((string) preg_replace('/\s+/', ' ', $snippet));
                if ($start > 0) {
                    $snippet = '...' . $snippet;
                }
                if ($start + $length < mb_strlen($content)) {
                    $snippet .= '...';
                }
            } else {
                $snippet = trim((string) preg_replace('/\s+/', ' ', mb_substr($content, 0, 100)));
                if (mb_strlen($content) > 100) {
                    $snippet .= '...';
                }
            }
            $results[] = $this->metadata->info($note, $root) + ['snippet' => $snippet, 'access' => $this->accessInfo->describe($note, $userId)];
            if (count($results) >= $limit) {
                break;
            }
        }
        return $results;
    }
}
