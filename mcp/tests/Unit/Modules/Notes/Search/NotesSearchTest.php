<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Notes\Search;

use OCA\Mcp\Modules\Notes\Search\NoteSearch;
final class NotesSearchTest extends \OCA\Mcp\Tests\Support\Notes\NotesTestCase
{

    public function testFocusedCapabilityReadsWithoutMutationAndResolvesRootOnce(): void
    {
        self::assertTrue(class_exists(NoteSearch::class), 'Reading capability is missing');
        $cap = new NoteSearch(new \OCA\Mcp\Modules\Notes\Storage\NotesFolders($this->root, $this->config->mock($this)), new \OCA\Mcp\Modules\Notes\Storage\NoteLookup(), new \OCA\Mcp\Modules\Notes\Storage\NoteMetadata(), new \OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo(\OCA\Mcp\Tests\Support\Identity\FakeUsers::manager($this, \OCA\Mcp\Tests\Support\Identity\FakeUsers::DEFAULTS), $this->tree->shareManager()));
        $result = $cap->search('alice', 'Original', 20, '');
        self::assertSame('# Original', $result[0]['snippet']);
        self::assertSame(1, $this->rootReads);
        self::assertSame([], $this->tree->ops);
    }
}
