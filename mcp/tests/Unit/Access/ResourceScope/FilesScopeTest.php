<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\ResourceScope;

use OCA\Mcp\Tests\Support\Access\ResourceScope\FilesScopes;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Access\ResourceScope\FilesScope;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Support\Platform\FileAccess\FakeTree;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\Node;
use OCP\Files\Storage\ISharedStorage;
use OCP\Files\Storage\IStorage;
use OCP\IUser;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;

/**
 * The Files resource scope (contracts B.2, B.5): a node is inside when it or an ancestor, in the mounted chain or in the
 * owner's chain above a share, is a selected folder; ids are compared in canonical decimal form (F2 of the B4 review);
 * restricted to nothing reaches nothing (S6); a scope that cannot be read is 503, never unrestricted (S11); the scope
 * of one user never applies to another (S4).
 */
final class FilesScopeTest extends TestCase {
    private const HIDDEN_TAG = '999';

    private FakeTree $tree;
    private InMemoryConfig $config;
    /** @var array<string, list<string>|null|false> scope by user; absent is unrestricted */
    private array $scopes = [];
    /** @var array<string, list<string>> tags by file id */
    private array $tags = [];
    private VisibilityGuard $guard;
    private string $projects;
    private string $nested;
    private string $archive;

    protected function setUp(): void {
        $this->tree = new FakeTree($this, '/alice/files');
        $this->projects = (string)$this->tree->addFolder('/alice/files/Projects');
        $this->nested = (string)$this->tree->addFolder('/alice/files/A/B/C');
        $this->archive = (string)$this->tree->addFolder('/alice/files/Archive');
        $this->tree->addFile('/alice/files/Projects/deep/plan.md', 'plan');
        $this->tree->addFile('/alice/files/Archive/old.md', 'old');
        $this->config = new InMemoryConfig();
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode([self::HIDDEN_TAG]);
        $this->guard = new VisibilityGuard($this->config->mock($this), $this->tags(), $this->tree->filesystemRoot());
    }

    private function tags(): ISystemTagObjectMapper {
        $mapper = $this->createMock(ISystemTagObjectMapper::class);
        $mapper->method('getTagIdsForObjects')->willReturnCallback(function (array $ids): array {
            $result = [];
            foreach ($ids as $id) {
                $result[(string)$id] = $this->tags[(string)$id] ?? [];
            }
            return $result;
        });
        return $mapper;
    }

    private function scope(?string $operationUser = 'alice'): FilesScope {
        return FilesScopes::over($this, $this->guard, $this->tree->filesystemRoot(),
            fn (string $uid): array|null|false => $this->scopes[$uid] ?? null, $operationUser);
    }

    private function gate(?string $operationUser = 'alice'): FilesAccessGuard {
        return new FilesAccessGuard($this->scope($operationUser), $this->config->mock($this), $this->tags(), $this->tree->filesystemRoot(), null, null, null);
    }

    private function node(string $path): Node {
        return $this->tree->node('/alice/files' . $path);
    }

    public function testUnrestrictedAllowsEverythingAndListsTheRootItself(): void {
        $scope = $this->scope();
        $this->assertFalse($scope->restricts('alice'));
        foreach (['', '/Projects', '/Archive/old.md', '/A/B'] as $path) {
            $this->assertTrue($scope->allows('alice', $this->node($path)), $path);
        }
        $this->assertNull($scope->roots('alice', $this->tree->rootFolder()));
    }

    /**
     * External storage Guests hides from a guest stays out of reach of the gate however the node was resolved: core
     * does not apply the Guests mount filter to a path mounted on demand (Nextcloud 35).
     */
    public function testExternalStorageGuestsHidesIsOutOfReachAndTheRestIsNot(): void {
        $this->tree->nodes['/alice/files/Archive/old.md']['scope'] = 'external';
        foreach ([true, false] as $hides) {
            $guests = $this->getMockBuilder(\OCA\Mcp\Access\Guests\GuestRestrictions::class)->disableOriginalConstructor()->onlyMethods(['hidesExternalStorage'])->getMock();
            $guests->method('hidesExternalStorage')->willReturnCallback(fn (string $uid): bool => $uid === 'alice' && $hides);
            $gate = new FilesAccessGuard($this->scope(), $this->config->mock($this), $this->tags(), $this->tree->filesystemRoot(), null, null, null, $guests);
            $label = $hides ? 'hidden' : 'allowed';
            $this->assertSame(!$hides, $gate->isVisible($this->node('/Archive/old.md')), "external file, storage $label");
            $this->assertSame(!$hides, $gate->isTraversable($this->node('/Archive/old.md')), "crossing it, storage $label");
            $this->assertTrue($gate->isVisible($this->node('/Projects')), "a file of the home, storage $label");
        }
    }

