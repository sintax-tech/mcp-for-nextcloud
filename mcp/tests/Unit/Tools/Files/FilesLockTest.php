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
        $this->assertStringStartsWith('The file “/Documentos/ata.md” is open in Nextcloud Office', $e->getMessage());
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
        $this->assertStringContainsString('is open in Text', $e->getMessage());
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
            $this->assertStringContainsString('is open in Text', $plan['warnings'][0]['message'], $tool);
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
        $this->assertStringContainsString('is open in Text', $e->getMessage());
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
        $this->assertStringContainsString('is open in Text', $plan['denied'][0]['reason']);
        $this->assertSame(FilesMessages::batchNotOk(0, 1), $this->failure('files_move_batch', $arguments));
        $this->assertSame([], $this->tree->ops, 'no folder created, nothing moved');
    }

    public function testABatchItemLockedDuringTheRunIsReportedWithTheExplanation(): void {
        $this->tree->addFolder('/alice/files/Arquivo');
        $this->tree->throwOnMove[self::FILE] = FakeLockManager::refusal(new FakeLock($this->id(), ILock::TYPE_USER, 'pedro'));
        $out = $this->json('files_move_batch', ['moves' => [['from' => '/Documentos/ata.md', 'to' => '/Arquivo/ata.md']]]);
        $this->assertStringContainsString('is locked by Pedro Almeida.', $out['failed']['reason']);
    }

    public function testACopyRefusedForALockedSourceIsExplained(): void {
        $this->tree->throwOnCopy[self::FILE] = FakeLockManager::refusal(new FakeLock($this->id(), ILock::TYPE_APP, 'richdocuments'));
        $e = $this->refused('files_copy', ['from' => '/Documentos/ata.md', 'to' => '/Documentos/ata-copia.md']);
        $this->assertStringContainsString('is open in Nextcloud Office', $e->getMessage());
    }

    public function testAnUndoOfALockedItemIsAConflictWithTheExplanation(): void {
        $this->tree->addFolder('/alice/files/Arquivo');
        $id = $this->json('files_move_batch', ['moves' => [['from' => '/Documentos/ata.md', 'to' => '/Arquivo/ata.md']]])['batch_id'];
        $this->locks->put(new FakeLock($this->id('/alice/files/Arquivo/ata.md'), ILock::TYPE_APP, 'text'));
        $plan = $this->plan('files_undo_batch', ['batch_id' => $id]);
        $this->assertFalse($plan['ok']);
        $this->assertStringContainsString('is open in Text', $plan['conflicts'][0]['reason']);
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

    public function testWithoutFilesLockEverythingWorksAsBefore(): void {
        $this->locks->available = false;
        $this->json('files_edit', ['path' => '/Documentos/ata.md', 'content' => 'novo']);
        $this->assertSame('novo', $this->tree->nodes[self::FILE]['content']);
        $this->assertArrayNotHasKey('lock', $this->plan('files_edit', ['path' => '/Documentos/ata.md', 'content' => 'outro']));
    }
}
