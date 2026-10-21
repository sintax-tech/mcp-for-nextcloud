<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Notes\Resources;

use OCA\Mcp\Modules\Notes\Resources\NextcloudNoteResourceReader;
use OCA\Mcp\Modules\Notes\Storage\NotesFolders;
use OCA\Mcp\Modules\Notes\Storage\NoteLookup;
use OCA\Mcp\Modules\Notes\Content\NoteContent;
final class NotesResourceReaderTest extends \OCA\Mcp\Tests\Support\Notes\NotesTestCase
{

    public function testResourceReaderKeepsEnvelopeAndChecksVisibilityBeforeContent(): void
    {
        self::assertTrue(class_exists(NextcloudNoteResourceReader::class), 'Permanent resource reader is missing');
        $guard = $this->createMock(\OCA\Mcp\Access\Visibility\VisibilityGuard::class);
        $reader = new NextcloudNoteResourceReader(new NotesFolders($this->root, $this->config->mock($this)), new NoteLookup(), new NoteContent(), $guard);
        self::assertSame(['contents' => [['uri' => 'nc://notes/' . $this->id, 'mimeType' => 'text/markdown', 'text' => '# Original']]], $reader->read('nc://notes/' . $this->id, 'alice'));
        $guard->method('assertVisible')->willThrowException(new \OCA\Mcp\Mcp\Tools\Results\ToolFailure('hidden'));
        $this->tree->nodes['/alice/files/Notes/Work/Before.md']['size'] = 1048577;
        $this->expectExceptionMessage('hidden');
        $reader->read('nc://notes/' . $this->id, 'alice');
    }
}
