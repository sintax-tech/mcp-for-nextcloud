<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Files\Reading;

use OCA\Mcp\Modules\Files\Reading\DirectoryReader;
use OCA\Mcp\Modules\Files\Reading\FileEntry;
use OCA\Mcp\Tests\Support\Platform\FileAccess\FakeTree;
use OCA\Mcp\Tests\Support\Identity\FakeUsers;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use PHPUnit\Framework\TestCase;

final class DirectoryReaderTest extends TestCase {
    public function testListingNeedsOnlyReadCollaboratorsAndOrdersFoldersFirst(): void {
        self::assertTrue(class_exists(DirectoryReader::class), 'Directory reading must have an independent capability');
        $tree = new FakeTree($this);
        $tree->addFile('/alice/files/a.txt', 'Read me', 'text/plain');
        $tree->addFolder('/alice/files/z');
        $access = new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $tree->shareManager());
        $reader = new DirectoryReader(new FileEntry($access), \OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this));
        $entries = $reader->list($tree->rootFolder(), 'alice', '/');
        self::assertSame(['z', 'a.txt'], array_column($entries, 'name'));
        self::assertSame([], $tree->ops);
    }
}
