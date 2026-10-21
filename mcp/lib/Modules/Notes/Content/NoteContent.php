<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Content;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\File;
class NoteContent
{
    public const MAX_BYTES = 1048576;

    /**
     * Reads a note's text content, checking the byte limit.
     * Shared by notes_read and nc://notes/{id}.
     *
     * @param File $note readable note file
     * @return string UTF-8 scrubbed content
     * @throws ToolFailure when the note exceeds MAX_BYTES
     */

    public function read(File $note): string
    {
        if ($note->getSize() > self::MAX_BYTES) {
            throw new ToolFailure(\OCA\Mcp\Modules\Notes\Content\ContentMessages::noteTooLargeForReading(self::MAX_BYTES));
        }
        return mb_scrub((string) $note->getContent(), 'UTF-8');
    }

    /** @throws ToolFailure when the content is over MAX_BYTES */

    public function assertSize(string $content): void
    {
        if (strlen($content) > self::MAX_BYTES) {
            throw new ToolFailure(\OCA\Mcp\Modules\Notes\Content\ContentMessages::noteTooLarge(self::MAX_BYTES));
        }
    }
}
