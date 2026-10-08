<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Common;

use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
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
        $this->tree = new FakeTree($this);
        $this->access = new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager());
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

    /**
     * IShare::getSharedBy() is documented as returning the sharer's UID string in stable33
     * (lib/public/Share/IShare.php:440), and this test feeds the description exactly that: a string, never
     * an IUser. Calling getDisplayName() on it is the TypeError that shipped; FakeTree's shared storage is
     * a real ISharedStorage so the branch is live instead of unreachable.
     */
    public function testSharedByIsResolvedFromTheUidStringTheShareReturns(): void {
        $this->tree->addFile('/alice/files/Compartilhado/plano.md', 'x', 'text/markdown', ['scope' => 'shared']);
        $share = $this->tree->shareOf('/alice/files/Compartilhado/plano.md');
        $this->assertSame('pedro', $share->getSharedBy(), 'IShare::getSharedBy() must be a string in this fixture');
        $this->assertSame('Pedro Almeida', $this->access->describe($this->tree->node('/alice/files/Compartilhado/plano.md'), 'alice')['sharedBy']);
    }

    /**
     * A share mount jails the shared storage, so the node holds a wrapper that is not an ISharedStorage.
     * instanceOfStorage() sees through it; instanceof does not, and the sharer has to come from the share
     * manager. The FakeTree reproduces exactly that shape.
     */
    public function testAWrappedShareStorageIsStillRecognised(): void {
        $this->tree->addFile('/alice/files/Compartilhado/plano.md', 'x', 'text/markdown', ['scope' => 'shared']);
        $storage = $this->tree->node('/alice/files/Compartilhado/plano.md')->getStorage();
        $this->assertNotInstanceOf(\OCP\Files\Storage\ISharedStorage::class, $storage, 'o fixture tem que embrulhar');
        $this->assertTrue($storage->instanceOfStorage(\OCP\Files\Storage\ISharedStorage::class));
        $this->assertSame('Pedro Almeida', $this->access->describe($this->tree->node('/alice/files/Compartilhado/plano.md'), 'alice')['sharedBy']);
    }

    /** A share whose sharer no longer exists must degrade to the UID, never crash and never stay empty. */
    public function testSharedByFallsBackToTheUidWhenTheUserIsGone(): void {
        $this->tree->addFile('/alice/files/Compartilhado/plano.md', 'x', 'text/markdown',
            ['scope' => 'shared', 'sharedBy' => 'fantasma']);
        $this->assertSame('fantasma', $this->access->describe($this->tree->node('/alice/files/Compartilhado/plano.md'), 'alice')['sharedBy']);
    }

    /** No sharer at all is not a deleted account, just a share that answers nothing: neutral wording. */
    public function testAShareWithoutASharerIsNamedNeutrally(): void {
        $this->tree->addFile('/alice/files/Compartilhado/plano.md', 'x', 'text/markdown',
            ['scope' => 'shared', 'sharedBy' => null]);
        $info = $this->access->describe($this->tree->node('/alice/files/Compartilhado/plano.md'), 'alice');
        $this->assertSame('shared', $info['scope']);
        $this->assertSame(CommonMessages::UNIDENTIFIED, $info['sharedBy']);
    }

    /**
     * No pt-BR sentence may live outside a Messages class, because IL10N will have to find every one of
     * them. This is the assertion that keeps SharedWriteGuard and NodeAccessInfo honest.
     */
    public function testNoUserFacingSentenceLivesOutsideTheMessagesClass(): void {
        foreach (['NodeAccessInfo', 'SharedWriteGuard'] as $class) {
            $source = (string)file_get_contents(__DIR__ . '/../../../../lib/Tools/Common/' . $class . '.php');
            preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $source, $matches);
            foreach ($matches[1] as $literal) {
                $this->assertDoesNotMatchRegularExpression('/[áàâãéêíóôõúç]/u', $literal,
                    "$class has a Portuguese sentence outside CommonMessages: '$literal'");
            }
        }
    }

    /** The description must never carry anything the client could use to reach the file another way. */
    public function testDescriptionCarriesNoShareToken(): void {
        $this->tree->addFile('/alice/files/Compartilhado/plano.md', 'x', 'text/markdown', ['scope' => 'shared']);
        $encoded = json_encode($this->access->describe($this->tree->node('/alice/files/Compartilhado/plano.md'), 'alice'), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('shareToken', $encoded);
        $this->assertStringNotContainsString('oc_share', $encoded);
    }
}