    public function testANodeIsInsideWhenItOrAnAncestorIsSelected(): void {
        $this->scopes['alice'] = [$this->projects, $this->nested];
        $scope = $this->scope();
        $this->assertTrue($scope->restricts('alice'));
        foreach (['/Projects' => true, '/Projects/deep' => true, '/Projects/deep/plan.md' => true, '/A/B/C' => true,
            '/A/B' => false, '/A' => false, '' => false, '/Archive' => false, '/Archive/old.md' => false] as $path => $inside) {
            $this->assertSame($inside, $scope->allows('alice', $this->node($path)), $path);
        }
    }

    /** F2 of the B4 review: Node::getId() is an int; the stored ids are canonical decimal strings, compared byte for byte. */
    public function testIntegerNodeIdsMatchOnlyTheirCanonicalDecimal(): void {
        $this->assertIsInt($this->node('/Projects')->getId());
        $this->scopes['alice'] = ['0' . $this->projects, $this->projects . ' ', '+' . $this->projects];
        $this->assertFalse($this->scope()->allows('alice', $this->node('/Projects/deep/plan.md')));
        $this->scopes['alice'] = [$this->projects];
        $this->assertTrue($this->scope()->allows('alice', $this->node('/Projects/deep/plan.md')));
        $this->assertSame('42', FilesScope::canonical(42));
        $this->assertSame('', FilesScope::canonical(0));
        $this->assertSame('', FilesScope::canonical(null));
    }

    /** S6: restricted to nothing reaches nothing in Files; the root is not listed either. */
    public function testRestrictedToNothingReachesNothing(): void {
        $this->scopes['alice'] = [];
        $scope = $this->scope();
        foreach (['', '/Projects', '/Archive/old.md'] as $path) {
            $this->assertFalse($scope->allows('alice', $this->node($path)), $path);
        }
        $this->assertSame([], $scope->roots('alice', $this->tree->rootFolder()));
    }

