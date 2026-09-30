<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools;

use InvalidArgumentException;
use OCA\Mcp\Tools\ArgumentValidator;
use OCA\Mcp\Tools\Files\FilesModule;
use OCA\Mcp\Tools\Files\TextExtractor;
use OCA\Mcp\Tools\ToolFailure;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use OCP\Files\Search\ISearchComparison;
use OCP\IDBConnection;
use OCP\ITempManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class FilesModuleTest extends TestCase {
    private FakeTree $tree;
    private array $apps = ['files_versions'];
    private FilesModule $module;

    protected function setUp(): void {
        $this->tree = new FakeTree($this);
        $this->tree->addFolder('/alice/files/Documentos');
        $this->tree->addFile('/alice/files/Documentos/ata.md', "# Ata\nolá", 'text/markdown');
        $this->tree->addFile('/alice/files/relatorio.pdf', (string)file_get_contents(__DIR__ . '/../../fixtures/sample.pdf'), 'application/pdf');

        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(fn (string $uid) => $uid === 'alice' ? $this->tree->rootFolder() : throw new \LogicException('other user'));
        $temp = $this->createMock(ITempManager::class);
        $temp->method('getTemporaryFile')->willReturnCallback(fn () => tempnam(sys_get_temp_dir(), 'mcp'));
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturnCallback(fn (string $app) => in_array($app, $this->apps, true));
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturn($this->createMock(IUser::class));
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1790000000);
        $db = $this->createMock(IDBConnection::class);
        $db->method('escapeLikeParameter')->willReturnCallback(fn (string $s) => addcslashes($s, '\\_%'));
        $this->module = new FilesModule($root, new TextExtractor($temp), $apps, $users, $time, $db);
    }

    /** Runs a tool the way the registry does: schema validation first, then the handler. */
    private function tool(string $name, array $arguments = []): array {
        foreach ($this->module->definitions() as $definition) {
            if ($definition['name'] === $name) {
                return $this->module->call($name, ArgumentValidator::validate($definition['inputSchema'], $arguments), 'alice');
            }
        }
        $this->fail("no tool $name");
    }

    private function json(string $name, array $arguments = []): array {
        return json_decode($this->tool($name, $arguments)['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }

    private function failure(string $name, array $arguments): string {
        try {
            $this->tool($name, $arguments);
        } catch (ToolFailure $e) {
            return $e->getMessage();
        }
        $this->fail("$name did not fail");
    }

    public function testDefinitionsKeepPrototypeNamesAndGrants(): void {
        $defs = array_column($this->module->definitions(), null, 'name');
        $this->assertSame(['files_list', 'files_search', 'files_read', 'files_edit'], array_keys($defs));
        $this->assertSame(['read', 'read', 'read', 'edit'], array_column($defs, 'operation'));
        $this->assertSame('/', $defs['files_list']['inputSchema']['properties']['path']['default']);
        $this->assertSame(['minimum' => 1, 'maximum' => 100, 'default' => 25], array_intersect_key($defs['files_search']['inputSchema']['properties']['limit'], ['minimum' => 0, 'maximum' => 0, 'default' => 0]));
        $this->assertSame(['path'], $defs['files_read']['inputSchema']['required']);
    }

    public function testListReturnsPrototypeEntryShape(): void {
        $entries = $this->json('files_list');
        $this->assertSame(['Documentos', 'relatorio.pdf'], array_column($entries, 'name'));
        $this->assertSame(['name' => 'Documentos', 'path' => '/Documentos', 'isDir' => true, 'size' => 0, 'mtime' => 'Tue, 30 Sep 2025 02:40:00 GMT', 'contentType' => 'httpd/unix-directory'], $entries[0]);
        $this->assertSame('/Documentos/ata.md', $this->json('files_list', ['path' => '/Documentos'])[0]['path']);
    }

    public function testListRejectsFilesTraversalAndUnreadable(): void {
        $this->assertSame('O caminho informado não é uma pasta.', $this->failure('files_list', ['path' => '/relatorio.pdf']));
        $this->assertSame(ToolFailure::NOT_FOUND, $this->failure('files_list', ['path' => '/nada']));
        $this->tree->nodes['/alice/files/Documentos']['readable'] = false;
        $this->assertSame(ToolFailure::FORBIDDEN, $this->failure('files_list', ['path' => 'Documentos']));
        foreach (['/../bob/files', "/a\0b", 'Documentos/../..'] as $path) {
            try {
                $this->tool('files_list', ['path' => $path]);
                $this->fail("accepted $path");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSearchMatchesNamesAndCapsResults(): void {
        $this->assertSame(['/Documentos/ata.md'], array_column($this->json('files_search', ['query' => 'ATA']), 'path'));
        $this->tree->addFile('/alice/files/b-ata.txt', 'x');
        $this->assertCount(1, $this->json('files_search', ['query' => 'ata', 'limit' => 1]));
        $this->expectException(InvalidArgumentException::class);
        $this->tool('files_search', ['query' => '']);
    }

    public function testSearchPushesAnEscapedLikeAndTheLimitIntoTheQuery(): void {
        $this->tree->addFile('/alice/files/100%_real.txt', 'x');
        $this->tree->addFile('/alice/files/100abreal.txt', 'x');
        $this->assertSame(['/100%_real.txt'], array_column($this->json('files_search', ['query' => '100%_r', 'limit' => 7]), 'path'));
        $query = $this->tree->searches[0];
        $this->assertSame(7 + FilesModule::SEARCH_OVERFETCH, $query->getLimit());
        $this->assertSame(0, $query->getOffset());
        $comparison = $query->getSearchOperation();
        $this->assertSame([ISearchComparison::COMPARE_LIKE, 'name', '%100\\%\\_r%'], [$comparison->getType(), $comparison->getField(), $comparison->getValue()]);
    }

    public function testSearchSkipsUnreadableMatchesAndTrimsToLimit(): void {
        $this->tree->addFile('/alice/files/ata-1.txt', 'x', 'text/plain', ['readable' => false]);
        $this->tree->addFile('/alice/files/ata-2.txt', 'x');
        $this->tree->addFile('/alice/files/ata-3.txt', 'x');
        $paths = array_column($this->json('files_search', ['query' => 'ata-', 'limit' => 2]), 'path');
        $this->assertSame(['/ata-2.txt', '/ata-3.txt'], $paths);
    }

    public function testReadsTextPdfDocxAndOdt(): void {
        $this->assertSame("# Ata\nolá", $this->tool('files_read', ['path' => '/Documentos/ata.md'])['content'][0]['text']);
        $this->assertStringContainsString('HELLO_MCP', $this->tool('files_read', ['path' => '/relatorio.pdf'])['content'][0]['text']);
        $this->tree->addFile('/alice/files/a.docx', (string)file_get_contents(__DIR__ . '/../../fixtures/sample.docx'), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $this->assertStringContainsString('HELLO_MCP', $this->tool('files_read', ['path' => '/a.docx'])['content'][0]['text']);
        $this->tree->addFile('/alice/files/a.odt', self::zip('content.xml', '<office:text><text:h>Título</text:h><text:p>Um<text:tab/>dois &amp; três</text:p></office:text>'), 'application/vnd.oasis.opendocument.text');
        $this->assertSame("Título\nUm\tdois & três", $this->tool('files_read', ['path' => '/a.odt'])['content'][0]['text']);
    }

    public function testCorruptDocumentsAndZipBombsReturnANotice(): void {
        $this->tree->addFile('/alice/files/bad.pdf', "%PDF-broken\0\0", 'application/pdf');
        $this->assertSame('[não foi possível extrair o texto de bad.pdf]', $this->tool('files_read', ['path' => '/bad.pdf'])['content'][0]['text']);
        $this->tree->addFile('/alice/files/bomb.docx', self::zip('word/document.xml', str_repeat('A', TextExtractor::MAX_BYTES + 1)), '');
        $this->assertSame('[não foi possível extrair o texto de bomb.docx]', $this->tool('files_read', ['path' => '/bomb.docx'])['content'][0]['text']);
    }

    public function testReadEnforcesByteLimitBeforeAndWhileReading(): void {
        $this->tree->addFile('/alice/files/big.pdf', 'x', 'application/pdf', ['size' => TextExtractor::MAX_BYTES + 1]);
        $this->assertSame(TextExtractor::tooLarge(), $this->failure('files_read', ['path' => '/big.pdf']));
        // The size reported by the storage lies: the stream read still stops at the limit.
        $this->tree->addFile('/alice/files/liar.txt', str_repeat('y', TextExtractor::MAX_BYTES + 1), 'text/plain', ['size' => 10]);
        $this->assertSame(TextExtractor::tooLarge(), $this->failure('files_read', ['path' => '/liar.txt']));
    }

    public function testReadTruncatesAtCharacterLimit(): void {
        $this->tree->addFile('/alice/files/long.txt', str_repeat('é', FilesModule::MAX_CHARS + 50));
        $text = $this->tool('files_read', ['path' => '/long.txt'])['content'][0]['text'];
        $this->assertStringEndsWith("\n\n[conteúdo truncado]", $text);
        $this->assertSame(FilesModule::MAX_CHARS, mb_strlen(explode("\n\n[", $text)[0]));
    }

    public function testReadRejectsFoldersAndBinaries(): void {
        $this->assertSame('O caminho informado não é um arquivo.', $this->failure('files_read', ['path' => '/Documentos']));
        $this->tree->addFile('/alice/files/foto.jpg', "\xFF\xD8", 'image/jpeg');
        $this->assertSame('Formato de arquivo não suportado para leitura de texto.', $this->failure('files_read', ['path' => '/foto.jpg']));
    }

    public function testEditBacksUpThenWrites(): void {
        $etag = $this->tree->nodes['/alice/files/Documentos/ata.md']['etag'];
        $out = $this->json('files_edit', ['path' => '/Documentos/ata.md', 'content' => 'novo', 'etag' => $etag]);
        $backup = '/MCP backups/Documentos/ata.md.20260921-141320.bak';
        $this->assertSame(['path' => '/Documentos/ata.md', 'size' => 4, 'etag' => $etag . '+', 'backup' => $backup], $out);
        $this->assertSame("# Ata\nolá", $this->tree->nodes['/alice/files' . $backup]['content']);
        $this->assertSame('novo', $this->tree->nodes['/alice/files/Documentos/ata.md']['content']);
        $this->assertSame(['mkdir /alice/files/MCP backups', 'mkdir /alice/files/MCP backups/Documentos',
            'copy /alice/files/Documentos/ata.md /alice/files' . $backup, 'write /alice/files/Documentos/ata.md'], $this->tree->ops);
    }

    public function testSecondBackupInTheSameSecondGetsASuffix(): void {
        $this->tool('files_edit', ['path' => '/Documentos/ata.md', 'content' => 'v2']);
        $this->assertStringEndsWith('-2.bak', $this->json('files_edit', ['path' => '/Documentos/ata.md', 'content' => 'v3'])['backup']);
    }

    /** @return iterable<string, array{0: callable(self):void, 1: string}> */
    public static function blockedEdits(): iterable {
        yield 'versions disabled' => [fn (self $t) => $t->apps = [], 'files_versions'];
        yield 'copy fails' => [fn (self $t) => $t->tree->failCopy[] = '/alice/files/Documentos/ata.md', 'cópia de segurança'];
        yield 'copy size differs' => [fn (self $t) => $t->tree->shortCopy[] = '/alice/files/Documentos/ata.md', 'cópia de segurança'];
        yield 'not updateable' => [fn (self $t) => $t->tree->nodes['/alice/files/Documentos/ata.md']['updateable'] = false, ToolFailure::FORBIDDEN];
        yield 'backup folder name taken by a file' => [fn (self $t) => $t->tree->addFile('/alice/files/MCP backups', 'x'), 'cópia de segurança'];
    }

    /** @dataProvider blockedEdits */
    public function testEditIsBlockedWithoutWritingWhenAPreconditionFails(callable $arrange, string $message): void {
        $arrange($this);
        $this->assertStringContainsString($message, $this->failure('files_edit', ['path' => '/Documentos/ata.md', 'content' => 'novo']));
        $this->assertSame("# Ata\nolá", $this->tree->nodes['/alice/files/Documentos/ata.md']['content']);
        $this->assertNotContains('write /alice/files/Documentos/ata.md', $this->tree->ops);
    }

    public function testEditRefusesStaleEtagBinariesBackupsAndOversizedContent(): void {
        $this->assertSame(ToolFailure::CONFLICT, $this->failure('files_edit', ['path' => '/Documentos/ata.md', 'content' => 'x', 'etag' => 'old']));
        $this->assertSame('Somente arquivos de texto podem ser editados pelo MCP.', $this->failure('files_edit', ['path' => '/relatorio.pdf', 'content' => 'x']));
        $this->tree->addFile('/alice/files/MCP backups/a.txt.bak', 'b');
        $this->assertStringContainsString('não podem ser editados', $this->failure('files_edit', ['path' => '/MCP backups/a.txt.bak', 'content' => 'x']));
        $this->assertStringContainsString('limite de edição', $this->failure('files_edit', ['path' => '/Documentos/ata.md', 'content' => str_repeat('x', FilesModule::MAX_EDIT_BYTES + 1)]));
        $this->assertSame(ToolFailure::NOT_FOUND, $this->failure('files_edit', ['path' => '/novo.txt', 'content' => 'x']));
        $this->assertSame([], $this->tree->ops);
    }

    public function testWriteFailureAfterBackupReportsTheBackup(): void {
        $this->tree->failWrite = true;
        $this->assertStringContainsString('/MCP backups/Documentos/ata.md.', $this->failure('files_edit', ['path' => '/Documentos/ata.md', 'content' => 'x']));
    }

    public function testFilesCodeNeverDeletesMovesOrRenames(): void {
        foreach (['/../../../lib/Tools/Files/FilesModule.php', '/../../../lib/Tools/Files/TextExtractor.php'] as $file) {
            $this->assertDoesNotMatchRegularExpression('/->(delete|move|rename|unlink)\s*\(/', (string)file_get_contents(__DIR__ . $file), $file);
        }
        $this->json('files_edit', ['path' => '/Documentos/ata.md', 'content' => 'x']);
        $this->assertSame([], array_filter($this->tree->ops, fn ($op) => preg_match('/^(delete|move) /', $op) === 1));
    }

    private static function zip(string $entry, string $content): string {
        $path = tempnam(sys_get_temp_dir(), 'mcpzip');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString($entry, $content);
        $zip->close();
        $bytes = (string)file_get_contents($path);
        unlink($path);
        return $bytes;
    }
}
