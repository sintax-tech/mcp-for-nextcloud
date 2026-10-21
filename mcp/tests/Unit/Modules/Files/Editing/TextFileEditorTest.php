<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files\Editing;

use OCA\Mcp\Mcp\Confirmation\ConfirmationMessages;
use OCA\Mcp\Platform\FileAccess\FileAccessMessages;
use OCA\Mcp\Platform\FileAccess\Sharing\SharingMessages;

use OCA\Mcp\Modules\Files\Editing\EditRequest;
use OCA\Mcp\Modules\Files\Editing\EditingMessages;
use OCA\Mcp\Platform\FileAccess\BackupMessages;
use OCA\Mcp\Tests\Support\Files\FilesEditFixture;
use OCA\Mcp\Tests\Support\Platform\FileAccess\Locks\FakeLock;
use OCA\Mcp\Platform\FileAccess\Locks\LockWriteFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Lock\ILock;

final class TextFileEditorTest extends FilesEditFixture {
    private function request(?string $etag = null): EditRequest {
        return new EditRequest('/Notes/readme.md', 'New', $etag, false);
    }

    private function failure(callable $call): ToolFailure {
        try {
            $call();
        } catch (ToolFailure $e) {
            return $e;
        }
        self::fail('Expected a safe refusal');
    }

    private function lock(string $owner): void {
        $this->locks->put(new FakeLock($this->tree->nodes[self::FILE]['id'], ILock::TYPE_USER, $owner));
    }

    public function testPreviewChecksEtagBeforeLockButExecutionRefusesLockFirst(): void {
        $this->lock('pedro');
        $preview = $this->failure(fn () => $this->editor->preview($this->request('stale'), 'alice'));
        self::assertNotInstanceOf(LockWriteFailure::class, $preview);
        self::assertSame(FileAccessMessages::conflict(), $preview->getMessage());
        $execution = $this->failure(fn () => $this->editor->execute($this->request('stale'), 'alice'));
        self::assertInstanceOf(LockWriteFailure::class, $execution);
        self::assertSame([], $this->tree->ops);
        $plan = $this->editor->preview($this->request(), 'alice');
        self::assertTrue($plan['lock']['blocking']);
        self::assertNotEmpty($plan['warnings']);
    }

    public function testSharedConfirmationPrecedesStaleEtagWithoutWriting(): void {
        $this->tree->nodes[self::FILE]['scope'] = 'shared';
        $result = $this->editor->execute($this->request('stale'), 'alice');
        self::assertSame(['content'], array_keys($result));
        self::assertSame(['type' => 'text'], array_diff_key($result['content'][0], ['text' => true]));
        self::assertSame([
            'requiresConfirmation' => true, 'scope' => 'shared', 'owner' => 'pedro',
            'ownerDisplayName' => 'Paulo Exemplo', 'sharedBy' => 'Paulo Exemplo', 'resource' => '/Notes/readme.md',
            'message' => SharingMessages::sharedBy('Paulo Exemplo') . ' ' . ConfirmationMessages::confirmAdvice(),
        ], json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame([], $this->tree->ops);
    }

    public function testVersioningIsAnExecutionCheckWhileUpdatePermissionAlsoAppliesToPreview(): void {
        $this->enabled = [];
        self::assertTrue($this->editor->preview($this->request(), 'alice')['recoverable']);
        self::assertSame(EditingMessages::versioningOff(), $this->failure(fn () => $this->editor->execute($this->request(), 'alice'))->getMessage());
        $this->tree->nodes[self::FILE]['updateable'] = false;
        $this->tree->nodes[self::FILE]['permissions'] = \OCP\Constants::PERMISSION_READ;
        self::assertSame(FileAccessMessages::forbidden(), $this->failure(fn () => $this->editor->preview($this->request(), 'alice'))->getMessage());
        self::assertSame([], $this->tree->ops);
    }

    public function testFailedBackupNeverWritesOriginal(): void {
        $this->tree->shortBackupWrite = true;
        self::assertSame(BackupMessages::failed(), $this->failure(fn () => $this->editor->execute($this->request(), 'alice'))->getMessage());
        self::assertNotContains('write ' . self::FILE, $this->tree->ops);
        self::assertSame("# Notes\né", $this->tree->nodes[self::FILE]['content']);
    }

    public function testLockAppearingAfterBackupKeepsRecoveryInformation(): void {
        $this->tree->afterWrite = function (string $path): void {
            if (str_contains($path, '/MCP backups/')) {
                $this->lock('pedro');
            }
        };
        $failure = $this->failure(fn () => $this->editor->execute($this->request(), 'alice'));
        self::assertInstanceOf(LockWriteFailure::class, $failure);
        self::assertStringContainsString('/MCP backups/Notes/readme.md.', $failure->getMessage());
        self::assertNotContains('write ' . self::FILE, $this->tree->ops);
    }

    public function testOwnManualLockAllowsWriteWithoutTakingOverLock(): void {
        $this->lock('alice');
        $this->editor->execute($this->request(), 'alice');
        self::assertSame('New', $this->tree->nodes[self::FILE]['content']);
        self::assertSame([], $this->locks->forbidden);
    }

    public function testAccessRevokedAfterPreviewPreventsBackup(): void {
        $this->editor->preview($this->request(), 'alice');
        $this->tree->nodes[self::FILE]['permissions'] = \OCP\Constants::PERMISSION_READ;
        self::assertSame(FileAccessMessages::forbidden(), $this->failure(fn () => $this->editor->execute($this->request(), 'alice'))->getMessage());
        self::assertSame([], $this->tree->ops);
    }

    public function testBackupInheritsTagsAddedDuringPreparationBeforeSensitiveBytesArrive(): void {
        $this->visibility->setHiddenTagIds([9]);
        $this->tree->beforeCreate = function (): void {
            $this->tags[(string)$this->tree->nodes[self::FILE]['id']] = ['9'];
        };
        $checked = false;
        $this->tree->afterWrite = function (string $path) use (&$checked): void {
            if (str_contains($path, '/MCP backups/')) {
                self::assertSame(['9'], $this->tags[(string)$this->tree->nodes[$path]['id']]);
                self::assertSame("# Notes\né", $this->tree->nodes[$path]['content']);
                $checked = true;
            }
        };
        $this->failure(fn () => $this->editor->execute($this->request(), 'alice'));
        self::assertTrue($checked);
        self::assertNotContains('write ' . self::FILE, $this->tree->ops);
    }

    public function testVisibilityIsCheckedAgainAfterPreview(): void {
        $this->editor->preview($this->request(), 'alice');
        $this->visibility->setHiddenTagIds([9]);
        $this->tags[(string)$this->tree->nodes['/alice/files/Notes']['id']] = ['9'];
        $this->failure(fn () => $this->editor->execute($this->request(), 'alice'));
        self::assertSame([], $this->tree->ops);
    }
}
