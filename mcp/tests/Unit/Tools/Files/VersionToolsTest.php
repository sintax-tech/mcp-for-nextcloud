<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\Common\SharedWriteGuard;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tools\Files\FileBackup;
use OCA\Mcp\Tools\Files\TextExtractor;
use OCA\Mcp\Tools\Files\VersionTools;
use OCA\Mcp\Tools\ToolFailure;
use OCP\App\IAppManager;
use OCP\Files\Folder;
use OCP\IUser;
use OCP\IUserManager;
use OCA\Files_Versions\Versions\FakeVersion;
use OCA\Files_Versions\Versions\FakeVersionManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The three version tools on top of files_versions, which Nextcloud 33 only exposes internally. The
 * container double resolves the real class string, so a rename upstream would fail here too.
 */
final class VersionToolsTest extends TestCase {
    private const FILE = '/alice/files/Documentos/ata.md';
    private const MANAGER = 'OCA\Files_Versions\Versions\IVersionManager';

    private FakeTree $tree;
    private FakeVersionManager $manager;
    private VersionTools $tools;
    private IAppManager $apps;
    private IUserManager $users;
    private IUser $user;
    private FileBackup $backup;

    protected function setUp(): void {
        require_once __DIR__ . '/Stubs/versions_api.php';
        $this->tree = new FakeTree($this);
        $this->tree->addFile(self::FILE, "# Ata\nolá", 'text/markdown');
        $this->manager = FakeVersionManager::with([
            new FakeVersion(1759200000, 1759200000, 9, 'text/markdown', 'ata.md'),
            new FakeVersion(1759100000, 1759100000, 4, 'text/markdown', 'ata.md'),
        ]);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(fn (string $id) => $id === self::MANAGER ? $this->manager : null);
        $this->user = $this->createMock(IUser::class);
        $this->user->method('getUID')->willReturn('alice');
        $this->users = $this->createMock(IUserManager::class);
        $this->users->method('get')->willReturn($this->user);
        $this->apps = $this->createMock(IAppManager::class);
        $this->apps->method('isEnabledForUser')->willReturn(true);
        $temp = $this->createMock(\OCP\ITempManager::class);
        $temp->method('getTemporaryFile')->willReturnCallback(fn () => tempnam(sys_get_temp_dir(), 'mcp'));
        $time = $this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class);
        $time->method('getTime')->willReturn(1790000000);
        $config = (new \OCA\Mcp\Tests\Unit\InMemoryConfig())->mock($this);
        $this->backup = new FileBackup($this->apps, $this->users, $time, $config);
        $this->tools = new VersionTools($this->apps, $this->users, new TextExtractor($temp), $this->backup,
            new NodeAccessInfo(), $container);
    }

    private function file(): \OCP\Files\File {
        return $this->tree->node(self::FILE);
    }

    public function testListReturnsEveryVersionNewestFirstWithTheAccessDescription(): void {
        $out = $this->tools->list($this->tree->rootFolder(), $this->file(), '/Documentos/ata.md', 50, 'alice');
        $this->assertSame(['path', 'access', 'versions', 'truncated'], array_keys($out));
        $this->assertSame('personal', $out['access']['scope']);
        $this->assertFalse($out['truncated']);
        $this->assertSame(['1759200000', '1759100000'], array_column($out['versions'], 'revision'));
        $this->assertSame(['2025-09-30T02:40:00Z', '2025-09-28T22:53:20Z'], array_column($out['versions'], 'timestamp'));
        $this->assertSame([9, 4], array_column($out['versions'], 'size'));
    }

    public function testListHonoursTheLimitAndSaysItCut(): void {
        $out = $this->tools->list($this->tree->rootFolder(), $this->file(), '/Documentos/ata.md', 1, 'alice');
        $this->assertCount(1, $out['versions']);
        $this->assertTrue($out['truncated']);
    }

    public function testVersionsRequireTheAppAndTheUser(): void {
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturn(false);
        $tools = new VersionTools($apps, $this->users, $this->createMock(TextExtractor::class), $this->backup,
            new NodeAccessInfo(), $this->createMock(ContainerInterface::class));
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage('O versionamento de arquivos (files_versions) não está ativo nesta conta.');
        $tools->list($this->tree->rootFolder(), $this->file(), '/Documentos/ata.md', 50, 'alice');
    }

    /** The app lookup must receive the IUser, not its uid: isEnabledForUser is not typed in OCP. */
    public function testTheAppLookupReceivesTheUserObject(): void {
        $seen = [];
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturnCallback(function (string $app, $user) use (&$seen): bool {
            $seen[] = $user;
            return true;
        });
        $tools = new VersionTools($apps, $this->users, $this->createMock(TextExtractor::class), $this->backup,
            new NodeAccessInfo(), $this->createMock(ContainerInterface::class));
        try {
            $tools->list($this->tree->rootFolder(), $this->file(), '/Documentos/ata.md', 50, 'alice');
        } catch (ToolFailure) {
            // the container double resolves nothing here; only the app lookup is under test
        }
        $this->assertCount(1, $seen);
        $this->assertSame($this->user, $seen[0]);
    }

    public function testReadExtractsTheStoredVersionText(): void {
        $this->manager->versions[0]->withContent('versão antiga');
        $out = $this->tools->read($this->file(), '/Documentos/ata.md', '1759200000', 'alice');
        $this->assertSame('versão antiga', $out['text']);
        $this->assertSame('1759200000', $out['version']);
        $this->assertFalse($out['truncated']);
        $this->assertSame(14, $out['size']);
        $this->assertContains('read', $this->manager->ops);
    }

    public function testReadRefusesAnUnknownVersion(): void {
        try {
            $this->tools->read($this->file(), '/Documentos/ata.md', 'nope', 'alice');
            $this->fail('an unknown revision must not resolve');
        } catch (ToolFailure $e) {
            $this->assertSame('Versão não encontrada para este arquivo: nope.', $e->getMessage());
        }
    }

    public function testReadReportsAVersionThatCannotBeOpened(): void {
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage('Não foi possível ler o conteúdo desta versão.');
        $this->tools->read($this->file(), '/Documentos/ata.md', '1759200000', 'alice');
    }

    public function testRestoreBacksUpThenRollsBack(): void {
        $out = $this->tools->restore($this->tree->rootFolder(), $this->file(), '/Documentos/ata.md', '1759100000', 'alice');
        $this->assertSame(['path', 'size', 'etag', 'access', 'backup', 'version'], array_keys($out));
        $this->assertSame('1759100000', $out['version']);
        $this->assertStringStartsWith('/MCP backups/Documentos/ata.md.', $out['backup']);
        $this->assertSame('# Ata' . "\n" . 'olá', $this->tree->nodes['/alice/files' . $out['backup']]['content']);
        $this->assertSame(['mkdir /alice/files/MCP backups', 'mkdir /alice/files/MCP backups/Documentos',
            'copy /alice/files/Documentos/ata.md /alice/files' . $out['backup']], $this->tree->ops);
        $this->assertContains('rollback', $this->manager->ops);
    }

    public function testRestoreReportsARollbackThatFailed(): void {
        $this->manager->rollbackResult = false;
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage('Não foi possível restaurar esta versão.');
        $this->tools->restore($this->tree->rootFolder(), $this->file(), '/Documentos/ata.md', '1759100000', 'alice');
    }

    public function testRestoreWithoutVersioningNeverRollsBack(): void {
        $this->apps = $this->createMock(IAppManager::class);
        $this->apps->method('isEnabledForUser')->willReturn(false);
        $tools = new VersionTools($this->apps, $this->users, $this->createMock(TextExtractor::class), $this->backup,
            new NodeAccessInfo(), $this->createMock(ContainerInterface::class));
        try {
            $tools->restore($this->tree->rootFolder(), $this->file(), '/Documentos/ata.md', '1759100000', 'alice');
            $this->fail('a restore without versioning must not proceed');
        } catch (ToolFailure) {
        }
        $this->assertSame([], $this->manager->ops);
    }

    public function testRestoreRefusesAFileNextcloudWillNotUpdate(): void {
        $this->tree->nodes[self::FILE]['updateable'] = false;
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(ToolFailure::FORBIDDEN);
        $this->tools->restore($this->tree->rootFolder(), $this->file(), '/Documentos/ata.md', '1759100000', 'alice');
    }
}
