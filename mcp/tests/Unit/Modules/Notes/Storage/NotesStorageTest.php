<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Notes\Storage;

use OCA\Mcp\Modules\Notes\Storage\NotesFolders;
use OCA\Mcp\Modules\Notes\Storage\NoteLookup;
use OCA\Mcp\Modules\Notes\Storage\NoteNames;
use OCA\Mcp\Modules\Notes\Content\NoteContent;
final class NotesStorageTest extends \OCA\Mcp\Tests\Support\Notes\NotesTestCase
{

    public function testStorageOwnersKeepScopedLookupNamingAndContentLimits(): void
    {
        self::assertTrue(class_exists(NotesFolders::class), 'Focused Notes storage is missing');
        $folders = new NotesFolders($this->root, $this->config->mock($this));
        $root = $folders->folder('alice', false);
        $lookup = new NoteLookup();
        $file = $lookup->find($root, $this->id);
        self::assertSame('Before (2).md', (new NoteNames())->freeName($folders->existingCategory($root, 'Work'), 'Before'));
        self::assertSame('# Original', (new NoteContent())->read($file));
        (new NoteContent())->assertSize(str_repeat('é', 524288));
        self::assertCount(1, $lookup->all($root));
        self::assertSame([], $this->tree->ops);
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        (new NoteContent())->assertSize(str_repeat('é', 524289));
    }
}
