<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Contacts;

use DateTimeImmutable;
use OCA\Mcp\Tools\Contacts\ContactBackup;
use OCA\Mcp\Tools\ToolFailure;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

/** Verifies full vCard backups, collision handling and failure before contact deletion. */
final class ContactBackupTest extends TestCase {
    private const CARD = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Name\r\nUID:c1\r\nX-SECRET:retain\r\nEND:VCARD\r\n";
    private ContactBackup $backup;
    private bool $collision = false;
    private int $attempt = 0;
    private Folder $folder;
    private File $copy;
    private ILockingProvider $locks;

    protected function setUp(): void {
        $roots = $this->createMock(IRootFolder::class);
        $root = $this->createMock(Folder::class);
        $this->folder = $this->createMock(Folder::class);
        $this->copy = $this->createMock(File::class);
        $roots->method('getUserFolder')->with('alice')->willReturn($root);
        $root->method('nodeExists')->willReturn(true);
        $root->method('get')->willReturn($this->folder);
        $this->folder->method('nodeExists')->willReturnCallback(
            function ($name) {
                if (!str_ends_with($name, '.vcf')) {
                    return true;
                }
                return $this->collision && ++$this->attempt === 1;
            }
        );
        $this->folder->method('get')->willReturn($this->folder);
        $this->folder->method('getPath')->willReturn('/alice/files/MCP backups/Contacts/Team');
        $this->copy->method('getPath')->willReturn('/alice/files/MCP backups/Contacts/Team/copy.vcf');
        $root->method('getRelativePath')->willReturn('MCP backups/Contacts/Team/copy.vcf');
        $time = $this->createMock(ITimeFactory::class);
        $time->method('now')->willReturn(new DateTimeImmutable('2026-10-01T12:00:00Z'));
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturn('America/Sao_Paulo');
        $this->locks = $this->createMock(ILockingProvider::class);
        $this->backup = new ContactBackup($roots, $time, $config, $this->locks);
    }

    public function testFullVcardIsReadBackAndUniqueNameUsesAccountTimezone(): void {
        $this->copy->method('getContent')->willReturn(self::CARD);
        $this->folder->expects(self::once())->method('newFile')->with(
            self::matchesRegularExpression('/^Name\.20261001-090000-[a-f0-9]{16}\.vcf$/'),
            self::CARD
        )->willReturn($this->copy);
        $this->locks->expects(self::once())->method('acquireLock')->with(
            self::stringStartsWith('mcp-contact-backup/'),
            ILockingProvider::LOCK_EXCLUSIVE
        );
        $this->locks->expects(self::once())->method('releaseLock');
        self::assertSame(
            '/MCP backups/Contacts/Team/copy.vcf',
            $this->backup->save('alice', 'Team', 'Name', self::CARD)
        );
    }

    public function testVerificationFailureFailsClosedAndReleasesMutex(): void {
        $this->copy->method('getContent')->willReturn('truncated');
        $this->folder->method('newFile')->willReturn($this->copy);
        $this->locks->expects(self::once())->method('releaseLock');
        $this->expectException(ToolFailure::class);
        $this->backup->save('alice', 'Team', 'Name', self::CARD);
    }

    public function testUnsafeNamesCannotEscapeBackupDirectory(): void {
        self::assertSame(
            '/MCP backups/Contacts/_.._etc_passwd',
            $this->backup->directory('/../etc/passwd')
        );
        self::assertSame('/MCP backups/Contacts/contact', $this->backup->directory('..'));
    }

    public function testCollisionUsesAnotherNameAndNeverOverwrites(): void {
        $this->copy->method('getContent')->willReturn(self::CARD);
        $this->collision = true;
        $this->folder->expects(self::once())
            ->method('newFile')
            ->with(self::matchesRegularExpression('/-2\.vcf$/'), self::CARD)
            ->willReturn($this->copy);
        $this->backup->save('alice', 'Team', 'Name', self::CARD);
    }
}
