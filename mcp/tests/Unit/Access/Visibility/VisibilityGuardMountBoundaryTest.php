<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Access\Visibility;

use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;

/**
 * The ancestor chains a real Nextcloud hands the guard across mount boundaries, as the A4 run recorded them: above a
 * mount root core answers a lazy folder whose id is the parent id of the mounted storage, not of the folder the user
 * sees. -1 for an external storage, another storage's id for a groupfolder, the source folder's id for a share.
 */
final class VisibilityGuardMountBoundaryTest extends TestCase {
    private const TAG = '42';

    /** @var array<string, list<string>> tags by file id */
    private array $tags = [];
    /** @var list<list<string>> ids of every tag lookup */
    private array $lookups = [];
    /** @var array<string, list<string>> tags assigned to backups, by destination id */
    private array $assigned = [];
    /** @var array<string, Node|\Throwable> what IRootFolder::get() answers by path */
    private array $byPath = [];
    /** @var array<string, Folder> user folders by uid */
    private array $userFolders = [];
    /** @var array<int, list<Node>> owner aliases by id, for every user folder */
    private array $byId = [];
    private IRootFolder $root;

    protected function setUp(): void {
        $this->root = $this->createMock(IRootFolder::class);
        $this->root->method('getId')->willReturn(-1);
        $this->root->method('getPath')->willReturn('/');
        $this->root->expects(self::never())->method('getParent');
        $this->root->method('get')->willReturnCallback(function (string $path): Node {
            $answer = $this->byPath[$path] ?? new \OCP\Files\NotFoundException();
            if ($answer instanceof \Throwable) {
                throw $answer;
            }
            return $answer;
        });
        $this->root->method('getUserFolder')->willReturnCallback(fn (string $uid) => $this->userFolders[$uid]);
    }

    private function guard(): VisibilityGuard {
        $config = new InMemoryConfig();
        $config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode([self::TAG]);
        $mapper = $this->createMock(ISystemTagObjectMapper::class);
        $mapper->method('getTagIdsForObjects')->willReturnCallback(function (array $ids): array {
            $this->lookups[] = array_map('strval', $ids);
            $result = [];
            foreach ($ids as $id) {
                $result[(string)$id] = $this->tags[(string)$id] ?? [];
            }
            return $result;
        });
        $mapper->method('assignTags')->willReturnCallback(function (string $id, string $type, array $tags): void {
            $this->assigned[$id] = array_map('strval', $tags);
        });
        return new VisibilityGuard($config->mock($this), $mapper, $this->root);
    }

    /**
     * @param Node|\Closure(): Node $parent the parent getParent() answers
     * @param string $mount mount point of the node, with its trailing slash, as IMountPoint reports it
     */
    private function node(int $id, string $path, string $mount, Node|\Closure $parent, bool $shared = false, string $owner = 'alice'): Folder {
        $node = $this->createMock(Folder::class);
        $node->method('getId')->willReturn($id);
        $node->method('getPath')->willReturn($path);
        $node->method('getName')->willReturn(basename($path));
        $node->method('getParent')->willReturnCallback(fn () => $parent instanceof \Closure ? $parent() : $parent);
        $mountPoint = $this->createMock(\OCP\Files\Mount\IMountPoint::class);
        $mountPoint->method('getMountPoint')->willReturn($mount);
        $node->method('getMountPoint')->willReturn($mountPoint);
        $storage = $this->createMock(\OCP\Files\Storage\IStorage::class);
        $storage->method('instanceOfStorage')->willReturnCallback(static fn (string $class): bool => $shared && $class === \OCP\Files\Storage\ISharedStorage::class);
        $node->method('getStorage')->willReturn($storage);
        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn($owner);
        $node->method('getOwner')->willReturn($user);
        $node->method('getById')->willReturnCallback(fn (int $id) => $this->byId[$id] ?? []);
        $node->method('nodeExists')->willReturn(false);
        return $node;
    }

