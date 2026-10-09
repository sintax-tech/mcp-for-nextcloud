<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Tests\Unit\Tools\Common\FakeLock;
use OCA\Mcp\Tests\Unit\Tools\Common\FakeLockManager;
use OCA\Mcp\Tools\Common\LockWriteFailure;
use OCA\Mcp\Tools\Files\FilesMessages;
use OCP\Files\Lock\ILock;

/**
 * Every Files write meets a lock of files_lock the same way: the plan warns about it, the confirmed call is refused
 * before the backup, the guard or the first byte, and a lock that appears in between is explained by the catch. The
 * own manual lock of the user lets the write through. Nothing ever takes or releases a lock.
 */
final class FilesLockTest extends FilesToolsTestCase {
    private const FILE = '/alice/files/Documentos/ata.md';

    private function id(string $path = self::FILE): int {
        return $this->tree->nodes[$path]['id'];
    }

    private function lockBy(int $type, string $owner, string $path = self::FILE): void {
        $this->locks->put(new FakeLock($this->id($path), $type, $owner));
    }

    private function refused(string $name, array $arguments): LockWriteFailure {
        try {
            $this->tool($name, $arguments);
        } catch (LockWriteFailure $e) {
            return $e;
        }
        $this->fail("$name was not refused");
    }

    private function assertUntouched(): void {
        $this->assertSame([], $this->tree->ops, 'no backup, folder or write happens');
        $this->assertSame("# Ata\nolá", $this->tree->nodes[self::FILE]['content']);
        $this->assertSame([], $this->locks->forbidden);
    }

    public function testAnEditOfAFileOpenInOfficeIsRefusedBeforeTheBackup(): void {
        $this->lockBy(ILock::TYPE_APP, 'richdocuments');
        $e = $this->refused('files_edit', ['path' => '/Documentos/ata.md', 'content' => 'novo']);
        $this->assertStringStartsWith('The file “/Documentos/ata.md” is locked by Nextcloud Office', $e->getMessage());
        $this->assertStringEndsWith('Nothing was changed.', $e->getMessage());
        $this->assertUntouched();
    }

    public function testAReplaceOfAFileLockedByAnotherPersonIsRefused(): void {
        $this->lockBy(ILock::TYPE_USER, 'pedro');
        $e = $this->refused('files_replace', ['path' => '/Documentos/ata.md', 'old' => 'olá', 'new' => 'oi']);
        $this->assertStringContainsString('is locked by Pedro Almeida.', $e->getMessage());
        $this->assertUntouched();
    }

    public function testTheOwnManualLockLetsTheEditThroughAndKeepsTheLock(): void {
        $this->lockBy(ILock::TYPE_USER, 'alice');
        $this->json('files_edit', ['path' => '/Documentos/ata.md', 'content' => 'novo']);
        $this->assertSame('novo', $this->tree->nodes[self::FILE]['content']);
        $this->assertCount(1, $this->locks->locks[$this->id()]);
    }

    public function testALockThatAppearsAfterTheBackupIsExplainedAndNamesTheBackup(): void {
        $this->tree->writeFailure = FakeLockManager::refusal(new FakeLock($this->id(), ILock::TYPE_APP, 'text'));
        $e = $this->refused('files_edit', ['path' => '/Documentos/ata.md', 'content' => 'novo']);
        $this->assertStringContainsString('is locked, and Nextcloud does not say by whom.', $e->getMessage());
        $this->assertMatchesRegularExpression('#The original is kept in /MCP backups/Documentos/ata\.md\.[0-9-]+\.bak\.#', $e->getMessage());
        $this->assertSame("# Ata\nolá", $this->tree->nodes[self::FILE]['content']);
    }

    public function testTheEditPlanWarnsAndWritesNothing(): void {
        $this->lockBy(ILock::TYPE_APP, 'text');
        foreach (['files_edit' => ['path' => '/Documentos/ata.md', 'content' => 'novo'],
            'files_replace' => ['path' => '/Documentos/ata.md', 'old' => 'olá', 'new' => 'oi'],
            'files_checkout' => ['path' => '/Documentos/ata.md'],
            'files_move' => ['from' => '/Documentos/ata.md', 'to' => '/ata.md']] as $tool => $arguments) {
            $plan = $this->plan($tool, $arguments);
            $this->assertSame($tool, $plan['action']);
            $this->assertTrue($plan['lock']['blocking'], $tool);
            $this->assertStringContainsString('is locked by Text', $plan['warnings'][0]['message'], $tool);
        }
        $this->assertUntouched();
        $this->assertSame([], $this->store->rows ?? []);
    }

