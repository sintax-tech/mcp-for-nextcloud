<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files\Sharing;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\VisibilityGuard;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\Files\FilesMessages;
use OCA\Mcp\Tools\Files\Sharing\ShareAccess;
use OCA\Mcp\Tools\Files\Sharing\ShareRecipient;
use OCA\Mcp\Tools\ToolFailure;
use OCP\Files\Node;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

/** Which node may be shared, which shares a user sees and may remove, and which grant each type needs. */
final class ShareAccessTest extends TestCase {
    private FakeTree $tree;
    private FakeShares $shares;
    private GrantPolicy $policy;
    /** @var list<int> node ids the visibility guard hides */
    private array $hidden = [];
    private ShareAccess $access;

    protected function setUp(): void {
        $this->tree = new FakeTree($this);
        $this->tree->addFile('/alice/files/Relatorio.pdf', 'pdf', 'application/pdf');
        $this->tree->addFolder('/alice/files/Projetos');
        $this->tree->addFile('/alice/files/Recebido.pdf', 'pdf', 'application/pdf', ['scope' => 'shared']);
        $this->shares = new FakeShares($this);
        $this->policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
        $guard = $this->createMock(VisibilityGuard::class);
        $guard->method('isVisible')->willReturnCallback(fn (Node $n): bool => !in_array($n->getId(), $this->hidden, true));
        $guard->method('assertVisible')->willReturnCallback(function (Node $n): void {
            if (in_array($n->getId(), $this->hidden, true)) {
                throw new ToolFailure(CommonMessages::notFound());
            }
        });
        $this->access = new ShareAccess($this->shares->manager(), $this->policy, $guard);
    }

    private function id(string $path): int {
        return $this->tree->nodes['/alice/files' . $path]['id'];
    }

    private function failure(callable $call): string {
        try {
            $call();
        } catch (ToolFailure $e) {
            return $e->getMessage();
        }
        self::fail('no ToolFailure');
    }

    public function testOwnNodeReturnsAFileOfTheUser(): void {
        $node = $this->access->ownNode($this->tree->rootFolder(), 'alice', '/Relatorio.pdf');
        self::assertSame($this->id('/Relatorio.pdf'), $node->getId());
    }

    public function testANodeOfAnotherOwnerIsRefused(): void {
        self::assertSame(FilesMessages::shareNotOwner(),
            $this->failure(fn () => $this->access->ownNode($this->tree->rootFolder(), 'alice', '/Recebido.pdf')));
    }

    public function testAHiddenNodeIsNotFound(): void {
        $this->hidden[] = $this->id('/Relatorio.pdf');
        self::assertSame(CommonMessages::notFound(),
            $this->failure(fn () => $this->access->ownNode($this->tree->rootFolder(), 'alice', '/Relatorio.pdf')));
    }

    public function testAMissingNodeIsNotFound(): void {
        self::assertSame(CommonMessages::notFound(),
            $this->failure(fn () => $this->access->ownNode($this->tree->rootFolder(), 'alice', '/nada.txt')));
    }

    public function testTheRootFolderIsRefused(): void {
        self::assertSame(FilesMessages::shareRootRefused(),
            $this->failure(fn () => $this->access->ownNode($this->tree->rootFolder(), 'alice', '/')));
    }

    public function testATraversalIsAnArgumentError(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->access->ownNode($this->tree->rootFolder(), 'alice', '/../bob/files/x');
    }

    public function testSharesOfAskEveryListedTypeForThatNodeOnlyAsInitiator(): void {
        $node = $this->tree->node('/alice/files/Relatorio.pdf');
        $mine = $this->shares->add(['node' => $node->getId()]);
        $link = $this->shares->add(['node' => $node->getId(), 'type' => IShare::TYPE_LINK, 'with' => null]);
        $this->shares->add(['node' => $this->id('/Projetos')]);
        $this->shares->add(['node' => $node->getId(), 'by' => 'bruno']);

        self::assertSame([$mine, $link], $this->access->sharesOf('alice', $node));
        self::assertSame(ShareAccess::LISTED_TYPES, array_column($this->shares->calls, 'type'));
        foreach ($this->shares->calls as $call) {
            self::assertSame(['alice', $node->getId(), false, -1], [$call['uid'], $call['node'], $call['reshares'], $call['limit']]);
        }
    }

    /** Talk off means no room provider; the other types are still listed. */
    public function testAMissingRoomProviderIsNotAnError(): void {
        $node = $this->tree->node('/alice/files/Relatorio.pdf');
        $mine = $this->shares->add(['node' => $node->getId()]);
        $this->shares->roomUnavailable = true;
        self::assertSame([$mine], $this->access->sharesOf('alice', $node));
    }

    public function testFindExistingMatchesTheRecipient(): void {
        $node = $this->tree->node('/alice/files/Relatorio.pdf');
        $this->shares->add(['node' => $node->getId(), 'with' => 'carla']);
        $bruno = $this->shares->add(['node' => $node->getId(), 'with' => 'bruno']);
        $link = $this->shares->add(['node' => $node->getId(), 'type' => IShare::TYPE_LINK, 'with' => null]);

        self::assertSame($bruno, $this->access->findExisting('alice', $node, new ShareRecipient(ShareRecipient::USER, 'bruno', 'Bruno')));
        self::assertSame($link, $this->access->findExisting('alice', $node, new ShareRecipient(ShareRecipient::LINK, '', 'Link')));
        self::assertNull($this->access->findExisting('alice', $node, new ShareRecipient(ShareRecipient::GROUP, 'bruno', 'Bruno')));
    }

