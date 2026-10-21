<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files\Versions;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use OCA\Mcp\Modules\Files\Editing\FileBackup;
use OCA\Mcp\Modules\Files\Reading\OcrSupport;
use OCA\Mcp\Modules\Files\Reading\ReadingMessages;
use OCA\Mcp\Modules\Files\Reading\TextExtractor;
use OCA\Mcp\Modules\Files\Versions\VersionMessages;
use OCA\Mcp\Modules\Files\Versions\VersionReader;
use OCA\Mcp\Modules\Files\Versions\VersionRestorer;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Support\Files\Stubs\FakeVersion;
use OCA\Mcp\Tests\Support\Files\Stubs\FakeVersionManager;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Support\Platform\FileAccess\Locks\FakeLock;
use OCA\Mcp\Tests\Support\Platform\FileAccess\Locks\FakeLockManager;
use OCA\Mcp\Tests\Support\Platform\FileAccess\FakeTree;
use OCA\Mcp\Tests\Support\Identity\FakeUsers;
use OCA\Mcp\Platform\FileAccess\Locks\LockWriteFailure;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\App\IAppManager;
use OCP\Files\Lock\ILock;
use OCP\IUser;
use OCP\IUserManager;
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
    private VersionReader $tools;
    private VersionRestorer $restorer;
    private IAppManager $apps;
    private IUserManager $users;
    private IUser $user;
    private FileBackup $backup;

    protected function setUp(): void {
        require_once __DIR__ . '/../../../../Support/Files/Stubs/versions_api.php';
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
        $config = (new InMemoryConfig())->mock($this);
        $this->backup = new FileBackup($this->apps, $this->users, $time, $config, \OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this));
        $this->tools = new VersionReader($this->apps, $this->users, new TextExtractor($temp), new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager()), fn () => ($container)->get(VersionReader::MANAGER), new OcrSupport($this->apps));
        $this->restorer = new VersionRestorer(new VersionReader($this->apps, $this->users, new TextExtractor($temp), new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager()), fn () => ($container)->get(VersionReader::MANAGER), new OcrSupport($this->apps)), $this->backup, new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager()), new SharedWriteGuard(new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager())), \OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this), null);
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
        $tools = new VersionReader($apps, $this->users, $this->createMock(TextExtractor::class), new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager()), fn () => ($this->createMock(ContainerInterface::class))->get(VersionReader::MANAGER), new OcrSupport($apps));
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(VersionMessages::versionsOff());
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
        $tools = new VersionReader($apps, $this->users, $this->createMock(TextExtractor::class), new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager()), fn () => ($this->createMock(ContainerInterface::class))->get(VersionReader::MANAGER), new OcrSupport($apps));
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

    public function testReadOfAnImageVersionWarnsThereIsNoText(): void {
        $this->manager->versions[0]->withContent("\xFF\xD8");
        $this->manager->versions[0] = new FakeVersion(1759200000, 1759200000, 2, 'image/jpeg', 'foto.jpg');
        $this->manager->versions[0]->withContent("\xFF\xD8");
        $out = $this->tools->read($this->file(), '/Documentos/foto.jpg', '1759200000', 'alice');
        $this->assertFalse($out['text_layer']);
        $this->assertFalse($out['ocr_active']);
        $this->assertSame(ReadingMessages::noTextOcrInactive(), $out['notice']);
        $this->assertSame($out['notice'], $out['text']);
    }

    public function testReadRefusesAnUnknownVersion(): void {
        try {
            $this->tools->read($this->file(), '/Documentos/ata.md', 'nope', 'alice');
            $this->fail('an unknown revision must not resolve');
        } catch (ToolFailure $e) {
            $this->assertSame(VersionMessages::versionMissing('nope'), $e->getMessage());
        }
    }

    public function testReadReportsAVersionThatCannotBeOpened(): void {
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(VersionMessages::versionUnreadable());
        $this->tools->read($this->file(), '/Documentos/ata.md', '1759200000', 'alice');
    }

    public function testRestoreBacksUpThenRollsBack(): void {
        $out = $this->restorer->restore($this->tree->rootFolder(), $this->file(), '/Documentos/ata.md', '1759100000', 'alice');
        $this->assertSame(['path', 'size', 'etag', 'access', 'backup', 'version'], array_keys($out));
        $this->assertSame('1759100000', $out['version']);
        $this->assertStringStartsWith('/MCP backups/Documentos/ata.md.', $out['backup']);
        $this->assertSame('# Ata' . "\n" . 'olá', $this->tree->nodes['/alice/files' . $out['backup']]['content']);
        $this->assertSame(['mkdir /alice/files/MCP backups', 'mkdir /alice/files/MCP backups/Documentos',
            'create /alice/files' . $out['backup'], 'write /alice/files' . $out['backup']], $this->tree->ops);
        $this->assertContains('rollback', $this->manager->ops);
    }

    /** @return iterable<string, array{bool}> */
    public static function restoreModes(): iterable {
        yield 'rollback' => [false];
        yield 'locked write' => [true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('restoreModes')]
    public function testRestoreHookCannotPublishNewlyHiddenReceipt(bool $locked): void {
        $hidden = false;
        $mapper = $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class);
        $mapper->method('getTagIdsForObjects')->willReturnCallback(static function (array $ids) use (&$hidden): array {
            return array_fill_keys($ids, $hidden ? ['999'] : []);
        });
        $config = new InMemoryConfig();
        $config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["999"]';
        $guard = \OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this, $config->mock($this), $mapper, $this->tree->filesystemRoot());
        $this->assertTrue($guard->isVisible($this->file()));
        $this->manager->onRollback = static function () use (&$hidden): void { $hidden = true; };
        $this->tree->afterWrite = static function (string $path) use (&$hidden): void {
            if ($path === self::FILE) { $hidden = true; }
        };
        $this->manager->versions[1]->withContent('old');
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($this->manager);
        $locks = $locked ? (new FakeLockManager())->service($this) : null;
        $tools = new VersionRestorer(new VersionReader($this->apps, $this->users, $this->createMock(TextExtractor::class), new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager()), fn () => ($container)->get(VersionReader::MANAGER), new OcrSupport($this->apps)), $this->backup, new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager()), new SharedWriteGuard(new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager())), $guard, $locks);
        try {
            $tools->restore($this->tree->rootFolder(), $this->file(), '/Documentos/ata.md', '1759100000', 'alice');
            $this->fail('Restore published a hidden receipt');
        } catch (ToolFailure $e) {
            $this->assertSame(FileAccessMessages::notFound(), $e->getMessage());
        }
        $this->assertFalse($guard->isVisible($this->file()));
    }

    public function testRestoreReportsARollbackThatFailed(): void {
        $this->manager->rollbackResult = false;
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(VersionMessages::versionRestoreFailed());
        $this->restorer->restore($this->tree->rootFolder(), $this->file(), '/Documentos/ata.md', '1759100000', 'alice');
    }

    public function testRestoreWithoutVersioningNeverRollsBack(): void {
        $this->apps = $this->createMock(IAppManager::class);
        $this->apps->method('isEnabledForUser')->willReturn(false);
        $tools = new VersionRestorer(new VersionReader($this->apps, $this->users, $this->createMock(TextExtractor::class), new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager()), fn () => ($this->createMock(ContainerInterface::class))->get(VersionReader::MANAGER), new OcrSupport($this->apps)), $this->backup, new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager()), new SharedWriteGuard(new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager())), \OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this), null);
        try {
            $tools->restore($this->tree->rootFolder(), $this->file(), '/Documentos/ata.md', '1759100000', 'alice');
            $this->fail('a restore without versioning must not proceed');
        } catch (ToolFailure) {
        }
        $this->assertSame([], $this->manager->ops);
    }

    public function testRestoreRefusesAFileNextcloudWillNotUpdate(): void {
        $this->tree->nodes[self::FILE]['updateable'] = false;
        try {
            $this->restorer->restore($this->tree->rootFolder(), $this->file(), '/Documentos/ata.md', '1759100000', 'alice');
            $this->fail('a file Nextcloud will not update was restored');
        } catch (ToolFailure $e) {
            $this->assertSame(FileAccessMessages::forbidden(), $e->getMessage());
        }
        $this->assertNotContains('rollback', $this->manager->ops, 'a recusa vem antes de a versão sobrescrever o arquivo');
        $this->assertSame("# Ata\nolá", $this->tree->nodes[self::FILE]['content']);
    }

    /** @return VersionTools the tools with files_lock behind them, holding the given locks */
    private function lockedTools(FakeLockManager $locks): VersionRestorer {
        $config = (new InMemoryConfig())->mock($this);
        $time = $this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class);
        $time->method('getTime')->willReturn(1790000000);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(fn (string $id) => $id === self::MANAGER ? $this->manager : null);
        $service = $locks->service($this);
        return new VersionRestorer(new VersionReader($this->apps, $this->users, $this->createMock(TextExtractor::class), new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager()), fn () => ($container)->get(VersionReader::MANAGER), new OcrSupport($this->apps)), new FileBackup($this->apps, $this->users, $time, $config, \OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this), $service), new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager()), new SharedWriteGuard(new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager())), \OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this), $service);
    }

    /**
     * What VersionManager::rollback() does in Nextcloud 32 to 35 (handleAppLocks): the write refused by a lock of
     * Text or Office is caught and repeated inside that app's lock scope, where files_lock lets it through. The
     * double reproduces it: the first try meets the lock, the retry overwrites the file anyway.
     */
    private function rollbackRetriesInsideTheEditorScope(): void {
        $this->manager->onRollback = function (): void {
            try {
                throw new \OCP\Lock\ManuallyLockedException('/alice/files/Documentos/ata.md', null, 'files_lock/t', 'text', -1);
            } catch (\OCP\Lock\ManuallyLockedException) {
                $this->tree->ops[] = 'rollback in text scope';
                $this->tree->nodes[self::FILE]['content'] = 'versão antiga';
            }
        };
        $this->manager->versions[1]->withContent('versão antiga');
    }

    public function testARestoreOfAFileOpenInAnEditorIsRefusedBeforeTheBackupAndTheRollback(): void {
        $locks = new FakeLockManager();
        $locks->put(new FakeLock($this->tree->nodes[self::FILE]['id'], ILock::TYPE_APP, 'text'));
        $this->rollbackRetriesInsideTheEditorScope();
        try {
            $this->lockedTools($locks)->restore($this->tree->rootFolder(), $this->file(), '/Documentos/ata.md', '1759100000', 'alice');
            $this->fail('the restore of a locked file went on');
        } catch (LockWriteFailure $e) {
            $this->assertStringStartsWith('The file “/Documentos/ata.md” is locked by Text, usually because it is open there.', $e->getMessage());
        }
        $this->assertNotContains('rollback', $this->manager->ops);
        $this->assertSame([], $this->tree->ops);
        $this->assertSame("# Ata\nolá", $this->tree->nodes[self::FILE]['content']);
        $this->assertSame([], $locks->forbidden);
    }

    /**
     * The lock of the editor appears after the check, so only the storage sees it. The core's rollback would retry in
     * the editor's scope and overwrite the open document; the restore writes the version through the file instead,
     * which files_lock refuses, and the core rollback is never called.
     */
    public function testALockThatAppearsAfterTheCheckIsNeverWrittenOverByTheCoreRetry(): void {
        $locks = new FakeLockManager();
        $this->rollbackRetriesInsideTheEditorScope();
        $this->tree->writeFailure = new \OCP\Lock\ManuallyLockedException('/alice/files/Documentos/ata.md', null, 'files_lock/t', 'text', -1);
        try {
            $this->lockedTools($locks)->restore($this->tree->rootFolder(), $this->file(), '/Documentos/ata.md', '1759100000', 'alice');
            $this->fail('the restore under the editor lock was reported as a success');
        } catch (LockWriteFailure $e) {
            $this->assertStringContainsString('is locked, and Nextcloud does not say by whom.', $e->getMessage());
            $this->assertMatchesRegularExpression('#The original is kept in /MCP backups/Documentos/ata\.md\.[0-9-]+\.bak\.$#', $e->getMessage());
        }
        $this->assertNotContains('rollback', $this->manager->ops);
        $this->assertNotContains('rollback in text scope', $this->tree->ops);
        $this->assertSame("# Ata\nolá", $this->tree->nodes[self::FILE]['content']);
    }

    /** With files_lock there, the version is read through files_versions and written through the file: a new version of the current content comes first, as with any edit. */
    public function testWithALockProviderTheVersionIsWrittenThroughTheFile(): void {
        $this->rollbackRetriesInsideTheEditorScope();
        $out = $this->lockedTools(new FakeLockManager())->restore($this->tree->rootFolder(), $this->file(), '/Documentos/ata.md', '1759100000', 'alice');
        $this->assertSame('versão antiga', $this->tree->nodes[self::FILE]['content']);
        $this->assertSame(['list', 'read'], $this->manager->ops);
        $this->assertContains('write ' . self::FILE, $this->tree->ops);
        $this->assertSame("# Ata\nolá", $this->tree->nodes['/alice/files' . $out['backup']]['content']);
        $this->assertSame('1759100000', $out['version']);
    }

    public function testAVersionThatCannotBeReadIsNotRestored(): void {
        try {
            $this->lockedTools(new FakeLockManager())->restore($this->tree->rootFolder(), $this->file(), '/Documentos/ata.md', '1759100000', 'alice');
            $this->fail('an unreadable version was restored');
        } catch (ToolFailure $e) {
            $this->assertSame(VersionMessages::versionRestoreFailed(), $e->getMessage());
        }
        $this->assertSame("# Ata\nolá", $this->tree->nodes[self::FILE]['content']);
    }

    /**
     * The version file must not be open while the file is written (files_lock cannot raise the lock the write needs
     * otherwise): the restore copies the version, closes its stream, and writes the copy from its first byte.
     */
    public function testTheVersionStreamIsClosedBeforeTheFileIsWritten(): void {
        $version = $this->manager->versions[1]->withContent('versão antiga');
        $seen = [];
        $this->tree->beforeWrite = function (string $path, mixed $data) use ($version, &$seen): void {
            if ($path === self::FILE) {
                $seen = ['versionStreamClosed' => !is_resource($version->handles[0]), 'dataIsOtherStream' => is_resource($data)
                    && get_resource_id($data) !== get_resource_id($version->handles[0])];
            }
        };
        $this->lockedTools(new FakeLockManager())->restore($this->tree->rootFolder(), $this->file(), '/Documentos/ata.md', '1759100000', 'alice');
        $this->assertSame(['versionStreamClosed' => true, 'dataIsOtherStream' => true], $seen);
        $this->assertSame('versão antiga', $this->tree->nodes[self::FILE]['content'], 'the copy is written from its first byte');
        $this->assertCount(1, $version->handles, 'the version is read once');
    }

    /** A version that cannot be copied is never written over the file, and its stream is closed. */
    public function testAVersionThatCannotBeCopiedLeavesTheFileUntouched(): void {
        $version = $this->manager->versions[1];
        $version->failingRead = true;
        try {
            $this->lockedTools(new FakeLockManager())->restore($this->tree->rootFolder(), $this->file(), '/Documentos/ata.md', '1759100000', 'alice');
            $this->fail('a version that could not be copied was restored');
        } catch (ToolFailure $e) {
            $this->assertSame(VersionMessages::versionRestoreFailed(), $e->getMessage());
        }
        $this->assertNotContains('write ' . self::FILE, $this->tree->ops, 'nothing was written over the file');
        $this->assertSame("# Ata\nolá", $this->tree->nodes[self::FILE]['content']);
        $this->assertFalse(is_resource($version->handles[0]), 'the stream of the version is closed');
    }

    /** A lock that refuses the write still tells where the original is kept, and no stream of the version stays open. */
    public function testALockRefusalStillCarriesTheNoteAndLeavesNoStreamOpen(): void {
        $version = $this->manager->versions[1]->withContent('versão antiga');
        $this->tree->writeFailure = new \OCP\Lock\ManuallyLockedException('/alice/files/Documentos/ata.md', null, 'files_lock/t', 'text', -1);
        try {
            $this->lockedTools(new FakeLockManager())->restore($this->tree->rootFolder(), $this->file(), '/Documentos/ata.md', '1759100000', 'alice');
            $this->fail('the restore under the lock was reported as a success');
        } catch (LockWriteFailure $e) {
            $this->assertMatchesRegularExpression('#The original is kept in /MCP backups/Documentos/ata\.md\.[0-9-]+\.bak\.$#', $e->getMessage());
        }
        $this->assertSame("# Ata\nolá", $this->tree->nodes[self::FILE]['content']);
        $this->assertFalse(is_resource($version->handles[0]));
    }

    public function testTheOwnManualLockLetsTheRestoreThrough(): void {
        $locks = new FakeLockManager();
        $locks->put(new FakeLock($this->tree->nodes[self::FILE]['id'], ILock::TYPE_USER, 'alice'));
        $this->rollbackRetriesInsideTheEditorScope();
        $this->lockedTools($locks)->restore($this->tree->rootFolder(), $this->file(), '/Documentos/ata.md', '1759100000', 'alice');
        $this->assertSame('versão antiga', $this->tree->nodes[self::FILE]['content']);
    }

    /** Without a lock provider no editor lock can exist, and the restore stays the core rollback it always was. */
    public function testWithoutALockProviderTheCoreRollbackStillRestores(): void {
        $locks = new FakeLockManager();
        $locks->available = false;
        $this->lockedTools($locks)->restore($this->tree->rootFolder(), $this->file(), '/Documentos/ata.md', '1759100000', 'alice');
        $this->assertContains('rollback', $this->manager->ops);
        $this->assertNotContains('read', $this->manager->ops);
    }

    /** Turns the rollback into what the real one does: the file now holds the version. */
    private function rollbackOverwritesTheFile(): void {
        $this->manager->onRollback = function (): void {
            $this->tree->ops[] = 'rollback';
            $this->tree->nodes[self::FILE]['content'] = 'versão antiga';
        };
    }

    /**
     * The point of the restore: the backup keeps what the file held before the version overwrote it. A rollback
     * that ran first would leave a "backup" of the restored version and lose the current content for good.
     */
    public function testTheBackupHoldsTheCurrentContentBecauseItIsTakenBeforeTheRollback(): void {
        $this->rollbackOverwritesTheFile();
        $out = $this->restorer->restore($this->tree->rootFolder(), $this->file(), '/Documentos/ata.md', '1759100000', 'alice');
        $this->assertSame("# Ata\nolá", $this->tree->nodes['/alice/files' . $out['backup']]['content'], 'o conteúdo de antes está no backup');
        $this->assertSame('versão antiga', $this->tree->nodes[self::FILE]['content']);
        $this->assertSame(['write /alice/files' . $out['backup'], 'rollback'], array_slice($this->tree->ops, -2), 'o backup antes da versão');
    }

    /** A backup that could not be written stops the restore before the version touches the file. */
    public function testARestoreWhoseBackupFailsNeverRollsBack(): void {
        $this->rollbackOverwritesTheFile();
        $this->tree->failBackupWrite = true;
        try {
            $this->restorer->restore($this->tree->rootFolder(), $this->file(), '/Documentos/ata.md', '1759100000', 'alice');
            $this->fail('a restore without its backup went on');
        } catch (ToolFailure) {
        }
        $this->assertNotContains('rollback', $this->manager->ops);
        $this->assertSame("# Ata\nolá", $this->tree->nodes[self::FILE]['content']);
    }
}
