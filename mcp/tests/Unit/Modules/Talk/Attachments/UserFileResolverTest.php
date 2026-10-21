<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Talk\Attachments;

use InvalidArgumentException;
use OCA\Mcp\Modules\Talk\Attachments\FileAccessException;
use OCA\Mcp\Modules\Talk\Attachments\UserFileResolver;
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
        $this->resolver = new UserFileResolver($this->rootFolder, \OCA\Mcp\Tests\Support\Platform\FileAccess\References\OutputReferenceFilters::openGuard($this), \OCA\Mcp\Tests\Support\Platform\FileAccess\References\OutputReferenceFilters::inactive($this));
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
        $this->expectExceptionMessage(\OCA\Mcp\Modules\Talk\Attachments\AttachmentMessages::invalidPath());
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
        $this->expectExceptionMessage(\OCA\Mcp\Modules\Talk\Attachments\AttachmentMessages::fileNotFound());
        $this->resolver->resolveShareableFile('alice', 'relatorio.pdf');
    }

    public function testFolderIsRefusedBecauseItCannotBeAttached(): void {
        $this->userFolder->method('get')->willReturn($this->createMock(Folder::class));

        $this->expectException(FileAccessException::class);
        $this->expectExceptionMessage(\OCA\Mcp\Modules\Talk\Attachments\AttachmentMessages::fileNotFound());
        $this->resolver->resolveShareableFile('alice', 'Documentos');
    }

    public function testUnreadableFileIsRefused(): void {
        $this->givenFile(Constants::PERMISSION_SHARE, false);

        $this->expectException(FileAccessException::class);
        $this->expectExceptionMessage(\OCA\Mcp\Modules\Talk\Attachments\AttachmentMessages::fileNotFound());
        $this->resolver->resolveShareableFile('alice', 'relatorio.pdf');
    }

    public function testFileTheUserCannotShareIsRefused(): void {
        $this->givenFile(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);

        $this->expectException(FileAccessException::class);
        $this->expectExceptionMessage(\OCA\Mcp\Modules\Talk\Attachments\AttachmentMessages::fileNotFound());
        $this->resolver->resolveShareableFile('alice', 'relatorio.pdf');
    }

    public function testDeniedLookupLooksLikeAMissingFile(): void {
        // A denied node and an absent one are the same answer, so the tool cannot be used to probe storage.
        $this->userFolder->method('get')->willThrowException(new NotPermittedException('storage is locked'));

        try {
            $this->resolver->resolveShareableFile('alice', 'relatorio.pdf');
            $this->fail('A denied lookup must be refused.');
        } catch (FileAccessException $e) {
            $this->assertSame(\OCA\Mcp\Modules\Talk\Attachments\AttachmentMessages::fileNotFound(), $e->getMessage());
            $this->assertStringNotContainsString('locked', $e->getMessage());
        }
    }

    /**
     * @param int $permissions Permissions reported by the node
     * @param bool $readable Whether the node says it can be read
     * @return File&MockObject The node the user folder returns
     */
    public function testHiddenFileIsRefusedAsNotFound(): void {
        $file = $this->givenFile(Constants::PERMISSION_READ | Constants::PERMISSION_SHARE);
        $file->method('getId')->willReturn(12345);
        $owner = $this->createMock(\OCP\IUser::class);
        $owner->method('getUID')->willReturn('alice');
        $file->method('getOwner')->willReturn($owner);
        $file->method('getParent')->willReturn($this->userFolder);
        $this->userFolder->method('getId')->willReturn(1);
        $this->userFolder->method('getById')->with(12345)->willReturn([$file]);
        $config = new \OCA\Mcp\Tests\Unit\InMemoryConfig();
        $config->app['mcp'][\OCA\Mcp\Access\Visibility\VisibilityGuard::CONFIG_KEY] = json_encode(['999']);
        $tagMapper = $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class);
        $tagMapper->method('getTagIdsForObjects')->willReturn(['12345' => ['999']]);
        $guard = new \OCA\Mcp\Access\Visibility\VisibilityGuard($config->mock($this), $tagMapper, $this->rootFolder);
        $resolver = new UserFileResolver($this->rootFolder, $guard, \OCA\Mcp\Tests\Support\Platform\FileAccess\References\OutputReferenceFilters::inactive($this));

        $this->expectException(FileAccessException::class);
        $this->expectExceptionMessage(\OCA\Mcp\Modules\Talk\Attachments\AttachmentMessages::fileNotFound());
        $resolver->resolveShareableFile('alice', 'relatorio.pdf');
    }

    public static function guestStorageCases(): array {
        return [
            'hidden external' => ['external', true, false],
            'hidden external subtype' => ['external::local', true, false],
            'internal control' => ['home', true, true],
            'external allowed control' => ['external', false, true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('guestStorageCases')]
    public function testGuestsStorageRestrictionsApplyBeforePublishingATalkAttachment(string $mountType, bool $hidden, bool $allowed): void {
        $file = $this->givenFile(Constants::PERMISSION_READ | Constants::PERMISSION_SHARE);
        $file->method('getId')->willReturn(12345);
        $this->userFolder->method('getById')->with(12345)->willReturn([$file]);
        $mount = $this->createMock(\OCP\Files\Mount\IMountPoint::class);
        $mount->method('getMountType')->willReturn($mountType);
        $file->method('getMountPoint')->willReturn($mount);
        $guests = $this->getMockBuilder(\OCA\Mcp\Access\Guests\GuestRestrictions::class)
            ->disableOriginalConstructor()->onlyMethods(['hidesExternalStorage'])->getMock();
        $guests->method('hidesExternalStorage')->with('alice')->willReturn($hidden);
        $guard = \OCA\Mcp\Tests\Support\Platform\FileAccess\References\OutputReferenceFilters::openGuard($this);
        $filter = new \OCA\Mcp\Platform\FileAccess\References\OutputReferenceFilter($this->rootFolder,
            $this->createMock(\OCP\Share\IManager::class), $this->createMock(\OCP\IConfig::class),
            $this->createMock(\OCP\IURLGenerator::class), $guard,
            new \OCA\Mcp\Tests\Support\Platform\FileAccess\References\UnrestrictedFileReferenceScope(), $guests);
        $resolver = new UserFileResolver($this->rootFolder, $guard, $filter);
        if (!$allowed) {
            $this->expectException(FileAccessException::class);
            $this->expectExceptionMessage(\OCA\Mcp\Modules\Talk\Attachments\AttachmentMessages::fileNotFound());
        }
        self::assertSame($file, $resolver->resolveShareableFile('alice', 'external.pdf'));
    }

    private function givenFile(int $permissions, bool $readable = true): File&MockObject {
        $file = $this->createMock(File::class);
        $file->method('getPermissions')->willReturn($permissions);
        $file->method('isReadable')->willReturn($readable);
        $this->userFolder->method('get')->willReturn($file);

        return $file;
    }
}
