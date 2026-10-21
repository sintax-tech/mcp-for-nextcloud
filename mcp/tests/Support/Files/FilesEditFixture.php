<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Support\Files;

use OCA\Mcp\Modules\Files\Editing\ContentPlan;
use OCA\Mcp\Modules\Files\Editing\ContentWriter;
use OCA\Mcp\Modules\Files\Editing\FileBackup;
use OCA\Mcp\Modules\Files\Editing\TextFileEditor;
use OCA\Mcp\Tests\Support\Platform\FileAccess\Locks\FakeLockManager;
use OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard;
use OCP\AppFramework\Utility\ITimeFactory;

abstract class FilesEditFixture extends FilesReadFixture {
    protected TextFileEditor $editor;
    protected FakeLockManager $locks;
    protected const FILE = '/alice/files/Notes/readme.md';

    protected function setUp(): void {
        parent::setUp();
        $this->locks = new FakeLockManager();
        $locks = $this->locks->service($this);
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1790000000);
        $backup = new FileBackup($this->apps, $this->users, $time, $this->config->mock($this), $this->visibility, $locks);
        $this->editor = new TextFileEditor($this->root, $this->extractor, new ContentWriter($backup, $this->access, $this->visibility, $locks), new ContentPlan($this->access, new SharedWriteGuard($this->access), $locks), new SharedWriteGuard($this->access), $this->visibility, $locks);
    }
}
