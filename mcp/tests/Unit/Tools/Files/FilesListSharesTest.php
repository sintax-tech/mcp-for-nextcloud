<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\Files\FilesMessages;
use OCA\Mcp\Tools\Files\Sharing\ShareAccess;
use OCP\Share\IShare;

/** files_list_shares as the model calls it: by path, or every share of the user page by page. */
final class FilesListSharesTest extends FilesToolsTestCase {
    private const PASSWORD = 'N3ver-Sh0wn#pass';

    private function id(string $path): int {
        return $this->tree->nodes['/alice/files' . $path]['id'];
    }

    public function testTheToolIsAReadOfFilesWithOptionalPathAndBoundedOffset(): void {
        $definition = array_column($this->module->definitions(), null, 'name')['files_list_shares'];
        self::assertSame(['files', 'read'], [$definition['module'], $definition['operation']]);
        self::assertSame([], $definition['inputSchema']['required'] ?? []);
        self::assertSame(['path', 'offset'], array_keys($definition['inputSchema']['properties']));
        self::assertSame(0, $definition['inputSchema']['properties']['offset']['minimum']);
        self::assertSame(ShareAccess::MAX_OFFSET, $definition['inputSchema']['properties']['offset']['maximum']);
        self::assertStringContainsString('never', $definition['description']);
    }

    public function testAnOffsetAboveTheCeilingIsAValidationErrorOnTheOffsetField(): void {
        try {
            $this->validated('files_list_shares', ['offset' => ShareAccess::MAX_OFFSET + 1]);
            self::fail('no exception');
        } catch (ArgumentValidationException $e) {
            self::assertSame('offset', $e->details()['field']);
        }
    }

    public function testByPathListsTheSharesOfThatNode(): void {
        $node = $this->id('/Documentos/ata.md');
        $this->shares->add(['node' => $node, 'with' => 'alice-colleague', 'permissions' => 3]);
        $this->shares->add(['node' => $node, 'type' => IShare::TYPE_LINK, 'with' => null, 'token' => 'TOKEN1', 'password' => self::PASSWORD]);
        $this->shares->add(['node' => $node, 'type' => IShare::TYPE_ROOM, 'with' => 'room1', 'withName' => 'Diretoria']);
        $this->shares->add(['node' => $this->id('/Documentos'), 'with' => 'other']);

        $out = $this->json('files_list_shares', ['path' => 'Documentos/ata.md']);

        self::assertSame('/Documentos/ata.md', $out['path']);
        self::assertSame(['user', 'link', 'room'], array_column($out['shares'], 'type'));
        self::assertSame(['edit', 'view', 'view'], array_column($out['shares'], 'permission'));
        self::assertSame([true, true, false], array_column($out['shares'], 'removable'), 'anexo do Talk é somente leitura');
        self::assertSame(['/Documentos/ata.md'], array_values(array_unique(array_column($out['shares'], 'path'))));
        self::assertTrue($out['shares'][1]['hasPassword']);
        self::assertSame('https://cloud.test/apps/mcp/TOKEN1', $out['shares'][1]['url']);
    }

    public function testANodeOfAnotherOwnerIsRefused(): void {
        $this->tree->addFile('/alice/files/Recebido.pdf', 'x', 'application/pdf', ['scope' => 'shared']);
        self::assertSame(FilesMessages::shareNotOwner(), $this->failure('files_list_shares', ['path' => '/Recebido.pdf']));
    }

    public function testAMissingPathIsNotFound(): void {
        self::assertSame(CommonMessages::notFound(), $this->failure('files_list_shares', ['path' => '/nada.md']));
    }

    public function testWithoutPathEveryShareIsListedPageByPage(): void {
        $node = $this->id('/Documentos/ata.md');
        for ($i = 0; $i < 52; $i++) {
            $this->shares->add(['node' => $node, 'with' => 'u' . $i]);
        }
        $this->shares->add(['node' => $this->id('/Documentos'), 'nodeType' => 'folder', 'type' => IShare::TYPE_GROUP, 'with' => 'finance', 'permissions' => 15]);

        $first = $this->json('files_list_shares');
        self::assertCount(ShareAccess::PAGE_SIZE, $first['shares']);
        self::assertSame(0, $first['offset']);
        self::assertTrue($first['hasMore']);
        self::assertSame(ShareAccess::PAGE_SIZE, $first['nextOffset']);

        $second = $this->json('files_list_shares', ['offset' => $first['nextOffset']]);
        self::assertSame(['user', 'user', 'group'], array_column($second['shares'], 'type'));
        self::assertSame('/Documentos', $second['shares'][2]['path']);
        self::assertSame('edit', $second['shares'][2]['permission']);
        self::assertFalse($second['hasMore']);
        self::assertNull($second['nextOffset']);
    }

    /** Invariant 2: the password is in no part of the answer, with or without path. */
    public function testThePasswordIsNeverInTheResult(): void {
        $this->shares->add(['node' => $this->id('/Documentos/ata.md'), 'type' => IShare::TYPE_LINK, 'with' => null, 'token' => 'T', 'password' => self::PASSWORD]);
        foreach ([[], ['path' => '/Documentos/ata.md']] as $arguments) {
            $text = $this->tool('files_list_shares', $arguments)['content'][0]['text'];
            self::assertStringNotContainsString(self::PASSWORD, $text);
            self::assertTrue(json_decode($text, true)['shares'][0]['hasPassword']);
        }
    }

    /** Invariant 7: only the user's own shares are asked for, never as reshares of somebody else. */
    public function testOnlySharesTheUserInitiatedAreRead(): void {
        $this->shares->add(['node' => $this->id('/Documentos/ata.md'), 'by' => 'bruno']);
        self::assertSame([], $this->json('files_list_shares')['shares']);
        self::assertSame([], $this->json('files_list_shares', ['path' => '/Documentos/ata.md'])['shares']);
        foreach ($this->shares->calls as $call) {
            self::assertSame('alice', $call['uid']);
            self::assertFalse($call['reshares']);
        }
    }
}
