<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Execution;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\File;
use OCP\Files\Folder;
class NoteSnapshot
{

    public function __construct(private \OCA\Mcp\Modules\Notes\Storage\NoteMetadata $metadata)
    {
    }
    private const EXCERPT_CHARS = 400;

    /**
     * The note as a plan shows it: where it is and what it says, without reading it whole.
     *
     * @param Folder $root notes folder
     * @param File $note note to describe
     * @return array<string, mixed>
     * @throws ToolFailure when the note is over the read limit
     */

    public function snapshot(Folder $root, File $note): array
    {
        $size = (int) $note->getSize();
        if ($size > \OCA\Mcp\Modules\Notes\Content\NoteContent::MAX_BYTES) {
            throw new ToolFailure(\OCA\Mcp\Modules\Notes\Content\ContentMessages::noteTooLargeForReading(\OCA\Mcp\Modules\Notes\Content\NoteContent::MAX_BYTES));
        }
        return $this->metadata->info($note, $root) + ['content' => $this->excerpt((string) $note->getContent()), 'bytes' => $size];
    }

    /**
     * @param string $content full content of a note
     * @return string the beginning of it, marked when there is more
     */

    public function excerpt(string $content): string
    {
        return mb_strlen($content) > self::EXCERPT_CHARS ? mb_substr($content, 0, self::EXCERPT_CHARS) . \OCA\Mcp\Modules\Notes\Presentation\NotesPlanMessages::excerptTruncated() : $content;
    }
}
