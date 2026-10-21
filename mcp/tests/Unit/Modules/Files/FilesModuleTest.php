<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use InvalidArgumentException;
use OCA\Mcp\Modules\Files\Editing\ContentPlan;
use OCA\Mcp\Modules\Files\Editing\ContentWriter;
use OCA\Mcp\Modules\Files\Editing\EditingMessages;
use OCA\Mcp\Modules\Files\Editing\FileBackup;
use OCA\Mcp\Modules\Files\Editing\TextFileEditor;
use OCA\Mcp\Modules\Files\FilesModule;
use OCA\Mcp\Modules\Files\Reading\DirectoryReader;
use OCA\Mcp\Modules\Files\Reading\FileEntry;
use OCA\Mcp\Modules\Files\Reading\FileReader;
use OCA\Mcp\Modules\Files\Reading\OcrSupport;
use OCA\Mcp\Modules\Files\Reading\ReadingMessages;
use OCA\Mcp\Modules\Files\Reading\TextExtractor;
use OCA\Mcp\Modules\Files\Search\FileSearch;
use OCA\Mcp\Modules\Files\Tools\EditFile;
use OCA\Mcp\Modules\Files\Tools\ListFiles;
use OCA\Mcp\Modules\Files\Tools\ReadFile;
use OCA\Mcp\Modules\Files\Tools\SearchFiles;
use OCA\Mcp\Platform\FileAccess\BackupMessages;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Support\Files\FilesFactories;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Support\Platform\FileAccess\Locks\FakeLockManager;
use OCA\Mcp\Tests\Support\Platform\FileAccess\FakeTree;
use OCA\Mcp\Tests\Support\Identity\FakeUsers;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\App\IAppManager;
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
    private InMemoryConfig $config;
    private FilesModule $module;

    protected function setUp(): void {
        $this->tree = new FakeTree($this);
        $this->tree->addFolder('/alice/files/Documentos');
        $this->tree->addFile('/alice/files/Documentos/ata.md', "# Ata\nolá", 'text/markdown');
        $this->tree->addFile('/alice/files/relatorio.pdf', (string)file_get_contents(__DIR__ . '/../../../fixtures/sample.pdf'), 'application/pdf');

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
        $this->config = new InMemoryConfig();
        // Alice's own timezone stamps every backup below; the stamps are local, never UTC.
        $this->config->user['alice']['core']['timezone'] = 'America/Sao_Paulo';
        $appConfig = $this->config->mock($this);
        $access = new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager());
        $fileVisibility = \OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this, $appConfig, $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class), $root);
        $fileLocks = (new FakeLockManager())->service($this);
        $this->module = new FilesModule(FilesFactories::complete([
            'files_read' => fn () => new ReadFile(new FileReader($root, new TextExtractor($temp), $access, new OcrSupport($apps), $fileVisibility)),
            'files_edit' => fn () => new EditFile(new TextFileEditor($root, new TextExtractor($temp), new ContentWriter(new FileBackup($apps, $users, $time, $appConfig, $fileVisibility, $fileLocks), $access, $fileVisibility, $fileLocks), new ContentPlan($access, new SharedWriteGuard($access), $fileLocks), new SharedWriteGuard($access), $fileVisibility, $fileLocks)),
            'files_list' => fn () => new ListFiles($root, new DirectoryReader(new FileEntry($access), \OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this))),
            'files_search' => fn () => new SearchFiles($root, new FileSearch($db, $users, new FileEntry($access), \OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this), null, null)),
        ]), (\OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this))->assertScopeReadable(...));
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
        $this->assertSame(['files_list', 'files_search', 'files_tree', 'files_mkdir', 'files_copy', 'files_move',
            'files_move_batch', 'files_undo_batch', 'files_read', 'files_edit', 'files_replace',
            'files_checkout', 'files_versions_list', 'files_version_read', 'files_version_restore',
            'files_image_view', 'files_images_view', 'files_image_search', 'files_list_shares', 'files_share', 'files_unshare',
            'files_upload', 'files_create'],
            array_keys($defs));
        $this->assertSame(['read', 'read', 'read', 'create', 'create', 'move', 'move', 'move', 'read', 'edit',
            'edit', 'edit', 'read', 'read', 'restore', 'read', 'read', 'read', 'read', 'share', 'share', 'create', 'create'], array_column($defs, 'operation'));
        $this->assertSame(['files_versions', 'files_versions', 'files_versions'],
            array_values(array_filter(array_column($defs, 'app', 'name'))));
        $this->assertArrayNotHasKey('app', $defs['files_tree'], 'a árvore só depende de arquivos, que sempre existem');
        $this->assertSame(['path', 'old', 'new'], $defs['files_replace']['inputSchema']['required']);
        $this->assertSame(['path', 'version'], $defs['files_version_restore']['inputSchema']['required']);
        $this->assertSame('/', $defs['files_list']['inputSchema']['properties']['path']['default']);
        $this->assertSame(['minimum' => 1, 'maximum' => 100, 'default' => 25], array_intersect_key($defs['files_search']['inputSchema']['properties']['limit'], ['minimum' => 0, 'maximum' => 0, 'default' => 0]));
        $this->assertSame(['path'], $defs['files_read']['inputSchema']['required']);
    }

    public function testListReturnsPrototypeEntryShape(): void {
        $entries = $this->json('files_list');
        $this->assertSame(['Documentos', 'relatorio.pdf'], array_column($entries, 'name'));
        $this->assertSame(['name' => 'Documentos', 'path' => '/Documentos', 'isDir' => true, 'size' => 0,
            'mtime' => 'Tue, 30 Sep 2025 02:40:00 GMT', 'contentType' => 'httpd/unix-directory',
            'access' => ['scope' => 'personal', 'owner' => 'alice', 'ownerDisplayName' => 'Alice',
                'permissions' => ['read' => true, 'update' => true, 'create' => true, 'delete' => true, 'share' => true]]],
            $entries[0]);
        $this->assertSame('/Documentos/ata.md', $this->json('files_list', ['path' => '/Documentos'])[0]['path']);
    }

    public function testListRejectsFilesTraversalAndUnreadable(): void {
        $this->assertSame(ReadingMessages::notAFolder(), $this->failure('files_list', ['path' => '/relatorio.pdf']));
        $this->assertSame(FileAccessMessages::notFound(), $this->failure('files_list', ['path' => '/nada']));
        $this->tree->nodes['/alice/files/Documentos']['readable'] = false;
        $this->assertSame(FileAccessMessages::forbidden(), $this->failure('files_list', ['path' => 'Documentos']));
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
        $res = $this->json('files_search', ['query' => 'ATA']);
        $this->assertSame('name_only', $res['search_mode']);
        $this->assertFalse($res['full_text_active']);
        $this->assertSame(['/Documentos/ata.md'], array_column($res['files'], 'path'));
        $this->tree->addFile('/alice/files/b-ata.txt', 'x');
        $this->assertCount(1, $this->json('files_search', ['query' => 'ata', 'limit' => 1])['files']);
        $this->expectException(InvalidArgumentException::class);
        $this->tool('files_search', ['query' => '']);
    }

    public function testSearchPushesAnEscapedLikeAndTheLimitIntoTheQuery(): void {
        $this->tree->addFile('/alice/files/100%_real.txt', 'x');
        $this->tree->addFile('/alice/files/100abreal.txt', 'x');
        $res = $this->json('files_search', ['query' => '100%_r', 'limit' => 7]);
        $this->assertSame('name_only', $res['search_mode']);
        $this->assertSame(['/100%_real.txt'], array_column($res['files'], 'path'));
        $query = $this->tree->searches[0];
        $this->assertSame(7 + FileSearch::SEARCH_OVERFETCH, $query->getLimit());
        $this->assertSame(0, $query->getOffset());
        $comparison = $query->getSearchOperation();
        $this->assertSame([ISearchComparison::COMPARE_LIKE, 'name', '%100\\%\\_r%'], [$comparison->getType(), $comparison->getField(), $comparison->getValue()]);
    }

    public function testSearchSkipsUnreadableMatchesAndTrimsToLimit(): void {
        $this->tree->addFile('/alice/files/ata-1.txt', 'x', 'text/plain', ['readable' => false]);
        $this->tree->addFile('/alice/files/ata-2.txt', 'x');
        $this->tree->addFile('/alice/files/ata-3.txt', 'x');
        $res = $this->json('files_search', ['query' => 'ata-', 'limit' => 2]);
        $paths = array_column($res['files'], 'path');
        $this->assertSame(['/ata-2.txt', '/ata-3.txt'], $paths);
    }

    public function testReadsTextPdfDocxAndOdt(): void {
        $this->assertSame("# Ata\nolá", $this->tool('files_read', ['path' => '/Documentos/ata.md'])['content'][0]['text']);
        $this->assertStringContainsString('HELLO_MCP', $this->tool('files_read', ['path' => '/relatorio.pdf'])['content'][0]['text']);
        $this->tree->addFile('/alice/files/a.docx', (string)file_get_contents(__DIR__ . '/../../../fixtures/sample.docx'), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $this->assertStringContainsString('HELLO_MCP', $this->tool('files_read', ['path' => '/a.docx'])['content'][0]['text']);
        $this->tree->addFile('/alice/files/a.odt', self::zip('content.xml', '<office:text><text:h>Título</text:h><text:p>Um<text:tab/>dois &amp; três</text:p></office:text>'), 'application/vnd.oasis.opendocument.text');
        $this->assertSame("Título\nUm\tdois & três", $this->tool('files_read', ['path' => '/a.odt'])['content'][0]['text']);
    }

    public function testCorruptDocumentsAndZipBombsReturnANotice(): void {
        $this->tree->addFile('/alice/files/bad.pdf', "%PDF-broken\0\0", 'application/pdf');
        $this->assertSame(ReadingMessages::notExtracted('bad.pdf'), $this->tool('files_read', ['path' => '/bad.pdf'])['content'][0]['text']);
        $this->tree->addFile('/alice/files/bomb.docx', self::zip('word/document.xml', str_repeat('A', TextExtractor::MAX_BYTES + 1)), '');
        $this->assertSame(ReadingMessages::notExtracted('bomb.docx'), $this->tool('files_read', ['path' => '/bomb.docx'])['content'][0]['text']);
    }

    public function testReadEnforcesByteLimitBeforeAndWhileReading(): void {
        $this->tree->addFile('/alice/files/big.pdf', 'x', 'application/pdf', ['size' => TextExtractor::MAX_BYTES + 1]);
        $this->assertSame(TextExtractor::tooLarge(), $this->failure('files_read', ['path' => '/big.pdf']));
        // The size reported by the storage lies: the stream read still stops at the limit.
        $this->tree->addFile('/alice/files/liar.txt', str_repeat('y', TextExtractor::MAX_BYTES + 1), 'text/plain', ['size' => 10]);
        $this->assertSame(TextExtractor::tooLarge(), $this->failure('files_read', ['path' => '/liar.txt']));
    }

    public function testReadTruncatesAtCharacterLimit(): void {
        $this->tree->addFile('/alice/files/long.txt', str_repeat('é', TextExtractor::MAX_CHARS + 50));
        $text = $this->tool('files_read', ['path' => '/long.txt'])['content'][0]['text'];
        $this->assertStringEndsWith("

" . ReadingMessages::textTruncated(), $text);
        $this->assertSame(TextExtractor::MAX_CHARS, mb_strlen(explode("\n\n[", $text)[0]));
    }

    public function testReadRejectsFoldersAndBinaries(): void {
        $this->assertSame(ReadingMessages::notAFile(), $this->failure('files_read', ['path' => '/Documentos']));
        $this->tree->addFile('/alice/files/dados.bin', "\x00\x01", 'application/octet-stream');
        $this->assertSame(ReadingMessages::unsupportedFormat(), $this->failure('files_read', ['path' => '/dados.bin']));
    }

    public function testEditBacksUpThenWrites(): void {
        $etag = $this->tree->nodes['/alice/files/Documentos/ata.md']['etag'];
        $out = $this->json('files_edit', ['path' => '/Documentos/ata.md', 'content' => 'novo', 'etag' => $etag]);
        // 1790000000 is 2026-09-21 14:13:20 UTC; America/Sao_Paulo is UTC-3, so 11:13:20 local.
        $backup = '/MCP backups/Documentos/ata.md.20260921-111320.bak';
        $this->assertSame(['path' => '/Documentos/ata.md', 'size' => 4, 'etag' => $etag . '+',
            'access' => ['scope' => 'personal', 'owner' => 'alice', 'ownerDisplayName' => 'Alice',
                'permissions' => ['read' => true, 'update' => true, 'create' => true, 'delete' => true, 'share' => true]],
            'backup' => $backup,
            'diff' => "--- antes\n+++ depois\n@@ -1,2 +1,1 @@\n-# Ata\n-olá\n\\ No newline at end of file\n+novo\n\\ No newline at end of file\n"], $out);
        $this->assertSame("# Ata\nolá", $this->tree->nodes['/alice/files' . $backup]['content']);
        $this->assertSame('novo', $this->tree->nodes['/alice/files/Documentos/ata.md']['content']);
        $this->assertSame(['mkdir /alice/files/MCP backups', 'mkdir /alice/files/MCP backups/Documentos',
            'create /alice/files' . $backup, 'write /alice/files' . $backup, 'write /alice/files/Documentos/ata.md'], $this->tree->ops);
    }

    public function testSecondBackupInTheSameSecondGetsASuffix(): void {
        $this->tool('files_edit', ['path' => '/Documentos/ata.md', 'content' => 'v2']);
        $this->assertStringEndsWith('-2.bak', $this->json('files_edit', ['path' => '/Documentos/ata.md', 'content' => 'v3'])['backup']);
    }

    /**
     * Backup stamp of 1790000000 (2026-09-21 14:13:20 UTC) under each timezone source, in precedence order.
     *
     * @return iterable<string, array{0: string|null, 1: string|null, 2: string}>
     *         the user's `core`/`timezone`, the system `default_timezone` (null = unset) and the stamp that must win
     */
    public static function timezoneSources(): iterable {
        yield 'user timezone, not the system one' => ['America/Sao_Paulo', 'Europe/Berlin', '20260921-111320'];
        yield 'system timezone when the user has none' => [null, 'Europe/Berlin', '20260921-161320'];
        yield 'invalid user timezone falls back to the system' => ['Marte/Cratera', 'Europe/Berlin', '20260921-161320'];
        yield 'invalid timezone everywhere falls back to php' => ['Marte/Cratera', 'Marte/Cratera',
            (new \DateTimeImmutable('@1790000000'))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Ymd-His')];
    }

    /**
     * @dataProvider timezoneSources
     * @param string|null $userZone value of the user's core/timezone preference, null when unset
     * @param string|null $systemZone value of the default_timezone system setting, null when unset
     * @param string $expected `Ymd-His` stamp the backup must carry
     */
    public function testBackupStampFollowsTheTimezonePrecedence(?string $userZone, ?string $systemZone, string $expected): void {
        $this->config->user = [];
        if ($userZone !== null) {
            $this->config->user['alice']['core']['timezone'] = $userZone;
        }
        $this->config->system = [];
        if ($systemZone !== null) {
            $this->config->system['default_timezone'] = $systemZone;
        }
        $backup = $this->json('files_edit', ['path' => '/Documentos/ata.md', 'content' => 'x'])['backup'];
        $this->assertSame('/MCP backups/Documentos/ata.md.' . $expected . '.bak', $backup);
    }

    /** @return iterable<string, array{0: callable(self):void, 1: string}> */
    public static function blockedEdits(): iterable {
        yield 'versions disabled' => [fn (self $t) => $t->apps = [], EditingMessages::versioningOff()];
        yield 'copy fails' => [fn (self $t) => $t->tree->failBackupWrite = true, BackupMessages::failed()];
        yield 'copy size differs' => [fn (self $t) => $t->tree->shortBackupWrite = true, BackupMessages::failed()];
        yield 'not updateable' => [fn (self $t) => $t->tree->nodes['/alice/files/Documentos/ata.md']['updateable'] = false, FileAccessMessages::forbidden()];
        yield 'backup folder name taken by a file' => [fn (self $t) => $t->tree->addFile('/alice/files/MCP backups', 'x'), BackupMessages::failed()];
    }

    /** @dataProvider blockedEdits */
    public function testEditIsBlockedWithoutWritingWhenAPreconditionFails(callable $arrange, string $message): void {
        $arrange($this);
        $this->assertStringContainsString($message, $this->failure('files_edit', ['path' => '/Documentos/ata.md', 'content' => 'novo']));
        $this->assertSame("# Ata\nolá", $this->tree->nodes['/alice/files/Documentos/ata.md']['content']);
        $this->assertNotContains('write /alice/files/Documentos/ata.md', $this->tree->ops);
    }

    public function testEditRefusesStaleEtagBinariesBackupsAndOversizedContent(): void {
        $this->assertSame(FileAccessMessages::conflict(), $this->failure('files_edit', ['path' => '/Documentos/ata.md', 'content' => 'x', 'etag' => 'old']));
        $this->assertSame(EditingMessages::notText(), $this->failure('files_edit', ['path' => '/relatorio.pdf', 'content' => 'x']));
        $this->tree->addFile('/alice/files/MCP backups/a.txt.bak', 'b');
        $this->assertSame(EditingMessages::backupPath(), $this->failure('files_edit', ['path' => '/MCP backups/a.txt.bak', 'content' => 'x']));
        $this->assertSame(EditingMessages::editTooLarge(TextFileEditor::MAX_EDIT_BYTES), $this->failure('files_edit', ['path' => '/Documentos/ata.md', 'content' => str_repeat('x', TextFileEditor::MAX_EDIT_BYTES + 1)]));
        $this->assertSame(FileAccessMessages::notFound(), $this->failure('files_edit', ['path' => '/novo.txt', 'content' => 'x']));
        $this->assertSame([], $this->tree->ops);
    }

    public function testWriteFailureAfterBackupReportsTheBackup(): void {
        $this->tree->failWrite = true;
        $this->assertStringContainsString('/MCP backups/Documentos/ata.md.', $this->failure('files_edit', ['path' => '/Documentos/ata.md', 'content' => 'x']));
    }

    public function testFilesCodeNeverDeletesMovesOrRenames(): void {
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
