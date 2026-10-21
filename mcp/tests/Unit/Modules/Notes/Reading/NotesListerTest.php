<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Notes\Reading;

use OCA\Mcp\Modules\Notes\Reading\NoteLister;
final class NotesListerTest extends \OCA\Mcp\Tests\Support\Notes\NotesTestCase
{

    public function testFocusedCapabilityReadsWithoutMutationAndResolvesRootOnce(): void
    {
        self::assertTrue(class_exists(NoteLister::class), 'Reading capability is missing');
        $cap = new NoteLister(new \OCA\Mcp\Modules\Notes\Storage\NotesFolders($this->root, $this->config->mock($this)), new \OCA\Mcp\Modules\Notes\Storage\NoteLookup(), new \OCA\Mcp\Modules\Notes\Storage\NoteMetadata(), new \OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo(\OCA\Mcp\Tests\Support\Identity\FakeUsers::manager($this, \OCA\Mcp\Tests\Support\Identity\FakeUsers::DEFAULTS), $this->tree->shareManager()));
        $result = $cap->list('alice');
        self::assertSame([$this->id], array_column($result, 'id'));
        self::assertSame(1, $this->rootReads);
        self::assertSame([], $this->tree->ops);
    }
}
