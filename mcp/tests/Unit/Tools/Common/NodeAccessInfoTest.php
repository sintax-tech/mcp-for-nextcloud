<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Common;

use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use PHPUnit\Framework\TestCase;

/**
 * Ownership awareness: which scope a node lives in, who owns it and what the viewer may do. Every scope
 * is reached through the OCP interfaces a real mount exposes, never through an app class.
 */
final class NodeAccessInfoTest extends TestCase {
    private NodeAccessInfo $access;
    private FakeTree $tree;

    protected function setUp(): void {
        $this->access = new NodeAccessInfo();
        $this->tree = new FakeTree($this);
    }

    public function testPersonalFileIsOwnedByTheViewerWithEveryPermission(): void {
        $this->tree->addFile('/alice/files/Documentos/ata.md', 'x', 'text/markdown');
        $this->assertSame([
            'scope' => 'personal',
            'owner' => 'alice',
            'ownerDisplayName' => 'Alice',
            'permissions' => ['read' => true, 'update' => true, 'create' => true, 'delete' => true, 'share' => true],
        ], $this->access->describe($this->tree->node('/alice/files/Documentos/ata.md'), 'alice'));
    }

    public function testSharedFileNamesWhoSharedItAndTheOwner(): void {
        $this->tree->sharedByName = 'Pedro Almeida';
        $this->tree->addFile('/alice/files/Compartilhado/plano.md', 'x', 'text/markdown', ['scope' => 'shared']);
        $info = $this->access->describe($this->tree->node('/alice/files/Compartilhado/plano.md'), 'alice');
        $this->assertSame('shared', $info['scope']);
        $this->assertSame('Pedro Almeida', $info['sharedBy']);
        $this->assertSame('pedro', $info['owner']);
        $this->assertArrayNotHasKey('teamFolder', $info);
    }

    public function testTeamFolderIsNamedFromTheMountPoint(): void {
        $this->tree->mountPath = '/alice/files/Engenharia';
        $this->tree->addFile('/alice/files/Engenharia/especificacao.md', 'x', 'text/markdown', ['scope' => 'team']);
        $info = $this->access->describe($this->tree->node('/alice/files/Engenharia/especificacao.md'), 'alice');
        $this->assertSame('team', $info['scope']);
        $this->assertSame('Engenharia', $info['teamFolder']);
        $this->assertArrayNotHasKey('sharedBy', $info);
    }

    public function testExternalStorageIsItsOwnScope(): void {
        $this->tree->addFile('/alice/files/Externo/dados.md', 'x', 'text/markdown', ['scope' => 'external']);
        $info = $this->access->describe($this->tree->node('/alice/files/Externo/dados.md'), 'alice');
        $this->assertSame('external', $info['scope']);
        $this->assertArrayNotHasKey('teamFolder', $info);
        $this->assertArrayNotHasKey('sharedBy', $info);
    }

    public function testPermissionBitmaskIsDecoded(): void {
        $this->tree->addFile('/alice/files/Documentos/ata.md', 'x', 'text/markdown',
            ['permissions' => \OCP\Constants::PERMISSION_READ | \OCP\Constants::PERMISSION_SHARE]);
        $info = $this->access->describe($this->tree->node('/alice/files/Documentos/ata.md'), 'alice');
        $this->assertSame(['read' => true, 'update' => false, 'create' => false, 'delete' => false, 'share' => true],
            $info['permissions']);
    }

    /**
     * A node whose mount reports nothing still gets a scope, decided by its storage and its owner. A
     * home file the viewer owns stays personal even when it is shared OUT to someone else, which is why
     * the storage wins over isShared().
     */
    public function testWithoutAMountTypeTheStorageAndOwnerDecide(): void {
        $node = $this->bare('personal', true);
        $this->assertSame('personal', $this->access->describe($node, 'alice')['scope']);
        $this->assertSame('shared', $this->access->describe($this->bare('shared', true), 'alice')['scope']);
        // A storage that is neither home nor a share, and a node nobody owns, fall back to personal.
        $this->assertSame('personal', $this->access->describe($this->bare('external', false), 'alice')['scope']);
    }

    /**
     * @param string $scope scope the fake storage reports as home storage
     * @param bool $shared what Node::isShared() answers
     * @return \OCP\Files\File a node with a mount that reports no type
     */
    private function bare(string $scope, bool $shared): \OCP\Files\File {
        $mount = $this->createMock(\OCP\Files\Mount\IMountPoint::class);
        $mount->method('getMountType')->willReturn(null);
        $storage = $this->createMock(\OCP\Files\Storage\IStorage::class);
        $storage->method('instanceOfStorage')->willReturnCallback(fn (string $class) => $class === \OCP\Files\IHomeStorage::class && $scope === 'personal');
        $owner = $this->createMock(\OCP\IUser::class);
        $owner->method('getUID')->willReturn('alice');
        $owner->method('getDisplayName')->willReturn('Alice');
        $file = $this->createMock(\OCP\Files\File::class);
        $file->method('getMountPoint')->willReturn($mount);
        $file->method('getOwner')->willReturn($owner);
        $file->method('getStorage')->willReturn($storage);
        $file->method('isShared')->willReturn($shared);
        $file->method('getPermissions')->willReturn(\OCP\Constants::PERMISSION_ALL);
        return $file;
    }

    /** The description must never carry anything the client could use to reach the file another way. */
    public function testDescriptionCarriesNoShareToken(): void {
        $this->tree->addFile('/alice/files/Compartilhado/plano.md', 'x', 'text/markdown', ['scope' => 'shared']);
        $encoded = json_encode($this->access->describe($this->tree->node('/alice/files/Compartilhado/plano.md'), 'alice'), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('shareToken', $encoded);
        $this->assertStringNotContainsString('oc_share', $encoded);
    }
}