    /** /<uid> and /<uid>/files as core resolves them by path: the real user folder, with its own id. */
    private function home(string $uid, int $homeId, int $filesId): Folder {
        $home = $this->node($homeId, "/$uid", "/$uid/", $this->root, false, $uid);
        $files = $this->node($filesId, "/$uid/files", "/$uid/", $home, false, $uid);
        $this->byPath["/$uid/files"] = $files;
        $this->userFolders[$uid] = $files;
        return $files;
    }

    /** A lazy folder above a mount root: the right path, an id of another storage, the right parent. */
    private function lazyFiles(string $uid, int $bogusId): Folder {
        return $this->node($bogusId, "/$uid/files", "/$uid/", fn () => $this->byPath["/$uid"] ?? $this->root, false, $uid);
    }

    /** alice's external storage file, chain 94 → 93 → 88 (mount root) → lazy /alice/files id -1. */
    private function externalFile(): Folder {
        $files = $this->home('alice', 90, 91);
        $this->byPath['/alice'] = $files->getParent();
        $mount = '/alice/files/ext/';
        $extRoot = $this->node(88, '/alice/files/ext', $mount, $this->lazyFiles('alice', -1));
        $dir = $this->node(93, '/alice/files/ext/dir', $mount, $extRoot);
        return $this->node(94, '/alice/files/ext/dir/diag.txt', $mount, $dir);
    }

    /** alice's groupfolder file, chain 96 → 95 → 85 (mount root) → lazy /alice/files id 84. */
    private function groupfolderFile(): Folder {
        $files = $this->home('alice', 90, 91);
        $this->byPath['/alice'] = $files->getParent();
        $mount = '/alice/files/gf/';
        $gfRoot = $this->node(85, '/alice/files/gf', $mount, $this->lazyFiles('alice', 84));
        $dir = $this->node(95, '/alice/files/gf/dir', $mount, $gfRoot);
        return $this->node(96, '/alice/files/gf/dir/diag.txt', $mount, $dir);
    }

    public function testExternalStorageFileIsVisibleWithATagConfiguredAndNothingTagged(): void {
        $file = $this->externalFile();

        self::assertTrue($this->guard()->isVisible($file));
        self::assertNotContains('-1', array_merge(...$this->lookups), 'the placeholder id is never looked up');
        self::assertContains('91', array_merge(...$this->lookups), 'the real user folder is');
    }

    public function testExternalStorageStillHonorsTagsOnRealAncestors(): void {
        foreach (['94', '93', '88', '91'] as $tagged) {
            $this->tags = [$tagged => [self::TAG]];
            self::assertFalse($this->guard()->isVisible($this->externalFile()), "tag on $tagged");
        }
    }

    public function testGroupfolderFileChecksTheRealUserFolderInsteadOfTheForeignParentId(): void {
        self::assertTrue($this->guard()->isVisible($this->groupfolderFile()));
        self::assertNotContains('84', array_merge(...$this->lookups));

        foreach (['96', '95', '85', '91'] as $tagged) {
            $this->tags = [$tagged => [self::TAG]];
            self::assertFalse($this->guard()->isVisible($this->groupfolderFile()), "tag on $tagged");
        }
    }

    /**
     * bob receives a file of alice's groupfolder. Mounted chain: 97 (share mount root) → lazy /bob/files id 95, the
     * source folder's id. Owner chain: 96 → 95 → 85 (groupfolder mount root) → lazy /alice/files id 84, never 91.
     */
    private function sharedGroupfolderFile(): Folder {
        $origin = $this->groupfolderFile();
        $this->byId[97] = [$this->node(97, '/alice/files/gf/dir/diag.txt', '/alice/files/gf/', $origin->getParent())];
        $bobFiles = $this->home('bob', 98, 99);
        $this->byPath['/bob'] = $bobFiles->getParent();
        return $this->node(97, '/bob/files/diag.txt', '/bob/files/diag.txt/', $this->lazyFiles('bob', 95), true, 'alice');
    }

    public function testFileSharedFromAGroupfolderIsVisibleToTheRecipient(): void {
        self::assertTrue($this->guard()->isVisible($this->sharedGroupfolderFile()));
        self::assertContains('99', array_merge(...$this->lookups), "bob's real folder is checked");
    }