    /** S11: a scope that cannot be read is unavailable, never unrestricted. */
    public function testAnUnreadableScopeIsUnavailableNeverUnrestricted(): void {
        $this->scopes['alice'] = false;
        $scope = $this->scope();
        foreach ([fn () => $scope->restricts('alice'), fn () => $scope->allows('alice', $this->node('/Projects')),
            fn () => $scope->roots('alice', $this->tree->rootFolder()), fn () => $this->gate()->isVisible($this->node('/Projects')),
            fn () => $this->gate()->assertScopeReadable()] as $read) {
            try {
                $read();
                $this->fail('An unreadable scope was answered');
            } catch (PolicyUnavailable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** The gate without a user cannot know whose scope applies: nothing passes, the operation is unavailable. */
    public function testTheGateWithoutAnOpenOperationFailsClosed(): void {
        $this->expectException(PolicyUnavailable::class);
        $this->gate(null)->isVisible($this->node('/Projects'));
    }

    /** S4: the scope is the operation user's own; another user's restriction never applies, nor widens. */
    public function testTheScopeIsTheOperationUsersOwn(): void {
        $this->scopes['alice'] = [$this->projects];
        $this->assertFalse($this->gate('alice')->isVisible($this->node('/Archive/old.md')));
        $this->assertTrue($this->gate('bob')->isVisible($this->node('/Archive/old.md')), 'bob is unrestricted');
        $this->scopes['bob'] = [$this->archive];
        $this->assertFalse($this->gate('bob')->isVisible($this->node('/Projects/deep/plan.md')));
        $this->assertTrue($this->gate('alice')->isVisible($this->node('/Projects/deep/plan.md')));
    }

    /** The order of the gate: scope, then exclusion; ancestors crossed by metadata answer to the exclusion only. */
    public function testTheGateAppliesScopeThenExclusionAndTraversesAncestors(): void {
        $this->scopes['alice'] = [$this->nested];
        $gate = $this->gate();
        $this->assertTrue($gate->isVisible($this->node('/A/B/C')));
        $this->assertFalse($gate->isVisible($this->node('/A/B')));
        $this->assertTrue($gate->isTraversable($this->node('/A/B')));
        $this->assertTrue($gate->isTraversable($this->node('')));
        // S3: exclusion wins over the scope, inside a selected folder and on a crossed ancestor.
        $this->tags[$this->nested] = [self::HIDDEN_TAG];
        $gate->clearCache();
        $this->assertFalse($gate->isVisible($this->node('/A/B/C')));
        $this->tags = [(string)$this->tree->nodes['/alice/files/A']['id'] => [self::HIDDEN_TAG]];
        $gate->clearCache();
        $this->assertFalse($gate->isTraversable($this->node('/A/B')));
    }

    /** The listing of "/" shows the outermost selected folders the user reaches, at their real paths, still excluded by tag. */
    public function testRootsAreTheOutermostReachableSelectedFolders(): void {
        $inner = (string)$this->tree->nodes['/alice/files/Projects/deep']['id'];
        $this->scopes['alice'] = [$inner, $this->nested, '987654', $this->projects, $this->archive];
        $paths = static fn (?array $folders): ?array => $folders === null ? null : array_map(static fn (Folder $f): string => $f->getPath(), $folders);
        $this->assertSame(['/alice/files/A/B/C', '/alice/files/Archive', '/alice/files/Projects'],
            $paths($this->scope()->roots('alice', $this->tree->rootFolder())));
        $this->tags[$this->archive] = [self::HIDDEN_TAG];
        $this->assertSame(['/alice/files/A/B/C', '/alice/files/Projects'], $paths($this->gate()->scopeRoots($this->tree->rootFolder())));
        $this->scopes['alice'] = [(string)$this->tree->rootFolder()->getId()];
        $this->assertNull($this->gate()->scopeRoots($this->tree->rootFolder()), 'the root itself is selected');
    }

    /** A chain that cannot be resolved is outside the scope, never inside. */
    public function testAnUnresolvedChainIsOutside(): void {
        $this->scopes['alice'] = [$this->projects];
        $broken = $this->createMock(Node::class);
        $broken->method('getId')->willReturn(5000);
        $broken->method('getPath')->willReturn('/alice/files/Projects/x');
        $broken->method('getStorage')->willReturn($this->createMock(IStorage::class));
        $broken->method('getParent')->willThrowException(new \OCP\Files\NotFoundException());
        $this->assertFalse($this->scope()->allows('alice', $broken));
    }

    /**
     * Above a share mount root the owner's chain counts: bob selected alice's Projects (shared with him), and reaches
     * Projects/Shared, also shared with him and mounted elsewhere, only through alice's chain.
     */
    public function testTheOwnersChainAboveAShareRootCounts(): void {
        [$root, $node] = $this->sharedWithBob();
        $guard = new VisibilityGuard($this->config->mock($this), $this->tags(), null);
        $scope = fn (?array $items) => FilesScopes::over($this, $guard, $root, static fn (): ?array => $items, 'bob');
        $this->assertTrue($scope(['100'])->allows('bob', $node), 'alice\'s Projects, above the share root');
        $this->assertTrue($scope(['300'])->allows('bob', $node), 'the share root as bob sees it');
        $this->assertTrue($scope(['200'])->allows('bob', $node), 'bob\'s root, in the mounted chain');
        $this->assertFalse($scope(['999'])->allows('bob', $node));
    }

    /**
     * bob's /bob/files/Shared (id 300) is the mount root of alice's /alice/files/Projects/Shared; Projects is 100, alice's
     * folder 10, bob's folder 200. The node is /bob/files/Shared/x.md (id 301).
     *
     * @return array{IRootFolder, Node}
     */
    private function sharedWithBob(): array {
        $alice = $this->createMock(IUser::class);
        $alice->method('getUID')->willReturn('alice');
        $plain = $this->createMock(IStorage::class);
        $plain->method('instanceOfStorage')->willReturn(false);
        $shared = $this->createMock(IStorage::class);
        $shared->method('instanceOfStorage')->willReturnCallback(static fn (string $class): bool => $class === ISharedStorage::class);
        $make = function (string $class, int $id, string $path, ?Node $parent, IStorage $storage, string $mount) use ($alice): Node {
            $node = $this->createMock($class);
            $node->method('getId')->willReturn($id);
            $node->method('getPath')->willReturn($path);
            $node->method('getStorage')->willReturn($storage);
            $point = $this->createMock(IMountPoint::class);
            $point->method('getMountPoint')->willReturn($mount);
            $node->method('getMountPoint')->willReturn($point);
            $node->method('getOwner')->willReturn($alice);
            $node->method('getParent')->willReturn($parent ?? $this->createMock(IRootFolder::class));
            return $node;
        };
        $aliceFolder = $make(Folder::class, 10, '/alice/files', null, $plain, '/alice/');
        $projects = $make(Folder::class, 100, '/alice/files/Projects', $aliceFolder, $plain, '/alice/');
        $origin = $make(Folder::class, 300, '/alice/files/Projects/Shared', $projects, $plain, '/alice/');
        $aliceFolder->method('getById')->willReturnCallback(static fn (int $id): array => $id === 300 ? [$origin] : []);
        $bobFolder = $make(Folder::class, 200, '/bob/files', null, $plain, '/bob/');
        $mountRoot = $make(Folder::class, 300, '/bob/files/Shared', $bobFolder, $shared, '/bob/files/Shared/');
        $node = $make(\OCP\Files\File::class, 301, '/bob/files/Shared/x.md', $mountRoot, $shared, '/bob/files/Shared/');
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(static fn (string $uid) => $uid === 'alice' ? $aliceFolder : $bobFolder);
        return [$root, $node];
    }

    /**
     * F5: ensureFolder decides the boundary before creating anything. Existing ancestors are crossed by metadata, the
     * deepest existing folder must be reachable, and a refusal leaves no folder behind.
     */
    public function testEnsureFolderCreatesOnlyAtOrBelowASelectedFolder(): void {
        $this->scopes['alice'] = [$this->nested];
        $gate = $this->gate();
        $root = $this->tree->rootFolder();
        $this->assertSame('/alice/files/A/B/C/new/x', \OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess::ensureFolder($root, '/A/B/C/new/x', $gate)->getPath());
        $this->assertSame('/alice/files/A/B/C', \OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess::ensureFolder($root, '/A/B/C', $gate)->getPath());
        $this->tree->ops = [];
        foreach (['/A/new', '/A/B/new/x', '/Fresh/x/y', '/Archive/x', '/A/B'] as $path) {
            try {
                \OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess::ensureFolder($root, $path, $gate);
                $this->fail("$path was reached");
            } catch (\OCA\Mcp\Mcp\Tools\Results\ToolFailure $e) {
                $this->assertSame(\OCA\Mcp\Platform\FileAccess\FileAccessMessages::notFound(), $e->getMessage(), $path);
            }
        }
        $this->assertSame([], $this->tree->ops, 'no folder on the way to a refusal');
    }

    /** N2 of the ticket 13 re-read: the inspector's entry builds the chain only for a restricted Files scope. */
    public function testCandidatesInBuildsTheChainOnlyWhenRestricted(): void {
        $broken = $this->createMock(Node::class);
        $broken->method('getId')->willReturn(5000);
        $broken->method('getPath')->willReturn('/alice/files/x');
        $broken->method('getStorage')->willReturn($this->createMock(IStorage::class));
        $broken->method('getParent')->willThrowException(new \OCP\Files\NotFoundException());
        $scope = $this->scope();
        $resolution = static fn (string $mode, array $items = []) => new \OCA\Mcp\Access\ResourceScope\ScopeResolution($mode, $items);
        $this->assertNull($scope->candidatesIn($resolution('unrestricted'), $broken), 'no chain, no failure, without a scope');
        $this->assertNull($scope->candidatesIn($resolution('unavailable'), $broken));
        $this->assertContains($this->projects, $scope->candidatesIn($resolution('restricted', [$this->projects]), $this->node('/Projects/deep/plan.md')));
        $this->assertSame($scope->candidates($this->node('/A/B/C')), $scope->candidatesIn($resolution('restricted', []), $this->node('/A/B/C')));
        $this->expectException(\Throwable::class);
        $scope->candidatesIn($resolution('restricted', [$this->projects]), $broken);
    }

    /**
     * M2 of the B5 review: siblings share their ancestors, so a listing of N children resolves each ancestor once:
     * the first child walks up to the root, every other one stops at its parent.
     */
    public function testSiblingsResolveTheirAncestorsOncePerOperation(): void {
        for ($i = 0; $i < 20; $i++) {
            $this->tree->addFile("/alice/files/Projects/deep/f$i.md", 'x');
        }
        $calls = 0;
        $counting = new class ($this->config->mock($this), $this->tags(), $this->tree->filesystemRoot(), $calls) extends VisibilityGuard {
            public function __construct($config, $tags, $root, private int &$calls) {
                parent::__construct($config, $tags, $root);
            }
            public function parentOf(Node $node): Node {
                $this->calls++;
                return parent::parentOf($node);
            }
        };
        $this->scopes['alice'] = [$this->projects];
        $scope = FilesScopes::over($this, $counting, $this->tree->filesystemRoot(), fn (string $uid): array|null|false => $this->scopes[$uid] ?? null);
        for ($i = 0; $i < 20; $i++) {
            $this->assertTrue($scope->allows('alice', $this->node("/Projects/deep/f$i.md")));
        }
        // f0 -> deep -> Projects -> /alice/files -> root: 4 walks; f1..f19 stop at the known deep: 19 more.
        $this->assertSame(23, $calls);
        $this->assertTrue($scope->allows('alice', $this->node('/Projects/deep/f3.md')));
        $this->assertSame(23, $calls, 'a node already resolved walks nothing');
        $scope->clearCache();
        $scope->allows('alice', $this->node('/Projects/deep/f3.md'));
        $this->assertSame(27, $calls, 'a change of the tree forgets the chains');
    }

    /** M2: the owner's chain of a share root is resolved once for every node below it. */
    public function testTheOwnersChainIsResolvedOncePerShareRoot(): void {
        [$root, $node] = $this->sharedWithBob();
        $lookups = 0;
        $counted = $this->createMock(IRootFolder::class);
        $counted->method('getUserFolder')->willReturnCallback(function (string $uid) use ($root, &$lookups) {
            $lookups++;
            return $root->getUserFolder($uid);
        });
        $guard = new VisibilityGuard($this->config->mock($this), $this->tags(), null);
        $scope = FilesScopes::over($this, $guard, $counted, static fn (): array => ['100'], 'bob');
        foreach ([1, 2, 3] as $ignored) {
            $this->assertTrue($scope->allows('bob', $node));
        }
        $sibling = $this->createMock(\OCP\Files\File::class);
        $sibling->method('getId')->willReturn(302);
        $sibling->method('getPath')->willReturn('/bob/files/Shared/y.md');
        $sibling->method('getStorage')->willReturn($node->getStorage());
        $sibling->method('getMountPoint')->willReturn($node->getMountPoint());
        $sibling->method('getParent')->willReturn($node->getParent());
        $this->assertTrue($scope->allows('bob', $sibling));
        // The same share root mounted at a second path of bob's: another chain, the same owner lookup.
        $alias = $this->createMock(Folder::class);
        $alias->method('getId')->willReturn(300);
        $alias->method('getPath')->willReturn('/bob/files/Again');
        $alias->method('getStorage')->willReturn($node->getStorage());
        $point = $this->createMock(IMountPoint::class);
        $point->method('getMountPoint')->willReturn('/bob/files/Again/');
        $alias->method('getMountPoint')->willReturn($point);
        $alias->method('getOwner')->willReturn($node->getOwner());
        $alias->method('getParent')->willReturn($node->getParent()->getParent());
        $this->assertTrue($scope->allows('bob', $alias));
        $this->assertSame(1, $lookups, 'one owner lookup for the share root, whatever path it is mounted at');
    }
}