    public function testOnlyOwnSharesOfOwnFilesAreRemovableAndNeverARoom(): void {
        self::assertTrue($this->access->isRemovable($this->shares->add(), 'alice'));
        self::assertTrue($this->access->isRemovable($this->shares->add(['type' => IShare::TYPE_LINK, 'with' => null]), 'alice'));
        self::assertFalse($this->access->isRemovable($this->shares->add(['type' => IShare::TYPE_ROOM, 'with' => 'tok']), 'alice'));
        self::assertFalse($this->access->isRemovable($this->shares->add(['by' => 'bruno']), 'alice'), 'criado por outro');
        self::assertFalse($this->access->isRemovable($this->shares->add(['owner' => 'pedro']), 'alice'), 'reshare de arquivo recebido');
        self::assertFalse($this->access->isRemovable($this->shares->add(['type' => IShare::TYPE_EMAIL]), 'alice'), 'tipo fora da 0.10');
    }

    public function testListMinePagesAcrossTypesInAFixedOrder(): void {
        $node = $this->id('/Relatorio.pdf');
        $links = [];
        for ($i = 0; $i < 3; $i++) {
            $links[] = $this->shares->add(['node' => $node, 'type' => IShare::TYPE_LINK, 'with' => null]);
        }
        $users = [];
        for ($i = 0; $i < 60; $i++) {
            $users[] = $this->shares->add(['node' => $node, 'with' => 'u' . $i]);
        }

        $first = $this->access->listMine($this->tree->rootFolder(), 'alice', 0);
        self::assertCount(ShareAccess::PAGE_SIZE, $first['items']);
        self::assertTrue($first['hasMore']);
        self::assertSame($users[0], $first['items'][0]['share']);
        self::assertSame($node, $first['items'][0]['node']->getId());

        $second = $this->access->listMine($this->tree->rootFolder(), 'alice', ShareAccess::PAGE_SIZE);
        self::assertCount(13, $second['items']);
        self::assertFalse($second['hasMore']);
        self::assertSame(array_merge(array_slice($users, 50), $links), array_column($second['items'], 'share'));
        foreach ($this->shares->calls as $call) {
            self::assertNull($call['node']);
            self::assertFalse($call['reshares']);
            self::assertLessThanOrEqual(ShareAccess::MAX_OFFSET + ShareAccess::PAGE_SIZE + 1, $call['limit']);
        }
    }

    /** A hidden node is as if it did not exist, and so is one the user cannot reach any more. */
    public function testListMineSkipsHiddenAndUnreachableNodesWithoutShortPages(): void {
        $visible = $this->id('/Relatorio.pdf');
        $hidden = $this->id('/Projetos');
        $this->hidden[] = $hidden;
        for ($i = 0; $i < 30; $i++) {
            $this->shares->add(['node' => $hidden, 'with' => 'h' . $i]);
            $this->shares->add(['node' => 999999, 'with' => 'gone' . $i]);
        }
        $kept = [];
        for ($i = 0; $i < 55; $i++) {
            $kept[] = $this->shares->add(['node' => $visible, 'with' => 'v' . $i]);
        }

        $first = $this->access->listMine($this->tree->rootFolder(), 'alice', 0);
        self::assertSame(array_slice($kept, 0, 50), array_column($first['items'], 'share'));
        self::assertTrue($first['hasMore']);
        $second = $this->access->listMine($this->tree->rootFolder(), 'alice', 50);
        self::assertSame(array_slice($kept, 50), array_column($second['items'], 'share'));
        self::assertFalse($second['hasMore']);
    }

    /** The path of a listed share is the user's own view of it, never the owner's tree. */
    public function testNodeOfUsesTheUsersView(): void {
        $share = $this->shares->add(['node' => $this->id('/Recebido.pdf'), 'owner' => 'pedro']);
        self::assertSame('/alice/files/Recebido.pdf', $this->access->nodeOf($this->tree->rootFolder(), $share)?->getPath());
        self::assertNull($this->access->nodeOf($this->tree->rootFolder(), $this->shares->add(['node' => 424242])));
    }

    public function testEachTypeAsksForItsOwnGrant(): void {
        self::assertSame('share', ShareAccess::operationFor(IShare::TYPE_USER));
        self::assertSame('share', ShareAccess::operationFor(IShare::TYPE_GROUP));
        self::assertSame('link', ShareAccess::operationFor(IShare::TYPE_LINK));
        self::assertSame(FilesMessages::shareTypeUnsupported(), $this->failure(fn () => ShareAccess::operationFor(IShare::TYPE_ROOM)));

        self::assertSame(FilesMessages::shareNotGranted(), $this->failure(fn () => $this->access->assertGranted('alice', IShare::TYPE_USER)));
        $this->policy->setGrant('alice', 'files', 'share', true);
        $this->access->assertGranted('alice', IShare::TYPE_USER);
        $this->access->assertGranted('alice', IShare::TYPE_GROUP);
        self::assertSame(FilesMessages::shareNotGranted(), $this->failure(fn () => $this->access->assertGranted('alice', IShare::TYPE_LINK)));
        $this->policy->setGrant('alice', 'files', 'link', true);
        $this->access->assertGranted('alice', IShare::TYPE_LINK);
    }
}