    public function testACheckoutOfALockedFileMintsNoLink(): void {
        $this->lockBy(ILock::TYPE_TOKEN, 'pedro');
        $e = $this->refused('files_checkout', ['path' => '/Documentos/ata.md']);
        $this->assertStringContainsString('It was locked through a WebDAV client', $e->getMessage());
        $this->assertSame([], $this->issued);
    }

    public function testAMoveOfALockedFileIsRefusedAndItsPlanSaysWhy(): void {
        $this->lockBy(ILock::TYPE_APP, 'text');
        $e = $this->refused('files_move', ['from' => '/Documentos/ata.md', 'to' => '/ata.md']);
        $this->assertStringContainsString('is locked by Text', $e->getMessage());
        $this->assertArrayHasKey(self::FILE, $this->tree->nodes);
        $this->assertUntouched();
    }

    public function testABatchWithALockedItemIsRefusedWholeAndThePlanNamesTheItem(): void {
        $this->tree->addFile('/alice/files/Documentos/plano.md', 'plano', 'text/markdown');
        $this->lockBy(ILock::TYPE_APP, 'text', '/alice/files/Documentos/plano.md');
        $arguments = ['moves' => [
            ['from' => '/Documentos/ata.md', 'to' => '/Arquivo/ata.md'],
            ['from' => '/Documentos/plano.md', 'to' => '/Arquivo/plano.md'],
        ], 'mkdirs' => ['/Arquivo']];
        $plan = $this->plan('files_move_batch', $arguments);
        $this->assertFalse($plan['ok']);
        $this->assertSame('/Documentos/plano.md', $plan['denied'][0]['from']);
        $this->assertStringContainsString('is locked by Text', $plan['denied'][0]['reason']);
        $this->assertSame(FilesMessages::batchNotOk(0, 1), $this->failure('files_move_batch', $arguments));
        $this->assertSame([], $this->tree->ops, 'no folder created, nothing moved');
    }

    public function testABatchItemLockedDuringTheRunIsReportedWithTheExplanation(): void {
        $this->tree->addFolder('/alice/files/Arquivo');
        $this->tree->throwOnMove[self::FILE] = FakeLockManager::refusal(new FakeLock($this->id(), ILock::TYPE_USER, 'pedro'));
        $out = $this->json('files_move_batch', ['moves' => [['from' => '/Documentos/ata.md', 'to' => '/Arquivo/ata.md']]]);
        $this->assertStringContainsString('is locked, and Nextcloud does not say by whom.', $out['failed']['reason']);
    }

    public function testACopyRefusedForALockedSourceIsExplained(): void {
        $this->tree->throwOnCopy[self::FILE] = FakeLockManager::refusal(new FakeLock($this->id(), ILock::TYPE_APP, 'richdocuments'));
        $e = $this->refused('files_copy', ['from' => '/Documentos/ata.md', 'to' => '/Documentos/ata-copia.md']);
        $this->assertStringContainsString('is locked, and Nextcloud does not say by whom.', $e->getMessage());
    }

    public function testAnUndoOfALockedItemIsAConflictWithTheExplanation(): void {
        $this->tree->addFolder('/alice/files/Arquivo');
        $id = $this->json('files_move_batch', ['moves' => [['from' => '/Documentos/ata.md', 'to' => '/Arquivo/ata.md']]])['batch_id'];
        $this->locks->put(new FakeLock($this->id('/alice/files/Arquivo/ata.md'), ILock::TYPE_APP, 'text'));
        $plan = $this->plan('files_undo_batch', ['batch_id' => $id]);
        $this->assertFalse($plan['ok']);
        $this->assertStringContainsString('is locked by Text', $plan['conflicts'][0]['reason']);
        $out = $this->json('files_undo_batch', ['batch_id' => $id]);
        $this->assertSame(0, $out['undone']);
        $this->assertArrayHasKey('/alice/files/Arquivo/ata.md', $this->tree->nodes);
    }

