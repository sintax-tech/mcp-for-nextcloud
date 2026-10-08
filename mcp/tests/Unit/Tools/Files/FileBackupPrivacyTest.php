<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Service\VisibilityGuard;
use OCA\Mcp\Tools\Files\FileBackup;
use OCA\Mcp\Tools\Files\FilesMessages;
use OCA\Mcp\Tools\ToolFailure;
use OCP\SystemTag\ISystemTagObjectMapper;

/** Verifies that backup privacy is established before any sensitive bytes are copied. */
final class FileBackupPrivacyTest extends FilesToolsTestCase {
    /** @dataProvider tagFailures */
    public function testTagFailureLeavesAnEmptyBackupAndOriginalUntouched(string $failure): void {
        $backup = $this->backup($failure);
        try {
            $backup->prepare($this->tree->rootFolder(), $this->tree->node('/alice/files/Documentos/ata.md'),
                '/Documentos/ata.md', 'alice', null);
            $this->fail('An unprotected backup must abort the edit');
        } catch (ToolFailure $e) {
            $this->assertSame(FilesMessages::backupFailed(), $e->getMessage());
        }
        $copies = array_filter($this->tree->nodes, fn (array $node, string $path): bool =>
            str_ends_with($path, '.bak'), ARRAY_FILTER_USE_BOTH);
        $this->assertCount(1, $copies);
        $this->assertSame('', array_values($copies)[0]['content']);
        $this->assertSame("# Ata\nolá", $this->tree->nodes['/alice/files/Documentos/ata.md']['content']);
        $this->assertCount(3, $this->tree->ops, 'Only folders and the empty backup may be created');
    }

    /** @return iterable<string, array{0:string}> */
    public static function tagFailures(): iterable {
        yield 'assignment' => ['assign'];
        yield 'lookup' => ['lookup'];
    }

    public function testTagsAreAssignedWhileBackupIsEmpty(): void {
        $backup = $this->backup(null);
        $path = $backup->prepare($this->tree->rootFolder(), $this->tree->node('/alice/files/Documentos/ata.md'),
            '/Documentos/ata.md', 'alice', null);
        $this->assertSame("# Ata\nolá", $this->tree->nodes['/alice/files' . $path]['content']);
    }

    private function backup(?string $failure): FileBackup {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $mapper = $this->createMock(ISystemTagObjectMapper::class);
        $mapper->method('getTagIdsForObjects')->willReturnCallback(function (array $ids) use ($failure): array {
            if ($failure === 'lookup') {
                throw new \RuntimeException('Unavailable');
            }
            return array_fill_keys($ids, ['42']);
        });
        $mapper->expects($failure === 'lookup' ? $this->never() : $this->once())->method('assignTags')
            ->willReturnCallback(function (string $id, string $type, array $tags) use ($failure): void {
                $this->assertSame('files', $type);
                $this->assertSame(['42'], $tags);
                foreach ($this->tree->nodes as $node) {
                    if ((string)$node['id'] === $id) {
                        $this->assertSame('', $node['content']);
                    }
                }
                if ($failure === 'assign') {
                    throw new \RuntimeException('Unavailable');
                }
            });
        $guard = new VisibilityGuard($this->config->mock($this), $mapper);
        return new FileBackup($this->apps, $this->users, $this->time, $this->config->mock($this), $guard);
    }
}
