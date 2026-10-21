<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract;

use OCA\Mcp\Tests\Support\Files\FilesToolsTestCase;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Mcp\Tools\Validation\UnknownToolException;
use Psr\Log\LoggerInterface;

/** Public contract pinned before the read/edit extraction. */
final class FilesPilotCompatibilityTest extends FilesToolsTestCase {
    private function registry(bool $allowed = true): ToolRegistry {
        $this->sharePolicy->setGrant('alice', 'files', 'read', $allowed);
        $this->sharePolicy->setGrant('alice', 'files', 'edit', $allowed);
        $availability = new \OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability($this->sharePolicy, $this->apps, $this->users);
        return new ToolRegistry([$this->module], $availability, new \OCA\Mcp\Mcp\Tools\Execution\ToolExecutor($availability, $this->createMock(LoggerInterface::class)));
    }

    public function testDefinitionsAndOrderMatchPreExtractionContract(): void {
        $expected = json_decode(file_get_contents(__DIR__ . '/../../fixtures/files-pilot-definitions.json'), true, 512, JSON_THROW_ON_ERROR);
        $definitions = $this->module->definitions();
        $this->assertSame($expected['names'], array_column($definitions, 'name'));
        $pilot = array_values(array_filter($definitions, fn (array $d): bool => in_array($d['name'], ['files_read', 'files_edit'], true)));
        $this->assertSame($expected['definitions'], $pilot);
    }

    public function testReadReturnsTextFollowedByJsonMetadata(): void {
        $result = $this->registry()->call('files_read', ['path' => '/Documentos/ata.md'], 'alice');
        $this->assertSame(['content'], array_keys($result));
        $this->assertCount(2, $result['content']);
        $this->assertSame(['type' => 'text', 'text' => "# Ata\nolá"], $result['content'][0]);
        $metadata = json_decode($result['content'][1]['text'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['path', 'etag', 'size', 'mime', 'access'], array_keys($metadata));
        $this->assertSame('/Documentos/ata.md', $metadata['path']);
        $this->assertSame('text/markdown', $metadata['mime']);
        $this->assertSame('alice', $metadata['access']['owner']);
        $this->assertSame([], $this->tree->ops);
    }

    public function testOnlyExplicitGlobalConfirmationWritesAndNoPlanStateIsRequired(): void {
        $registry = $this->registry();
        $args = ['path' => '/Documentos/ata.md', 'content' => 'New content'];
        foreach ([[], ['confirm' => false], ['confirm_shared' => true]] as $flags) {
            $result = $registry->call('files_edit', $args + $flags, 'alice');
            $this->assertTrue($result['structuredContent']['requiresConfirmation']);
            $this->assertSame('files_edit', $result['structuredContent']['action']);
            $this->assertSame([], $this->tree->ops);
            $this->assertSame([], $this->issued);
        }
        $result = $registry->call('files_edit', $args + ['confirm' => true], 'alice');
        $written = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['path', 'size', 'etag', 'access', 'backup', 'diff'], array_keys($written));
        $this->assertSame('New content', $this->tree->nodes['/alice/files/Documentos/ata.md']['content']);
        $this->assertSame("# Ata\nolá", $this->tree->nodes['/alice/files' . $written['backup']]['content']);
    }

    public function testDeniedToolsDoNotReachFileAccess(): void {
        $registry = $this->registry(false);
        foreach (['files_read', 'files_edit'] as $name) {
            try {
                $registry->call($name, ['path' => '/missing', 'content' => 'Denied', 'confirm' => true], 'alice');
                $this->fail('Denied tool was executed');
            } catch (UnknownToolException) {
                $this->assertSame([], $this->tree->ops);
            }
        }
    }

    public function testUnexpectedReadFailureIsSanitized(): void {
        $this->tree->beforeGet = static function (): void {
            throw new \RuntimeException('Private storage path /secret/customer');
        };
        $result = $this->registry()->call('files_read', ['path' => '/Documentos/ata.md'], 'alice');
        self::assertSame([
            'content' => [['type' => 'text', 'text' => 'Unexpected error while accessing Nextcloud.']],
            'isError' => true,
        ], $result);
    }

    public function testGlobalAndSharedConfirmationAreIndependent(): void {
        $this->tree->nodes['/alice/files/Documentos/ata.md']['scope'] = 'shared';
        $registry = $this->registry();
        $args = ['path' => '/Documentos/ata.md', 'content' => 'New', 'confirm' => true];
        $refusal = $registry->call('files_edit', $args, 'alice');
        self::assertTrue(json_decode($refusal['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR)['requiresConfirmation']);
        self::assertArrayNotHasKey('isError', $refusal);
        self::assertSame([], $this->tree->ops);
        $registry->call('files_edit', $args + ['confirm_shared' => true], 'alice');
        self::assertSame('New', $this->tree->nodes['/alice/files/Documentos/ata.md']['content']);
    }

    public function testUnknownArgumentsAreRejectedAtPublicBoundary(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry()->call('files_read', ['path' => '/Documentos/ata.md', 'userId' => 'someone-else'], 'alice');
    }
}