    public function testANewFileLockedBeforeItsContentArrivesSaysItIsLocked(): void {
        $this->tree->beforeCreate = function (string $path): void {
            $this->tree->writeFailure = new \OCP\Lock\ManuallyLockedException($path, null, 'files_lock/x', 'text', -1);
        };
        $e = $this->refused('files_create', ['path' => '/Documentos/novo.md', 'content' => 'texto']);
        $this->assertStringContainsString('locked', $e->getMessage());
    }

    /** The backup folder itself can be locked: that is a lock refusal too, and nothing reaches the file. */
    public function testALockedBackupFolderRefusesTheEditAsALock(): void {
        $this->tree->backupWriteFailure = new \OCP\Lock\ManuallyLockedException('/alice/files/MCP backups/x', null, 'files_lock/b', 'pedro', -1);
        $e = $this->refused('files_edit', ['path' => '/Documentos/ata.md', 'content' => 'novo']);
        $this->assertStringContainsString('is locked, and Nextcloud does not say by whom.', $e->getMessage());
        $this->assertStringEndsWith(FilesMessages::backupFailed(), $e->getMessage());
        $this->assertSame("# Ata\nolá", $this->tree->nodes[self::FILE]['content']);
    }

    /** Moving a folder moves what is inside: a file open in an editor in there stops the move and its plan says why. */
    public function testAFolderWithALockedFileInsideIsNotMoved(): void {
        $this->lockBy(ILock::TYPE_APP, 'text');
        $plan = $this->plan('files_move', ['from' => '/Documentos', 'to' => '/Arquivo']);
        $this->assertTrue($plan['lock']['blocking']);
        $this->assertStringStartsWith('The file “/Documentos/ata.md” is locked by Text', $plan['warnings'][0]['message']);
        $e = $this->refused('files_move', ['from' => '/Documentos', 'to' => '/Arquivo']);
        $this->assertStringStartsWith('The file “/Documentos/ata.md” is locked by Text', $e->getMessage());
        $this->assertUntouched();
        $this->assertArrayHasKey(self::FILE, $this->tree->nodes);
    }

    public function testABatchMovingAFolderWithALockedFileInsideIsRefusedWhole(): void {
        $this->lockBy(ILock::TYPE_USER, 'pedro');
        $arguments = ['moves' => [['from' => '/Documentos', 'to' => '/Arquivo']]];
        $plan = $this->plan('files_move_batch', $arguments);
        $this->assertStringContainsString('“/Documentos/ata.md” is locked by Pedro Almeida', $plan['denied'][0]['reason']);
        $this->assertSame(FilesMessages::batchNotOk(0, 1), $this->failure('files_move_batch', $arguments));
        $this->assertSame([], $this->tree->ops);
    }

    public function testAnUndoOfAFolderWithALockedFileInsideIsAConflict(): void {
        $id = $this->json('files_move_batch', ['moves' => [['from' => '/Documentos', 'to' => '/Arquivo']]])['batch_id'];
        $this->locks->put(new FakeLock($this->id('/alice/files/Arquivo/ata.md'), ILock::TYPE_APP, 'text'));
        $out = $this->json('files_undo_batch', ['batch_id' => $id]);
        $this->assertSame(0, $out['undone']);
        $this->assertStringContainsString('“/Arquivo/ata.md” is locked by Text', $out['conflicts'][0]['reason']);
    }

    /**
     * files_lock never refuses a WebDAV token lock outside DAV, so the storage would let this write through: only the
     * check repeated right before each item of the batch stops it, after the first item already moved.
     */
    public function testATokenLockTakenDuringABatchStopsTheNextItem(): void {
        $this->tree->addFile('/alice/files/Documentos/plano.md', 'plano', 'text/markdown');
        $this->tree->addFolder('/alice/files/Arquivo');
        $this->tree->afterMove = function (): void {
            $this->tree->afterMove = null;
            $this->lockBy(ILock::TYPE_TOKEN, 'pedro', '/alice/files/Documentos/plano.md');
        };
        $out = $this->json('files_move_batch', ['moves' => [
            ['from' => '/Documentos/ata.md', 'to' => '/Arquivo/ata.md'],
            ['from' => '/Documentos/plano.md', 'to' => '/Arquivo/plano.md'],
        ]]);
        $this->assertCount(1, $out['moved']);
        $this->assertSame('/Documentos/plano.md', $out['failed']['from']);
        $this->assertStringContainsString('It was locked through a WebDAV client', $out['failed']['reason']);
        $this->assertArrayHasKey('/alice/files/Documentos/plano.md', $this->tree->nodes);
    }