    public function testFileSharedFromAGroupfolderStillHonorsEveryRealAncestor(): void {
        // 95 is both alice's real groupfolder subfolder and the id core lends to bob's /bob/files: tagged, the owner
        // chain refuses the file, which is the right answer for the real folder.
        foreach (['97', '95', '85', '91', '99'] as $tagged) {
            $this->tags = [$tagged => [self::TAG]];
            self::assertFalse($this->guard()->isVisible($this->sharedGroupfolderFile()), "tag on $tagged");
        }
    }

    public function testParentThatCannotBeResolvedAgainIsRefused(): void {
        foreach ([
            'missing' => new \OCP\Files\NotFoundException(),
            'other path' => fn () => $this->node(91, '/alice/files/elsewhere', '/alice/', $this->root),
            'no id' => fn () => $this->node(-1, '/alice/files', '/alice/', $this->root),
            'failure' => new \RuntimeException('/alice/files/secret'),
        ] as $case => $answer) {
            $file = $this->externalFile();
            $this->byPath['/alice/files'] = $answer instanceof \Closure ? $answer() : $answer;
            self::assertFalse($this->guard()->isVisible($file), $case);
        }
    }

    public function testAnInvalidIdInsideAMountIsStillRefused(): void {
        $this->home('alice', 90, 91);
        $mount = '/alice/files/ext/';
        $extRoot = $this->node(88, '/alice/files/ext', $mount, $this->lazyFiles('alice', -1));
        $broken = $this->node(-1, '/alice/files/ext/dir', $mount, $extRoot);
        $file = $this->node(94, '/alice/files/ext/dir/diag.txt', $mount, $broken);

        self::assertFalse($this->guard()->isVisible($file));
    }

    public function testAPlaceholderBelowTheUserFolderOutsideAMountBoundaryIsRefused(): void {
        $files = $this->home('alice', 90, 91);
        // Not a mount root: its parent id is trusted, so an invalid one is refused as before.
        $placeholder = $this->node(-1, '/alice/files/dir', '/alice/', $files);
        $file = $this->node(94, '/alice/files/dir/diag.txt', '/alice/', $placeholder);

        self::assertFalse($this->guard()->isVisible($file));
    }

    public function testATagOnTheRealUserFolderHidesBothExternalAndGroupfolderFiles(): void {
        $guard = $this->guard();
        self::assertTrue($guard->isVisible($this->externalFile()));
        self::assertTrue($guard->isVisible($this->groupfolderFile()));

        $this->tags = ['91' => [self::TAG]];
        $guard->clearCache();
        self::assertFalse($guard->isVisible($this->externalFile()));
        self::assertFalse($guard->isVisible($this->groupfolderFile()));
    }

    public function testTheRealUserFolderIsCheckedOnceAcrossMountsOfOneRequest(): void {
        $guard = $this->guard();
        self::assertTrue($guard->isVisible($this->externalFile()));
        self::assertTrue($guard->isVisible($this->groupfolderFile()));

        // Both walks reach /alice/files as the same id and path, so the clean ancestry of the first is reused.
        $ids = array_merge(...$this->lookups);
        self::assertSame(1, count(array_keys($ids, '91', true)));
        self::assertNotContains('-1', $ids);
        self::assertNotContains('84', $ids);
    }

    /** Groupfolder B mounted inside groupfolder A: two boundaries in a row, each with a borrowed parent id. */
    private function nestedGroupfolderFile(): Folder {
        $files = $this->home('alice', 90, 91);
        $this->byPath['/alice'] = $files->getParent();
        $outer = $this->node(60, '/alice/files/A', '/alice/files/A/', $this->lazyFiles('alice', 84));
        $this->byPath['/alice/files/A'] = $outer;
        $lazyOuter = $this->node(70, '/alice/files/A', '/alice/files/A/', $outer->getParent());
        $inner = $this->node(61, '/alice/files/A/B', '/alice/files/A/B/', $lazyOuter);
        return $this->node(62, '/alice/files/A/B/doc.txt', '/alice/files/A/B/', $inner);
    }

