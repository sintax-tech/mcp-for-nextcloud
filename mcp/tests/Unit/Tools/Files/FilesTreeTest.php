<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Tools\Files\FilesMessages;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\ToolFailure;

/**
 * files_tree: one bounded walk so an agent can plan a reorganization without a call per directory.
 * The shape, the bounds and what is left out are the contract; the order is deterministic.
 */
final class FilesTreeTest extends FilesToolsTestCase {
    /** @return array{path:string, entries:list<array<string, mixed>>, count:int, truncated:bool} */
    private function tree(array $arguments = []): array {
        return $this->json('files_tree', $arguments);
    }

    private function seed(): void {
        $this->tree->addFile('/alice/files/Documentos/2026/janeiro.md', 'janeiro', 'text/markdown');
        $this->tree->addFile('/alice/files/Documentos/2026/fevereiro.md', 'fevereiro', 'text/markdown');
        $this->tree->addFolder('/alice/files/Documentos/2026/arquivo');
        $this->tree->addFile('/alice/files/Documentos/2026/arquivo/antigo.md', 'antigo', 'text/markdown');
        $this->tree->addFile('/alice/files/Documentos/raiz.md', 'raiz', 'text/markdown');
    }

    public function testTheToolIsDeclaredForReadingFilesWithItsBounds(): void {
        $definition = array_column($this->module->definitions(), null, 'name')['files_tree'];
        $this->assertSame('read', $definition['operation']);
        $this->assertSame('files', $definition['module']);
        $this->assertArrayNotHasKey('app', $definition, 'a leitura de arquivos não depende de app opcional');
        $this->assertSame('/', $definition['inputSchema']['properties']['path']['default']);
        $this->assertSame(['minimum' => 1, 'maximum' => 5, 'default' => 5], array_intersect_key(
            $definition['inputSchema']['properties']['depth'], ['minimum' => 0, 'maximum' => 0, 'default' => 0]));
        $this->assertSame(['minimum' => 1, 'maximum' => 2000, 'default' => 2000], array_intersect_key(
            $definition['inputSchema']['properties']['limit'], ['minimum' => 0, 'maximum' => 0, 'default' => 0]));
        $this->assertFalse($definition['inputSchema']['additionalProperties']);
    }

    public function testItWalksNestedFoldersAndDescribesEveryNode(): void {
        $this->seed();
        $out = $this->tree(['path' => '/Documentos']);
        $this->assertSame(['path', 'entries', 'count', 'truncated'], array_keys($out));
        $this->assertSame('/Documentos', $out['path']);
        $this->assertFalse($out['truncated']);
        // Level by level: the whole first level, then the whole second, then the third. A tree an agent
        // reads top-down needs that order, and it does not depend on how the storage returned anything.
        $this->assertSame([
            '/Documentos/2026',
            '/Documentos/ata.md',
            '/Documentos/raiz.md',
            '/Documentos/2026/arquivo',
            '/Documentos/2026/fevereiro.md',
            '/Documentos/2026/janeiro.md',
            '/Documentos/2026/arquivo/antigo.md',
        ], array_column($out['entries'], 'path'));
        $this->assertSame($out['count'], count($out['entries']));
        $folder = $out['entries'][0];
        $this->assertSame('2026', $folder['name']);
        $this->assertTrue($folder['isDir']);
        $this->assertSame(0, $folder['size']);
        $this->assertSame('httpd/unix-directory', $folder['mime']);
        $file = $out['entries'][6];
        $this->assertSame('antigo.md', $file['name']);
        $this->assertFalse($file['isDir']);
        $this->assertSame(6, $file['size']);
        $this->assertSame('text/markdown', $file['mime']);
        $this->assertMatchesRegularExpression('/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT$/', $file['mtime']);
    }

    /** Every entry carries the whole access object, the same one the other Files tools return. */
    public function testEveryEntryCarriesTheWholeAccessObject(): void {
        $this->seed();
        $this->tree->mountPath = '/alice/files/Equipe';
        $this->tree->addFile('/alice/files/Documentos/2026/janeiro.md', 'x', 'text/markdown', ['scope' => 'team']);
        $access = [];

        foreach ($this->tree(['path' => '/Documentos'])['entries'] as $entry) {
            $access[$entry['path']] = $entry['access'];
        }
        $this->assertSame(['scope', 'owner', 'ownerDisplayName', 'permissions'], array_keys($access['/Documentos/2026']));
        $this->assertSame('personal', $access['/Documentos/2026']['scope']);
        $this->assertArrayHasKey('/Documentos/2026/janeiro.md', $access, json_encode(array_keys($access)));
        $this->assertSame([
            'scope' => 'team',
            'owner' => 'pedro',
            'ownerDisplayName' => 'Pedro Almeida',
            'permissions' => ['read' => true, 'update' => true, 'create' => true, 'delete' => true, 'share' => true],
            'teamFolder' => 'Equipe',
        ], $access['/Documentos/2026/janeiro.md']);
    }