    /** A checkout is planned as its upload will run: without session, so the own manual lock is a refusal there. */
    public function testTheCheckoutPlanWarnsThatTheLinkCannotWriteOverTheOwnLock(): void {
        $this->lockBy(ILock::TYPE_USER, 'alice');
        $plan = $this->plan('files_checkout', ['path' => '/Documentos/ata.md']);
        $this->assertTrue($plan['lock']['blocking']);
        $this->assertStringContainsString('change it with files_edit', $plan['warnings'][0]['message']);
        $e = $this->refused('files_checkout', ['path' => '/Documentos/ata.md']);
        $this->assertStringContainsString('You locked this file yourself.', $e->getMessage());
        $this->assertSame([], $this->issued);
    }

    /**
     * A WebDAV client locks the new file between its creation and its content. The storage would not refuse a token
     * lock, so the check right before the content is what keeps it from being written over.
     */
    public function testANewFileLockedByATokenBeforeItsContentIsNotWritten(): void {
        $this->tree->afterCreate = function (string $path): void {
            $this->lockBy(ILock::TYPE_TOKEN, 'pedro', $path);
        };
        $e = $this->refused('files_create', ['path' => '/Documentos/novo.md', 'content' => 'texto']);
        $this->assertStringContainsString('It was locked through a WebDAV client', $e->getMessage());
        $this->assertSame('', $this->tree->nodes['/alice/files/Documentos/novo.md']['content']);
    }

    /** The same for the backup copy: a token lock on it is refused before the original is copied into it. */
    public function testABackupCopyLockedByATokenRefusesTheEdit(): void {
        $this->tree->afterCreate = function (string $path): void {
            if (str_contains($path, '/MCP backups/')) {
                $this->lockBy(ILock::TYPE_TOKEN, 'pedro', $path);
            }
        };
        $e = $this->refused('files_edit', ['path' => '/Documentos/ata.md', 'content' => 'novo']);
        $this->assertStringContainsString('It was locked through a WebDAV client', $e->getMessage());
        $this->assertStringEndsWith(FilesMessages::backupFailed(), $e->getMessage());
        $this->assertSame("# Ata\nolá", $this->tree->nodes[self::FILE]['content']);
        $this->assertNotContains('write /alice/files/Documentos/ata.md', $this->tree->ops);
    }

    /**
     * What a plan promises about the content it replaces is the backup in /MCP backups. A version depends on the
     * versioning policy of the backend, so no plan, tool description or guide note promises one unconditionally.
     */
    public function testNoPlanPromisesAVersionUnconditionally(): void {
        $plan = $this->plan('files_edit', ['path' => '/Documentos/ata.md', 'content' => 'novo']);
        foreach ([$plan['consequence'], FilesMessages::planRestore(), FilesMessages::versionRestoreTool(),
            implode(' ', $this->module->guideNotes())] as $text) {
            $this->assertDoesNotMatchRegularExpression('/new version is created|keeps the new content as a version|a new version in Nextcloud|restores content as a new version/', $text);
        }
        $this->assertStringContainsString('backup folder', $plan['consequence']);
        $this->assertStringContainsString('backup folder', FilesMessages::planRestore());
    }

    public function testWithoutFilesLockEverythingWorksAsBefore(): void {
        $this->locks->available = false;
        $this->json('files_edit', ['path' => '/Documentos/ata.md', 'content' => 'novo']);
        $this->assertSame('novo', $this->tree->nodes[self::FILE]['content']);
        $this->assertArrayNotHasKey('lock', $this->plan('files_edit', ['path' => '/Documentos/ata.md', 'content' => 'outro']));
    }
}