    public function testTwoMountBoundariesInARowAreBothResolvedAgain(): void {
        self::assertTrue($this->guard()->isVisible($this->nestedGroupfolderFile()));
        $ids = array_merge(...$this->lookups);
        self::assertContains('60', $ids);
        self::assertContains('91', $ids);
        self::assertNotContains('70', $ids, 'the inner boundary lends the outer folder an id of another storage');
        self::assertNotContains('84', $ids);

        foreach (['62', '61', '60', '91'] as $tagged) {
            $this->tags = [$tagged => [self::TAG]];
            self::assertFalse($this->guard()->isVisible($this->nestedGroupfolderFile()), "tag on $tagged");
        }
    }

    public function testBackupCopiesTagsOfRealAncestorsAcrossMountBoundaries(): void {
        $backup = $this->node(500, '/alice/files/MCP backups/diag.txt.bak', '/alice/', $this->root);
        foreach ([
            'external, nothing tagged' => [fn () => $this->externalFile(), [], null],
            'external, real mount root' => [fn () => $this->externalFile(), ['88' => [self::TAG]], [self::TAG]],
            'external, real user folder' => [fn () => $this->externalFile(), ['91' => [self::TAG]], [self::TAG]],
            'groupfolder, borrowed id ignored' => [fn () => $this->groupfolderFile(), ['84' => [self::TAG]], null],
            'groupfolder, real user folder' => [fn () => $this->groupfolderFile(), ['91' => [self::TAG]], [self::TAG]],
            'nested, outer root' => [fn () => $this->nestedGroupfolderFile(), ['60' => [self::TAG]], [self::TAG]],
        ] as $case => [$source, $tags, $expected]) {
            $this->tags = $tags;
            $this->assigned = [];
            $this->guard()->copyHiddenTags($source(), $backup);
            self::assertSame($expected, $this->assigned['500'] ?? null, $case);
        }
    }

    public function testBackupRefusesWhenTheParentCannotBeResolvedAgain(): void {
        $file = $this->externalFile();
        $this->byPath['/alice/files'] = new \OCP\Files\NotFoundException();

        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        $this->guard()->copyHiddenTags($file, $this->node(500, '/alice/files/b', '/alice/', $this->root));
    }

    /**
     * D7 allows a folder rename only inside its real parent. bob's received folder S is a mount root whose parent
     * core reports with alice's id of its source parent: if that id equals another folder bob can name, the borrowed
     * id must not make a move into that folder look like a rename in place.
     *
     * @return array{Folder, Folder} bob's folder and the received folder S whose parent carries alice's id 300 */
    private function receivedFolderBesideP(): array {
        $bobFiles = $this->home('bob', 98, 99);
        $this->byPath['/bob'] = $bobFiles->getParent();
        $sharedParent = $this->node(300, '/bob/files/P', '/bob/files/P/', $bobFiles, true);
        $received = $this->node(301, '/bob/files/S', '/bob/files/S/', $this->lazyFiles('bob', 300), true);
        $bobFiles->method('get')->willReturnCallback(fn (string $path) => match ($path) {
            'P' => $sharedParent,
            default => throw new \OCP\Files\NotFoundException(),
        });
        return [$bobFiles, $received];
    }

    public function testFolderRenameGateRefusesAMoveIntoTheFolderWhoseIdTheMountRootBorrows(): void {
        [$bobFiles, $received] = $this->receivedFolderBesideP();

        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        \OCA\Mcp\Modules\Files\Access\FolderExposureGate::assertMove($bobFiles, $received, '/P/S', $this->guard());
    }

    public function testFolderRenameGateAllowsARenameOfAMountRootInItsRealParent(): void {
        [$bobFiles, $received] = $this->receivedFolderBesideP();

        \OCA\Mcp\Modules\Files\Access\FolderExposureGate::assertMove($bobFiles, $received, '/S-renamed', $this->guard());
        $this->addToAssertionCount(1);
    }
}