    public function testDepthBoundsTheWalk(): void {
        $this->seed();
        $shallow = array_column($this->tree(['path' => '/Documentos', 'depth' => 1])['entries'], 'path');
        $this->assertSame(['/Documentos/2026', '/Documentos/ata.md', '/Documentos/raiz.md'], $shallow);
        $deep = array_column($this->tree(['path' => '/Documentos', 'depth' => 2])['entries'], 'path');
        $this->assertContains('/Documentos/2026/arquivo', $deep);
        $this->assertNotContains('/Documentos/2026/arquivo/antigo.md', $deep);
        $this->assertCount(6, $deep);
    }

    public function testLimitBoundsTheResultAndSaysItCut(): void {
        $this->seed();
        $out = $this->tree(['path' => '/Documentos', 'limit' => 3]);
        $this->assertSame(['/Documentos/2026', '/Documentos/ata.md', '/Documentos/raiz.md'], array_column($out['entries'], 'path'));
        $this->assertSame(3, $out['count']);
        $this->assertTrue($out['truncated']);
    }

    public function testALimitBigEnoughForEverythingIsNotMarkedTruncated(): void {
        $this->seed();
        $this->assertFalse($this->tree(['path' => '/Documentos', 'limit' => 8])['truncated']);
        $this->assertFalse($this->tree(['path' => '/Documentos', 'limit' => 2000])['truncated']);
    }

    /** A cutoff stops the walk; it must not walk the whole tree first and then throw most of it away. */
    public function testTheWalkStopsAtTheLimit(): void {
        for ($i = 0; $i < 40; $i++) {
            $this->tree->addFile("/alice/files/Muitos/arquivo-$i.txt", 'x', 'text/plain');
        }
        $out = $this->tree(['path' => '/Muitos', 'limit' => 5]);
        $this->assertCount(5, $out['entries']);
        $this->assertTrue($out['truncated']);
    }

    /** A folder the user cannot read is skipped, exactly as files_list does, and the walk continues. */
    public function testAnUnreadableFolderIsSkippedAndTheWalkContinues(): void {
        $this->seed();
        $this->tree->nodes['/alice/files/Documentos/2026/arquivo']['readable'] = false;
        $paths = array_column($this->tree(['path' => '/Documentos'])['entries'], 'path');
        $this->assertContains('/Documentos/2026', $paths);
        $this->assertContains('/Documentos/raiz.md', $paths);
        $this->assertNotContains('/Documentos/2026/arquivo', $paths);
    }

    /** The tree of a file is a mistake, not an empty answer. */
    public function testItRejectsAFileAndAnUnreadablePath(): void {
        $this->seed();
        $this->assertSame(FilesMessages::notAFolder(), $this->failure('files_tree', ['path' => '/Documentos/raiz.md']));
        try {
            $this->tree(['path' => '/nao-existe']);
            $this->fail('uma pasta que não existe não pode dar árvore');
        } catch (\OCA\Mcp\Tools\ToolFailure $e) {
            $this->assertSame(CommonMessages::notFound(), $e->getMessage());
        }
        $this->tree->nodes['/alice/files/Documentos/2026/arquivo']['readable'] = false;
        $this->assertSame(CommonMessages::forbidden(), $this->failure('files_tree', ['path' => '/Documentos/2026/arquivo']));
    }

    /** The empty folder is a valid answer, not a failure. */
    public function testAnEmptyFolderReturnsNothingWithoutFailing(): void {
        $this->tree->addFolder('/alice/files/Vazia');
        $out = $this->tree(['path' => '/Vazia']);
        $this->assertSame([], $out['entries']);
        $this->assertSame(0, $out['count']);
        $this->assertFalse($out['truncated']);
    }

    /** Traversal is refused by PathGuard before anything is resolved. */
    public function testTraversalIsRefused(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid argument: path');
        $this->tool('files_tree', ['path' => '/../etc']);
    }

    public function testHiddenFilesAndFoldersAreOmittedFromTreeAndHiddenFolderFailsNotFound(): void {
        $tagMapper = $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class);
        $this->config->app['mcp'][\OCA\Mcp\Service\VisibilityGuard::CONFIG_KEY] = json_encode(['999']);
        $this->visibilityGuard = new \OCA\Mcp\Service\VisibilityGuard($this->config->mock($this), $tagMapper);
        $this->setUp();

        $hiddenFileId = $this->tree->addFile('/alice/files/Documentos/secret.txt', 'secret');
        $hiddenFolderId = $this->tree->addFolder('/alice/files/Documentos/SecretFolder');
        $this->tree->addFile('/alice/files/Documentos/SecretFolder/nested.txt', 'nested');

        $tagMapper->method('getTagIdsForObjects')->willReturnCallback(function (array $ids) use ($hiddenFileId, $hiddenFolderId): array {
            $res = [];
            foreach ($ids as $id) {
                if ($id === (string)$hiddenFileId || $id === (string)$hiddenFolderId) {
                    $res[$id] = ['999'];
                } else {
                    $res[$id] = [];
                }
            }
            return $res;
        });

        $out = $this->tree(['path' => '/Documentos']);
        $paths = array_column($out['entries'], 'path');
        $this->assertNotContains('/Documentos/secret.txt', $paths);
        $this->assertNotContains('/Documentos/SecretFolder', $paths);
        $this->assertNotContains('/Documentos/SecretFolder/nested.txt', $paths);

        // Accessing the hidden folder directly fails with not found
        $this->assertSame(CommonMessages::notFound(), $this->failure('files_tree', ['path' => '/Documentos/SecretFolder']));
    }
}