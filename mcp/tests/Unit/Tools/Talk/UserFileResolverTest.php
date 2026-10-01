<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Talk;

use InvalidArgumentException;
use OCA\Mcp\Tools\Talk\FileAccessException;
use OCA\Mcp\Tools\Talk\Messages;
use OCA\Mcp\Tools\Talk\UserFileResolver;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class UserFileResolverTest extends TestCase {
    private IRootFolder&MockObject $rootFolder;
    private Folder&MockObject $userFolder;
    private UserFileResolver $resolver;

    protected function setUp(): void {
        parent::setUp();
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->userFolder = $this->createMock(Folder::class);
        $this->rootFolder->method('getUserFolder')->with('alice')->willReturn($this->userFolder);
        $this->resolver = new UserFileResolver($this->rootFolder);
    }

    public function testFileOfTheUserFolderIsResolvedWithThePathTheClientSent(): void {
        $file = $this->givenFile(Constants::PERMISSION_READ | Constants::PERMISSION_SHARE);

        $this->assertSame($file, $this->resolver->resolveShareableFile('alice', 'Documentos/relatorio.pdf'));
    }

    public function testLeadingAndDuplicatedSlashesAreNormalized(): void {
        $file = $this->givenFile(Constants::PERMISSION_READ | Constants::PERMISSION_SHARE);
        $this->userFolder->expects($this->once())->method('get')->with('Documentos/relatorio.pdf')->willReturn($file);

        $this->assertSame($file, $this->resolver->resolveShareableFile('alice', '//Documentos//relatorio.pdf'));
    }

    public function testOnlyTheUserOwnFolderIsConsulted(): void {
        $this->givenFile(Constants::PERMISSION_READ | Constants::PERMISSION_SHARE);
        $this->rootFolder->expects($this->once())->method('getUserFolder')->with('alice');

        $this->resolver->resolveShareableFile('alice', 'relatorio.pdf');
    }

    /**
     * A relative segment never reaches the filesystem: the check happens before the lookup.
     *
     * @dataProvider malformedPaths
     */
    public function testMalformedPathIsRefusedBeforeAnyFilesystemAccess(string $path): void {
        $this->rootFolder->expects($this->never())->method('getUserFolder');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Messages::invalidPath());
        $this->resolver->resolveShareableFile('alice', $path);
    }

    public static function malformedPaths(): array {
        return [
            'empty' => [''],
            'blank' => ['   '],
            'slash only' => ['///'],
            'parent segment' => ['../outro/usuario/relatorio.pdf'],
            'parent segment in the middle' => ['Documentos/../../outro/relatorio.pdf'],
            'current directory segment' => ['./relatorio.pdf'],
            'null byte' => ["relatorio.pdf\0.png"],
            'backslash' => ['Documentos\\relatorio.pdf'],
            'trailing parent' => ['Documentos/..'],
        ];
    }

    public function testMissingFileIsRefusedAsIfItDidNotExist(): void {
        $this->userFolder->method('get')->willThrowException(new NotFoundException('nope'));

        $this->expectException(FileAccessException::class);
        $this->expectExceptionMessage(Messages::fileNotFound());
        $this->resolver->resolveShareableFile('alice', 'relatorio.pdf');
    }

    public function testFolderIsRefusedBecauseItCannotBeAttached(): void {
        $this->userFolder->method('get')->willReturn($this->createMock(Folder::class));

        $this->expectException(FileAccessException::class);
        $this->expectExceptionMessage(Messages::fileNotFound());
        $this->resolver->resolveShareableFile('alice', 'Documentos');
    }

    public function testUnreadableFileIsRefused(): void {
        $this->givenFile(Constants::PERMISSION_SHARE, false);

        $this->expectException(FileAccessException::class);
        $this->expectExceptionMessage(Messages::fileNotFound());
        $this->resolver->resolveShareableFile('alice', 'relatorio.pdf');
    }

    public function testFileTheUserCannotShareIsRefused(): void {
        $this->givenFile(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);

        $this->expectException(FileAccessException::class);
        $this->expectExceptionMessage(Messages::fileNotFound());
        $this->resolver->resolveShareableFile('alice', 'relatorio.pdf');
    }

    public function testDeniedLookupLooksLikeAMissingFile(): void {
        // A denied node and an absent one are the same answer, so the tool cannot be used to probe storage.
        $this->userFolder->method('get')->willThrowException(new NotPermittedException('storage is locked'));

        try {
            $this->resolver->resolveShareableFile('alice', 'relatorio.pdf');
            $this->fail('A denied lookup must be refused.');
        } catch (FileAccessException $e) {
            $this->assertSame(Messages::fileNotFound(), $e->getMessage());
            $this->assertStringNotContainsString('locked', $e->getMessage());
        }
    }

    /**
     * @param int $permissions Permissions reported by the node
     * @param bool $readable Whether the node says it can be read
     * @return File&MockObject The node the user folder returns
     */
    private function givenFile(int $permissions, bool $readable = true): File&MockObject {
        $file = $this->createMock(File::class);
        $file->method('getPermissions')->willReturn($permissions);
        $file->method('isReadable')->willReturn($readable);
        $this->userFolder->method('get')->willReturn($file);

        return $file;
    }
}
