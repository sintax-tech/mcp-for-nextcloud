<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Access\Visibility;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use OCA\Mcp\Access\Visibility\HiddenTagsUnavailableException;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Support\Platform\FileAccess\FakeTree;
use OCA\Mcp\Modules\Files\Editing\FileBackup;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;

final class VisibilityGuardTest extends TestCase {
    private InMemoryConfig $config;
    private $tagMapper;
    private FakeTree $tree;
    private array $tagAssignments = [];

    protected function setUp(): void {
        parent::setUp();
        $this->config = new InMemoryConfig();
        $this->tree = new FakeTree($this, '/alice/files');
        $this->tagAssignments = [];

        $this->tagMapper = $this->createMock(ISystemTagObjectMapper::class);
        $this->tagMapper->method('getTagIdsForObjects')->willReturnCallback(function (array $objIds, string $objectType): array {
            $result = [];
            foreach ($objIds as $id) {
                $result[(string)$id] = $this->tagAssignments[(string)$id] ?? [];
            }
            return $result;
        });
        $this->tagMapper->method('assignTags')->willReturnCallback(function (string $objId, string $objectType, array $tagIds): void {
            $existing = $this->tagAssignments[$objId] ?? [];
            $this->tagAssignments[$objId] = array_values(array_unique(array_merge($existing, array_map(strval(...), $tagIds))));
        });
    }

    /** V13: an unavailable config is never treated as an empty exclusion list or cached. */
    public function testV13ConfigurationReadFailureIsRefusedAndRetried(): void {
        $config = $this->createMock(\OCP\IConfig::class);
        $calls = 0;
        $config->method('getAppValue')->willReturnCallback(function () use (&$calls): string {
            if (++$calls === 1) {
                throw new \RuntimeException('/private/config');
            }
            return '["42"]';
        });
        $this->tree->addFile('/alice/files/plain', 'x');
        $guard = new VisibilityGuard($config, $this->tagMapper);
        $this->assertFalse($guard->isVisible($this->tree->node('/alice/files/plain')));
        $this->assertTrue($guard->isVisible($this->tree->node('/alice/files/plain')));
        $this->assertSame(2, $calls);
    }

    /** V13: incomplete or cyclic ancestry must not become a visible cache entry. */
    public function testV13UnresolvedAncestryIsRefusedOnEveryAttempt(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        foreach ([0, 20] as $id) {
            $node = $this->createMock(Folder::class);
            $node->method('getId')->willReturn($id);
            $node->method('getParent')->willReturn($node);
            $node->method('getStorage')->willReturn($this->storage(false));
            $guard = $this->guard();
            $this->assertFalse($guard->isVisible($node));
            $this->assertFalse($guard->isVisible($node));
            try {
                $guard->copyHiddenTags($node, $this->createMock(Node::class));
                $this->fail('Backup tagging must refuse unresolved ancestry');
            } catch (ToolFailure $e) {
                $this->assertSame(\OCA\Mcp\Platform\FileAccess\BackupMessages::failed(), $e->getMessage());
            }
        }
    }

    /** V7/V13: a directly excluded root protects even a wholly missing destination. */
    public function testV13WritePreflightChecksRootBeforeMissingAncestors(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $this->tagAssignments[(string)$this->tree->rootFolder()->getId()] = ['42'];
        $this->assertFalse($this->guard()->isPathAllowedForWrite($this->tree->rootFolder(), '/new/deep/item'));
        $this->assertSame([], $this->tree->ops);
    }

    /** V13: a visible mounted alias must not authorize another alias below an excluded parent. */
    public function testV13VisibilityCacheIsQualifiedByMountedPath(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $root = $this->createMock(\OCP\Files\IRootFolder::class);
        $plain = $this->ancestryNode(10, $root, null, false, '/plain');
        $hidden = $this->ancestryNode(20, $root, null, false, '/hidden');
        $first = $this->ancestryNode(30, $plain, null, false, '/plain/item');
        $alias = $this->ancestryNode(30, $hidden, null, false, '/hidden/item');
        $this->tagAssignments['20'] = ['42'];
        $guard = $this->guard();
        $this->assertTrue($guard->isVisible($first));
        $this->assertFalse($guard->isVisible($alias));
        $this->assertTrue($guard->isVisible($first));
    }

    /** V13: metadata failure after tag lookup must not leave a positive cache entry. */
    public function testV13LateMetadataFailureNeverCachesVisible(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $root = $this->createMock(\OCP\Files\IRootFolder::class);
        $node = $this->ancestryNode(30, $root, null, false, '/item');
        $node->method('getName')->willThrowException(new \RuntimeException('/private/item'));
        $guard = $this->guard();
        $this->assertFalse($guard->isVisible($node));
        $this->assertFalse($guard->isVisible($node));
    }

    /** V3/V14 contract: the active guard invalidates both positive and negative tag decisions. */
    public function testV14TagEventsInvalidateTheSameGuard(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $id = $this->tree->addFile('/alice/files/item', 'x');
        $listeners = [];
        $events = $this->createMock(\OCP\EventDispatcher\IEventDispatcher::class);
        $events->method('addListener')->willReturnCallback(function (string $name, callable $listener) use (&$listeners): void {
            $listeners[$name] = $listener;
        });
        $guard = new VisibilityGuard($this->config->mock($this), $this->tagMapper, $this->tree->filesystemRoot(), null, null, $events);
        $node = $this->tree->node('/alice/files/item');
        $this->assertTrue($guard->isVisible($node));
        $this->tagAssignments[(string)$id] = ['42'];
        $listeners[\OCP\SystemTag\MapperEvent::EVENT_ASSIGN]();
        $this->assertFalse($guard->isVisible($node));
        unset($this->tagAssignments[(string)$id]);
        $listeners[\OCP\SystemTag\MapperEvent::EVENT_UNASSIGN]();
        $this->assertTrue($guard->isVisible($node));
    }

    /** V13: failed destination existence checks are refusals, including repeated calls. */
    public function testV13WritePreflightResolutionFailureIsRefused(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $root = $this->createMock(\OCP\Files\IRootFolder::class);
        $folder = $this->ancestryNode(10, $root, null);
        $folder->method('nodeExists')->willThrowException(new \RuntimeException('/private/destination'));
        $guard = $this->guard();
        $this->assertFalse($guard->isPathAllowedForWrite($folder, '/new/deep/file'));
        $this->assertFalse($guard->isPathAllowedForWrite($folder, '/new/deep/file'));
    }

    public function testMovingIntoHiddenFolderStaleUntilClear(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $hidden = $this->tree->addFolder('/alice/files/secret');
        $this->tagAssignments[(string)$hidden] = ['42'];
        $this->tree->addFile('/alice/files/file', 'x');
        $file = $this->tree->node('/alice/files/file');
        $guard = $this->guardWithMutationEvents($file);
        $this->assertTrue($guard->isVisible($file));
        $file->move('/alice/files/secret/file');
        $this->assertFalse($guard->isVisible($this->tree->node('/alice/files/secret/file')));
    }

    public function testExternalMoveOutOfHiddenFolderRefreshesVisibility(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $hidden = $this->tree->addFolder('/alice/files/secret');
        $this->tagAssignments[(string)$hidden] = ['42'];
        $this->tree->addFile('/alice/files/secret/file', 'x');
        $file = $this->tree->node('/alice/files/secret/file');
        $guard = $this->guardWithMutationEvents($file);
        $this->assertFalse($guard->isVisible($file));
        $file->move('/alice/files/file');
        $this->assertTrue($guard->isVisible($this->tree->node('/alice/files/file')));
    }

    /** FakeTree emits the same post-rename event as Nextcloud's HookConnector. */
    private function guardWithMutationEvents(Node $source): VisibilityGuard {
        $listeners = [];
        $events = $this->createMock(\OCP\EventDispatcher\IEventDispatcher::class);
        $events->method('addListener')->willReturnCallback(function (string $name, callable $listener) use (&$listeners): void {
            $listeners[$name][] = $listener;
        });
        $events->method('dispatchTyped')->willReturnCallback(function (\OCP\EventDispatcher\Event $event) use (&$listeners): void {
            foreach ($listeners[$event::class] ?? [] as $listener) {
                $listener($event);
            }
        });
        $this->tree->afterMove = function (string $from, string $to) use ($events, $source): void {
            $events->dispatchTyped(new \OCP\Files\Events\Node\NodeRenamedEvent($source, $this->tree->node($to)));
        };
        return new VisibilityGuard($this->config->mock($this), $this->tagMapper, $this->tree->filesystemRoot(), null, null, $events);
    }

    public function testPartialMutationFailureStillInvalidatesVisibility(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $hidden = $this->tree->addFolder('/alice/files/secret');
        $this->tagAssignments[(string)$hidden] = ['42'];
        $this->tree->addFile('/alice/files/file', 'x');
        $guard = $this->guard();
        $this->assertTrue($guard->isVisible($this->tree->node('/alice/files/file')));
        try {
            \OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess::mutate(function (): void {
                $this->tree->node('/alice/files/file')->move('/alice/files/secret/file');
                throw new \RuntimeException('Mutation already happened');
            }, $guard);
            $this->fail('The storage exception must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('Mutation already happened', $e->getMessage());
        }
        $this->assertFalse($guard->isVisible($this->tree->node('/alice/files/secret/file')));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function mutationEvents(): iterable {
        foreach (['NodeRenamedEvent', 'NodeCopiedEvent', 'NodeCreatedEvent', 'NodeDeletedEvent', 'NodeWrittenEvent'] as $name) {
            yield $name => ['OCP\\Files\\Events\\Node\\' . $name, in_array($name, ['NodeRenamedEvent', 'NodeCopiedEvent'], true)];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mutationEvents')]
    public function testEveryMutationEventInvalidatesVisibility(string $class, bool $twoNodes): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $id = $this->tree->addFile('/alice/files/file', 'x');
        $file = $this->tree->node('/alice/files/file');
        $listeners = [];
        $events = $this->createMock(\OCP\EventDispatcher\IEventDispatcher::class);
        $events->method('addListener')->willReturnCallback(function (string $name, callable $listener, int $priority) use (&$listeners): void {
            $this->assertSame(PHP_INT_MIN, $priority);
            $listeners[$name] = $listener;
        });
        $guard = new VisibilityGuard($this->config->mock($this), $this->tagMapper, $this->tree->filesystemRoot(), null, null, $events);
        $this->assertTrue($guard->isVisible($file));
        $this->tagAssignments[(string)$id] = ['42'];
        $event = $twoNodes ? new $class($file, $file) : new $class($file);
        $listeners[$class]($event);
        $this->assertFalse($guard->isVisible($file));
    }

    public function testMapperAssignmentsAndRemovalsInvalidateVisibility(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $id = $this->tree->addFile('/alice/files/file', 'x');
        $listeners = [];
        $events = $this->createMock(\OCP\EventDispatcher\IEventDispatcher::class);
        $events->method('addListener')->willReturnCallback(static function (string $name, callable $listener) use (&$listeners): void {
            $listeners[$name] = $listener;
        });
        $guard = new VisibilityGuard($this->config->mock($this), $this->tagMapper, $this->tree->filesystemRoot(), null, null, $events);
        $file = $this->tree->node('/alice/files/file');
        $this->assertTrue($guard->isVisible($file));
        $this->assertArrayHasKey(\OCP\SystemTag\MapperEvent::EVENT_ASSIGN, $listeners);
        $this->assertArrayHasKey(\OCP\SystemTag\MapperEvent::EVENT_UNASSIGN, $listeners);
        $this->assertSame($listeners[\OCP\SystemTag\MapperEvent::EVENT_ASSIGN], $listeners[\OCP\SystemTag\MapperEvent::EVENT_UNASSIGN]);
        $this->tagAssignments[(string)$id] = ['42'];
        $listeners[\OCP\SystemTag\MapperEvent::EVENT_ASSIGN](new \OCP\SystemTag\MapperEvent(\OCP\SystemTag\MapperEvent::EVENT_ASSIGN, 'files', (string)$id, [42]));
        $this->assertFalse($guard->isVisible($file));
        $this->tagAssignments[(string)$id] = [];
        $listeners[\OCP\SystemTag\MapperEvent::EVENT_UNASSIGN](new \OCP\SystemTag\MapperEvent(\OCP\SystemTag\MapperEvent::EVENT_UNASSIGN, 'files', (string)$id, [42]));
        $this->assertTrue($guard->isVisible($file));
    }

    private function guard(): VisibilityGuard {
        return new VisibilityGuard($this->config->mock($this), $this->tagMapper, $this->tree->filesystemRoot());
    }

    public function testZeroCostWhenNoTagsConfigured(): void {
        $guard = $this->guard();
        $this->assertSame([], $guard->getHiddenTagIds());

        $fileId = $this->tree->addFile('/alice/files/doc.txt', 'hello');
        $file = $this->tree->node('/alice/files/doc.txt');

        // tagMapper should not even be called if hidden tags is empty
        $this->assertTrue($guard->isVisible($file));
        $guard->assertVisible($file);
        $this->assertSame([$file], $guard->filter([$file]));
    }

    public function testTagLookupFailureIsHiddenAndRetriedWithoutLeakingNames(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $mapper = $this->createMock(ISystemTagObjectMapper::class);
        $calls = 0;
        $mapper->expects($this->exactly(2))->method('getTagIdsForObjects')->willReturnCallback(
            function () use (&$calls): array {
                if (++$calls === 1) {
                    throw new \RuntimeException('secret.txt');
                }
                return [];
            }
        );
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            'MCP visibility check failed; access refused', ['app' => 'mcp']
        );
        $node = $this->tree->rootFolder();
        $guard = new VisibilityGuard($this->config->mock($this), $mapper, $this->tree->filesystemRoot(), null, $logger);
        $this->assertFalse($guard->isVisible($node));
        $this->assertTrue($guard->isVisible($node));
    }

    public function testParentFailureIsHiddenAndNeverCachedAsVisible(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $node = $this->createMock(Node::class);
        $node->method('getId')->willReturn(123);
        $node->method('getStorage')->willReturn($this->storage(false));
        $node->expects($this->exactly(2))->method('getParent')->willThrowException(new \RuntimeException());
        $mapper = $this->createMock(ISystemTagObjectMapper::class);
        $mapper->expects($this->never())->method('getTagIdsForObjects');
        $owner = $this->createMock(\OCP\IUser::class);
        $owner->method('getUID')->willReturn('alice');
        $node->method('getOwner')->willReturn($owner);
        $folder = $this->createMock(Folder::class);
        $folder->method('getId')->willReturn(1);
        $folder->method('getById')->willReturn([$node]);
        $root = $this->createMock(\OCP\Files\IRootFolder::class);
        $root->method('getUserFolder')->willReturn($folder);
        $guard = new VisibilityGuard($this->config->mock($this), $mapper, $root);
        $this->assertFalse($guard->isVisible($node));
        $this->assertFalse($guard->isVisible($node));
    }

    public function testCopyHiddenTagsAbortsOnAncestryFailure(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $source = $this->createMock(Node::class);
        $source->method('getId')->willReturn(123);
        $source->method('getParent')->willThrowException(new \RuntimeException());
        $destination = $this->createMock(Node::class);
        $mapper = $this->createMock(ISystemTagObjectMapper::class);
        $mapper->method('getTagIdsForObjects')->willReturn(['123' => ['42']]);
        $mapper->expects($this->never())->method('assignTags');
        $guard = new VisibilityGuard($this->config->mock($this), $mapper);
        $this->expectException(ToolFailure::class);
        $guard->copyHiddenTags($source, $destination);
    }

    public function testDirectlyTaggedFileIsHidden(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['42', '99']);
        $guard = $this->guard();
        $this->assertSame(['42', '99'], $guard->getHiddenTagIds());

        $fileId = $this->tree->addFile('/alice/files/secret.txt', 'secret content');
        $file = $this->tree->node('/alice/files/secret.txt');
        $this->tagAssignments[(string)$fileId] = ['42'];

        $this->assertFalse($guard->isVisible($file));

        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(FileAccessMessages::notFound());
        $guard->assertVisible($file);
    }

    public function testAncestralFolderTaggedHidesChildren(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['10']);
        $guard = $this->guard();

        $this->tree->addFolder('/alice/files/Confidential');
        $folder = $this->tree->node('/alice/files/Confidential');
        $folderId = $folder->getId();
        $this->tagAssignments[(string)$folderId] = ['10'];

        $fileId = $this->tree->addFile('/alice/files/Confidential/sub.txt', 'sub content');
        $file = $this->tree->node('/alice/files/Confidential/sub.txt');

        $this->assertFalse($guard->isVisible($folder));
        $this->assertFalse($guard->isVisible($file));

        $normalId = $this->tree->addFile('/alice/files/normal.txt', 'normal content');
        $normal = $this->tree->node('/alice/files/normal.txt');
        $this->assertTrue($guard->isVisible($normal));

        $filtered = $guard->filter([$file, $normal, $folder]);
        $this->assertSame([$normal], $filtered);
    }

    public function testBackupIsHiddenWhenOriginalExistsAndIsHidden(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['7']);
        $guard = $this->guard();

        $origId = $this->tree->addFile('/alice/files/docs/secret.txt', 'secret');
        $orig = $this->tree->node('/alice/files/docs/secret.txt');
        $this->tagAssignments[(string)$origId] = ['7'];

        // Backup mirror path
        $backupPath = '/alice/files/' . \OCA\Mcp\Platform\FileAccess\BackupLocation::FOLDER . '/docs/secret.txt.20261001-120000.bak';
        $backupId = $this->tree->addFile($backupPath, 'secret');
        $backup = $this->tree->node($backupPath);

        $this->assertFalse($guard->isVisible($orig));
        $this->assertFalse($guard->isVisible($backup));
    }

    public function testBackupRemainsHiddenAfterCopyHiddenTagsEvenIfOriginalDeleted(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['7']);
        $guard = $this->guard();

        $origId = $this->tree->addFile('/alice/files/secret.txt', 'secret');
        $orig = $this->tree->node('/alice/files/secret.txt');
        $this->tagAssignments[(string)$origId] = ['7'];

        $backupPath = '/alice/files/' . \OCA\Mcp\Platform\FileAccess\BackupLocation::FOLDER . '/secret.txt.20261001-120000.bak';
        $backupId = $this->tree->addFile($backupPath, 'secret');
        $backup = $this->tree->node($backupPath);

        // Copy hidden tags to backup
        $guard->copyHiddenTags($orig, $backup);
        $this->assertContains('7', $this->tagAssignments[(string)$backupId]);

        // Now simulate deleting the original
        $orig->delete();
        $guard->clearCache();

        $this->assertFalse($guard->isVisible($backup));
    }

    public function testIsPathAllowedForWriteRefusesHiddenTargetOrAncestor(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['5']);
        $guard = $this->guard();
        $root = $this->tree->rootFolder();

        $this->tree->addFolder('/alice/files/Private');
        $privateFolder = $this->tree->node('/alice/files/Private');
        $this->tagAssignments[(string)$privateFolder->getId()] = ['5'];

        $this->assertFalse($guard->isPathAllowedForWrite($root, '/Private'));
        $this->assertFalse($guard->isPathAllowedForWrite($root, '/Private/sub/newfile.txt'));
        $this->assertTrue($guard->isPathAllowedForWrite($root, '/Public/newfile.txt'));
    }

    public function testSetHiddenTagIdsRejectsNonPositiveInteger(): void {
        $guard = $this->guard();
        $this->expectException(\InvalidArgumentException::class);
        $guard->setHiddenTagIds(['not-an-int']);
    }

    public function testSetHiddenTagIdsRejectsZero(): void {
        $guard = $this->guard();
        $this->expectException(\InvalidArgumentException::class);
        $guard->setHiddenTagIds(['0']);
    }

    public function testSetHiddenTagIdsRejectsNegative(): void {
        $guard = $this->guard();
        $this->expectException(\InvalidArgumentException::class);
        $guard->setHiddenTagIds(['-4']);
    }

    public function testSetHiddenTagIdsFiltersOutNonExistentTagsWhenTagManagerPresent(): void {
        $tagManager = $this->createMock(\OCP\SystemTag\ISystemTagManager::class);
        $tag1 = $this->createMock(\OCP\SystemTag\ISystemTag::class);
        $tag1->method('getId')->willReturn('10');

        $tagManager->method('getTagsByIds')->willReturn(['10' => $tag1]);

        $guard = new VisibilityGuard(
            $this->config->mock($this),
            $this->tagMapper,
            null,
            $tagManager
        );

        $guard->setHiddenTagIds(['10', '99']);
        $this->assertSame(['10'], $guard->getHiddenTagIds());
    }

    /**
     * Regression (#5): walking up past the user folder reaches Nextcloud's Root, whose
     * getParent() throws by design; that must not be read as a lookup failure.
     */
    public function testOrdinaryFileStaysVisibleWhenWalkReachesFilesystemRoot(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['7']);
        $guard = $this->guard();

        $this->tree->addFile('/alice/files/docs/report.txt', 'report');
        $file = $this->tree->node('/alice/files/docs/report.txt');

        $this->assertTrue($guard->isVisible($file));
        $guard->assertVisible($file);
        $this->assertSame([$file], $guard->filter([$file]));
    }

    public function testBackupOfHiddenOriginalStaysHiddenWithFilesystemRoot(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['7']);
        $guard = $this->guard();

        $origId = $this->tree->addFile('/alice/files/docs/secret.txt', 'secret');
        $this->tagAssignments[(string)$origId] = ['7'];
        $backupPath = '/alice/files/' . \OCA\Mcp\Platform\FileAccess\BackupLocation::FOLDER . '/docs/secret.txt.20261001-120000.bak';
        $this->tree->addFile($backupPath, 'secret');

        $this->assertFalse($guard->isVisible($this->tree->node($backupPath)));
    }

    public function testBackupOfVisibleOriginalIsVisibleWithFilesystemRoot(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['7']);
        $guard = $this->guard();

        $this->tree->addFile('/alice/files/docs/report.txt', 'report');
        $backupPath = '/alice/files/' . \OCA\Mcp\Platform\FileAccess\BackupLocation::FOLDER . '/docs/report.txt.20261001-120000.bak';
        $this->tree->addFile($backupPath, 'report');

        $this->assertTrue($guard->isVisible($this->tree->node('/alice/files/docs/report.txt')));
        $this->assertTrue($guard->isVisible($this->tree->node($backupPath)));
    }

    /** A3: a tag manager that cannot answer refuses the save and keeps the previous protection. */
    public function testTagManagerFailureKeepsProtection(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $guard = new VisibilityGuard($this->config->mock($this), $this->tagMapper, null, $this->failingTagManager());

        $refused = false;
        try {
            $guard->setHiddenTagIds(['42', '7']);
        } catch (HiddenTagsUnavailableException) {
            $refused = true;
        }

        $this->assertTrue($refused, 'the save must be refused while the tags cannot be checked');

        $this->assertSame('["42"]', $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY]);
        $this->assertSame(['42'], $guard->getHiddenTagIds());
    }

    public function testExplicitRemovalOfAllTagsNeedsNoTagManager(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $guard = new VisibilityGuard($this->config->mock($this), $this->tagMapper, null, $this->failingTagManager());

        $guard->setHiddenTagIds([]);

        $this->assertSame('[]', $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY]);
        $this->assertSame([], $guard->getHiddenTagIds());
    }

    /** A9: a stored value that is not a list of tag ids refuses every node, and is left as it is. */
    public function testMalformedConfigurationRefusesAccess(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"';
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            'MCP hidden tags configuration is invalid; access refused', ['app' => 'mcp']
        );
        $guard = new VisibilityGuard($this->config->mock($this), $this->tagMapper, null, null, $logger);

        $secretId = $this->tree->addFile('/alice/files/secret.txt', 'secret');
        $this->tagAssignments[(string)$secretId] = ['42'];
        $this->tree->addFile('/alice/files/plain.txt', 'plain');

        $this->assertFalse($guard->isVisible($this->tree->node('/alice/files/secret.txt')));
        $this->assertFalse($guard->isVisible($this->tree->node('/alice/files/plain.txt')));
        $this->assertSame([], $guard->filter([$this->tree->node('/alice/files/plain.txt')]));
        $this->assertFalse($guard->isPathAllowedForWrite($this->tree->rootFolder(), '/new.txt'));
        $this->assertFalse($guard->isConfigurationValid());
        $this->assertSame('["42"', $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedConfigurations(): iterable {
        yield 'truncated json' => ['["42"'];
        yield 'not a list' => ['{"a":"42"}'];
        yield 'scalar' => ['42'];
        yield 'empty string' => [''];
        yield 'non numeric id' => ['["secret"]'];
        yield 'nested list' => ['[["42"]]'];
        yield 'zero id' => ['["0"]'];
    }

    /** @dataProvider malformedConfigurations */
    public function testEveryMalformedConfigurationIsInvalid(string $raw): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = $raw;
        $guard = $this->guard();
        $this->tree->addFile('/alice/files/plain.txt', 'plain');

        $this->assertFalse($guard->isConfigurationValid());
        $this->assertFalse($guard->isVisible($this->tree->node('/alice/files/plain.txt')));
    }

    public function testMalformedConfigurationRefusesBackupTagCopy(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"';
        $guard = $this->guard();
        $this->tree->addFile('/alice/files/a.txt', 'a');
        $this->tree->addFile('/alice/files/b.txt', 'b');

        $this->expectException(ToolFailure::class);
        $guard->copyHiddenTags($this->tree->node('/alice/files/a.txt'), $this->tree->node('/alice/files/b.txt'));
    }

    public function testValidEmptyConfigurationMeansNoProtection(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '[]';
        $guard = $this->guard();
        $this->tree->addFile('/alice/files/plain.txt', 'plain');

        $this->assertTrue($guard->isConfigurationValid());
        $this->assertTrue($guard->isVisible($this->tree->node('/alice/files/plain.txt')));
    }

    public function testSavingAgainRepairsMalformedConfiguration(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"';
        $guard = $this->guard();
        $this->tree->addFile('/alice/files/plain.txt', 'plain');
        $this->assertFalse($guard->isVisible($this->tree->node('/alice/files/plain.txt')));

        $guard->setHiddenTagIds(['42']);

        $this->assertTrue($guard->isConfigurationValid());
        $this->assertSame('["42"]', $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY]);
        $this->assertTrue($guard->isVisible($this->tree->node('/alice/files/plain.txt')));
    }

    private function failingTagManager(): \OCP\SystemTag\ISystemTagManager {
        $manager = $this->createMock(\OCP\SystemTag\ISystemTagManager::class);
        $manager->method('getTagsByIds')->willThrowException(new \RuntimeException('down'));
        $manager->method('getAllTags')->willThrowException(new \RuntimeException('down'));
        return $manager;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function objectConfigurations(): iterable {
        yield 'empty object' => ['{}'];
        yield 'object with list-like keys' => ['{"0":"42"}'];
    }

    /**
     * A JSON object is not the list the admin page saves, even when decoding it as an array would look like one:
     * every path of the guard refuses, the log names no value and the value stays as it was.
     *
     * @dataProvider objectConfigurations
     */
    public function testJsonObjectConfigurationIsInvalidOnEveryPath(string $raw): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = $raw;
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            'MCP hidden tags configuration is invalid; access refused', ['app' => 'mcp']
        );
        $guard = new VisibilityGuard($this->config->mock($this), $this->tagMapper, null, null, $logger);
        $this->tree->addFile('/alice/files/plain.txt', 'plain');
        $this->tree->addFile('/alice/files/copy.txt', 'copy');
        $plain = $this->tree->node('/alice/files/plain.txt');

        $this->assertFalse($guard->isVisible($plain));
        $this->assertSame([], $guard->filter([$plain]));
        $this->assertFalse($guard->isPathAllowedForWrite($this->tree->rootFolder(), '/new.txt'));
        try {
            $guard->assertVisible($plain);
            $this->fail('assertVisible must refuse');
        } catch (ToolFailure $e) {
            $this->assertSame(FileAccessMessages::notFound(), $e->getMessage());
        }
        try {
            $guard->copyHiddenTags($plain, $this->tree->node('/alice/files/copy.txt'));
            $this->fail('copyHiddenTags must refuse');
        } catch (ToolFailure $e) {
            $this->assertStringNotContainsString('42', $e->getMessage());
        }
        $this->assertFalse($guard->isConfigurationValid());
        $this->assertSame($raw, $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY]);
    }

    public function testAPathUnderAHiddenFolderNamedZeroIsNotAllowedForWrite(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['5']);
        $guard = $this->guard();
        $zero = $this->tree->addFolder('/alice/files/Docs/0');
        $this->tagAssignments[(string)$zero] = ['5'];

        $this->assertFalse($guard->isPathAllowedForWrite($this->tree->rootFolder(), 'Docs/0/new'));
        $this->assertTrue($guard->isPathAllowedForWrite($this->tree->rootFolder(), 'Docs/1/new'));
    }

    /** Same file id, physical chain 30→20→10, recipient chain 30→20→90. */
    public function testSharedFileKeepsHiddenOwnerAncestor(): void {
        [$guard, $alias] = $this->sharedFixture(false, 1, false, true, true);
        $this->tagAssignments['10'] = ['42'];
        $this->assertFalse($guard->isVisible($alias));
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(FileAccessMessages::notFound());
        $guard->assertVisible($alias);
    }

    /** A subfolder mount exposes 20 and its child 30, but omits hidden grandparent 10. */
    public function testSharedSubfolderChildKeepsHiddenOwnerGrandparent(): void {
        [$guard, $alias, $subfolder] = $this->sharedFixture(false, 1);
        $this->tagAssignments['10'] = ['42'];
        $this->assertFalse($guard->isVisible($alias));
        $this->assertFalse($guard->isVisible($subfolder));
    }

    public function testSharedVisibleFileRemainsVisibleAndOwnerLookupIsCached(): void {
        [$guard, $alias] = $this->sharedFixture();
        $this->assertTrue($guard->isVisible($alias));
        $this->assertTrue($guard->isVisible($alias));
    }

    public function testSharedFileOwnerResolutionFailureIsHiddenWithoutDetails(): void {
        [$guard, $alias] = $this->sharedFixture(true);
        $this->assertFalse($guard->isVisible($alias));
        try {
            $guard->assertVisible($alias);
            $this->fail('Owner resolution must refuse access');
        } catch (ToolFailure $e) {
            $this->assertSame(FileAccessMessages::notFound(), $e->getMessage());
        }
    }

    public function testSharedFileChecksAllOwnerAliases(): void {
        [$guard, $alias] = $this->sharedFixture(false, 1, true);
        $this->tagAssignments['10'] = ['42'];
        $this->assertFalse($guard->isVisible($alias));
    }

    public function testSharedFileStillChecksMountedAncestors(): void {
        [$guard, $alias] = $this->sharedFixture();
        $this->tagAssignments['90'] = ['42'];
        $this->assertFalse($guard->isVisible($alias));
    }

    public function testSharedFileDirectAndImmediateParentTagsStillHideIt(): void {
        foreach (['30', '20'] as $id) {
            [$guard, $alias] = $this->sharedFixture();
            $this->tagAssignments = [$id => ['42']];
            $this->assertFalse($guard->isVisible($alias));
        }
    }

    public function testMissingRootResolverRefusesAccess(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $this->tree->addFile('/alice/files/report.txt', 'report', 'text/plain', ['scope' => 'shared']);
        $guard = new VisibilityGuard($this->config->mock($this), $this->tagMapper);
        $this->assertFalse($guard->isVisible($this->tree->node('/alice/files/report.txt')));
    }

    public function testMissingOwnerRefusesAccess(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $node = $this->createMock(Node::class);
        $node->method('getId')->willReturn(30);
        $node->method('getStorage')->willReturn($this->storage(true));
        $node->method('getOwner')->willReturn(null);
        $this->assertFalse($this->guard()->isVisible($node));
    }

    public function testMissingOwnerNodeRefusesAccess(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $owner = $this->createMock(\OCP\IUser::class);
        $owner->method('getUID')->willReturn('alice');
        $node = $this->createMock(Node::class);
        $node->method('getId')->willReturn(30);
        $node->method('getStorage')->willReturn($this->storage(true));
        $node->method('getOwner')->willReturn($owner);
        $this->assertFalse($this->guard()->isVisible($node));
    }

    public function testGroupFolderWithoutOwnerStaysVisible(): void {
        [$guard, $node] = $this->nonLocalShareFixture(null);
        $this->assertTrue($guard->isVisible($node));
    }

    public function testGroupFolderWithMountOwnerStaysVisible(): void {
        [$guard, $node] = $this->nonLocalShareFixture('alice');
        $this->assertTrue($guard->isVisible($node));
    }

    public function testExternalStorageStaysVisibleWithoutOwnerLookup(): void {
        [$guard, $node] = $this->nonLocalShareFixture('external-mounter');
        $this->assertTrue($guard->isVisible($node));
        $this->tagAssignments['20'] = ['42'];
        $guard->clearCache();
        $this->assertFalse($guard->isVisible($node), 'Local external mount tags still apply');
    }

    public function testFederatedShareStaysVisibleWithoutLocalOwnerFolder(): void {
        // Core External\Storage implements the legacy OCA sharing interface,
        // not OCP\Files\Storage\ISharedStorage, and its owner is a cloud id.
        [$guard, $node] = $this->nonLocalShareFixture('owner@remote.example');
        $this->assertTrue($guard->isVisible($node));
    }

    public function testFederatedShareStillHonorsLocalHiddenAncestor(): void {
        [$guard, $node] = $this->nonLocalShareFixture('owner@remote.example');
        $this->tagAssignments['20'] = ['42'];
        $this->assertFalse($guard->isVisible($node));
    }

    public function testLocalShareOnMountedAncestorKeepsOwnerProtection(): void {
        [$guard, $node] = $this->sharedFixture(false, 1, false, false);
        $this->tagAssignments['10'] = ['42'];
        $this->assertFalse($guard->isVisible($node));
    }

    public function testSharedSiblingCostResolvesOwnerRootOnce(): void {
        [$guard, $files, $counts] = $this->costFixture(true, 20);
        foreach ($files as $file) {
            $this->assertTrue($guard->isVisible($file));
        }
        $this->assertSame(1, $counts->lookups, 'One owner lookup per share, not per leaf');
        $guard->clearCache();
        $this->assertTrue($guard->isVisible($files[0]));
        $this->assertSame(2, $counts->lookups, 'Cache clearing resolves the root again');
    }

    public function testPersonalSearchCostClassifiesEachAncestorOnce(): void {
        [$guard, $files, $counts] = $this->costFixture(false, 20);
        foreach ($files as $file) {
            $this->assertTrue($guard->isVisible($file));
        }
        foreach ([20, 90] as $id) {
            $this->assertSame(1, $counts->storageCalls[$id], 'Ancestor storage classified once: ' . $id);
        }
    }

    public function testNodeWithoutIdStillChecksSharedParent(): void {
        [$guard, $file, $parent] = $this->sharedFixture();
        $this->tagAssignments['10'] = ['42'];
        $node = $this->ancestryNode(0, $parent, null);
        $this->assertFalse($guard->isVisible($node));
    }

    public function testPersonalClassificationDoesNotBypassShareAliasProtection(): void {
        [$guard, $file, $sharedRoot] = $this->sharedFixture();
        $owner = $this->createMock(\OCP\IUser::class);
        $root = $this->createMock(\OCP\Files\IRootFolder::class);
        $root->method('getId')->willReturn(-1);
        $personalAlias = $this->ancestryNode(20, $root, $owner, false, '/alice/files/alias');
        $this->tagAssignments['10'] = ['42'];
        $this->assertTrue($guard->isVisible($personalAlias));
        $this->assertFalse($guard->isVisible($file));
    }

    public function testCleanTagAncestryShortensSiblingWalksWithoutCachingFinalVisibility(): void {
        $count = 20;
        [$guard, $files, $counts] = $this->costFixture(false, $count);
        foreach ($files as $file) {
            $this->assertTrue($guard->isVisible($file));
        }
        foreach ([20, 90] as $id) {
            // One classification, one tag walk, and one mandatory backup walk per sibling.
            $this->assertSame($count + 2, $counts->parentCalls[$id]);
            $this->assertLessThan(2 * $count + 1, $counts->parentCalls[$id]);
        }
        $this->tagAssignments['20'] = ['42'];
        $guard->clearCache();
        foreach ($files as $file) {
            $this->assertFalse($guard->isVisible($file));
        }
    }

    /** @return array{VisibilityGuard, list<Node>, object} */
    private function costFixture(bool $shared, int $count): array {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $owner = $this->createMock(\OCP\IUser::class);
        $owner->method('getUID')->willReturn('alice');
        $root = $this->createMock(\OCP\Files\IRootFolder::class);
        $root->method('getId')->willReturn(-1);
        $root->expects($this->never())->method('getParent');
        $ownerFolder = $this->ancestryNode(1, $root, $owner);
        $canonicalRoot = $this->ancestryNode(20, $ownerFolder, $owner);
        $counts = (object)['lookups' => 0, 'storageCalls' => [], 'parentCalls' => []];
        $make = function (int $id, Node $parent, bool $isShared) use ($owner, $counts): Node {
            $node = $this->createMock(Folder::class);
            $node->method('getId')->willReturn($id);
            $node->method('getParent')->willReturnCallback(function () use ($parent, $id, $counts): Node {
                $counts->parentCalls[$id] = ($counts->parentCalls[$id] ?? 0) + 1;
                return $parent;
            });
            $node->method('getOwner')->willReturn($owner);
            $storage = $this->storage($isShared);
            $node->method('getStorage')->willReturnCallback(function () use ($storage, $id, $counts) {
                $counts->storageCalls[$id] = ($counts->storageCalls[$id] ?? 0) + 1;
                return $storage;
            });
            $mount = $this->createMock(\OCP\Files\Mount\IMountPoint::class);
            $mount->method('getMountPoint')->willReturn('/bob/files/share');
            $node->method('getMountPoint')->willReturn($mount);
            return $node;
        };
        $recipient = $make(90, $root, false);
        $mountedRoot = $make(20, $recipient, $shared);
        $files = [];
        $origins = [20 => $canonicalRoot];
        for ($i = 0; $i < $count; $i++) {
            $id = 100 + $i;
            $files[] = $make($id, $mountedRoot, $shared);
            $origins[$id] = $this->ancestryNode($id, $canonicalRoot, $owner);
        }
        $ownerFolder->method('getById')->willReturnCallback(function (int $id) use ($origins, $counts): array {
            $counts->lookups++;
            return [$origins[$id]];
        });
        $root->method('getUserFolder')->willReturn($ownerFolder);
        return [new VisibilityGuard($this->config->mock($this), $this->tagMapper, $root), $files, $counts];
    }

    /** @return array{VisibilityGuard, Node} */
    private function nonLocalShareFixture(?string $uid): array {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $this->tagAssignments['10'] = ['42']; // an unrelated local folder
        $owner = null;
        if ($uid !== null) {
            $owner = $this->createMock(\OCP\IUser::class);
            $owner->method('getUID')->willReturn($uid);
        }
        $root = $this->createMock(\OCP\Files\IRootFolder::class);
        $root->method('getId')->willReturn(-1);
        $root->expects($this->never())->method('getParent');
        $root->expects($this->never())->method('getUserFolder');
        $folder = $this->ancestryNode(20, $root, $owner);
        $folder->expects($this->never())->method('getOwner');
        $node = $this->ancestryNode(30, $folder, $owner);
        $node->expects($this->never())->method('getOwner');
        return [new VisibilityGuard($this->config->mock($this), $this->tagMapper, $root), $node];
    }

    /** @return array{VisibilityGuard, Node, Node} */
    private function sharedFixture(bool $failure = false, int $lookups = 1, bool $multipleOrigins = false, bool $sharedLeaf = true, bool $fileShare = false): array {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $owner = $this->createMock(\OCP\IUser::class);
        $owner->method('getUID')->willReturn('alice');
        $root = $this->createMock(\OCP\Files\IRootFolder::class);
        $root->method('getId')->willReturn(-1);
        $root->expects($this->never())->method('getParent');
        $ownerFolder = $this->ancestryNode(1, $root, $owner);
        $secret = $this->ancestryNode(10, $ownerFolder, $owner);
        $canonicalSub = $this->ancestryNode(20, $secret, $owner);
        $canonicalFile = $this->ancestryNode(30, $canonicalSub, $owner);
        $recipient = $this->ancestryNode(90, $root, $owner, false, '/bob/files');
        // Above the share mount root the guard asks the root for the recipient folder by its path.
        $root->method('get')->willReturnCallback(static fn (string $path) => $path === '/bob/files' ? $recipient : throw new \OCP\Files\NotFoundException());
        $aliasSub = $this->ancestryNode(20, $recipient, $owner, !$fileShare, '/bob/files/share');
        $alias = $this->ancestryNode(30, $aliasSub, $owner, $sharedLeaf, '/bob/files/share/file');
        $visibleOwnerAlias = $this->ancestryNode(30, $ownerFolder, $owner);
        $visibleOwnerSubAlias = $this->ancestryNode(20, $ownerFolder, $owner);
        $root->method('getUserFolder')->with('alice')->willReturn($ownerFolder);
        if ($failure) {
            $ownerFolder->method('getById')->willThrowException(new \RuntimeException('/secret/sub/file'));
        } else {
            $ownerFolder->expects($this->exactly($lookups))->method('getById')->willReturnCallback(
                static fn (int $id): array => match ($id) {
                    30 => $multipleOrigins ? [$visibleOwnerAlias, $canonicalFile] : [$canonicalFile],
                    20 => $multipleOrigins ? [$visibleOwnerSubAlias, $canonicalSub] : [$canonicalSub],
                    default => [],
                }
            );
        }
        return [new VisibilityGuard($this->config->mock($this), $this->tagMapper, $root), $alias, $aliasSub];
    }

    private function ancestryNode(int $id, Node $parent, ?\OCP\IUser $owner, bool $shared = false, string $path = ''): Folder {
        $node = $this->createMock(Folder::class);
        $node->method('getId')->willReturn($id);
        $node->method('getOwner')->willReturn($owner);
        $node->method('getPath')->willReturn($path);
        $node->method('getStorage')->willReturn($this->storage($shared));
        $mount = $this->createMock(\OCP\Files\Mount\IMountPoint::class);
        $mount->method('getMountPoint')->willReturn('/bob/files/share');
        $node->method('getMountPoint')->willReturn($mount);
        $node->method('getParent')->willReturn($parent);
        return $node;
    }

    private function storage(bool $shared): \OCP\Files\Storage\IStorage {
        $storage = $this->createMock(\OCP\Files\Storage\IStorage::class);
        $storage->method('instanceOfStorage')->willReturnCallback(
            static fn (string $class): bool => $shared && $class === \OCP\Files\Storage\ISharedStorage::class
        );
        return $storage;
    }

    // --- explain(): the administrator's reason is the decision of isVisible() (issue #4, A5) ---------------------

    /** @return array{reason:string, folder:?string} explain() with the folder as its path, for comparison */
    private static function explained(VisibilityGuard $guard, Node $node): array {
        $why = $guard->explain($node);
        return ['reason' => $why['reason'], 'folder' => $why['folder']?->getPath()];
    }

    public function testA5ExplainNamesADirectTagAndTheFolderAnExclusionIsInheritedFrom(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['7']);
        $guard = $this->guard();
        $secret = $this->tree->addFolder('/alice/files/Secret');
        $this->tree->addFolder('/alice/files/Secret/Deep');
        $this->tree->addFile('/alice/files/Secret/Deep/plan.md', 'x');
        $this->tree->addFile('/alice/files/open.md', 'x');
        $this->tagAssignments[(string)$secret] = ['7'];

        $cases = [
            '/alice/files/Secret' => ['reason' => VisibilityGuard::EXPLAINED_TAG_DIRECT, 'folder' => null],
            '/alice/files/Secret/Deep/plan.md' => ['reason' => VisibilityGuard::EXPLAINED_TAG_FOLDER, 'folder' => '/alice/files/Secret'],
            '/alice/files/open.md' => ['reason' => VisibilityGuard::EXPLAINED_VISIBLE, 'folder' => null],
        ];
        foreach ($cases as $path => $expected) {
            $node = $this->tree->node($path);
            $this->assertSame($expected, self::explained($guard, $node), $path);
            // The decision the tools get, after an explanation and without one, is the same.
            $this->assertSame($expected['reason'] === VisibilityGuard::EXPLAINED_VISIBLE, $guard->isVisible($node), $path);
            $this->assertSame($expected['reason'] === VisibilityGuard::EXPLAINED_VISIBLE, $this->guard()->isVisible($node), $path);
        }
    }

    public function testA5AnExplanationIsNotHiddenByAReasonCachedEarlier(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['7']);
        $guard = $this->guard();
        $secret = $this->tree->addFolder('/alice/files/Secret');
        $this->tree->addFile('/alice/files/Secret/a.md', 'x');
        $this->tagAssignments[(string)$secret] = ['7'];
        $this->assertFalse($guard->isVisible($this->tree->node('/alice/files/Secret/a.md')));

        $this->assertSame(VisibilityGuard::EXPLAINED_TAG_FOLDER, $guard->explain($this->tree->node('/alice/files/Secret/a.md'))['reason']);
    }

    public function testA5ExplainNamesTheShareOrigin(): void {
        [$guard, $alias] = $this->sharedFixture(false, 1, false, true, true);
        $this->tagAssignments['10'] = ['42'];
        $this->assertSame(['reason' => VisibilityGuard::EXPLAINED_TAG_SHARE_ORIGIN, 'folder' => null], self::explained($guard, $alias));
        $this->assertFalse($guard->isVisible($alias));
    }

    public function testA5AnUnresolvedOwnerTreeIsNotEvaluatedNeverVisible(): void {
        [$guard, $alias] = $this->sharedFixture(true);
        $this->assertSame(VisibilityGuard::EXPLAINED_NOT_EVALUATED, $guard->explain($alias)['reason']);
    }

    public function testA5ABackupOfAnExcludedOriginalIsNamedAsSuch(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['7']);
        $guard = $this->guard();
        $original = $this->tree->addFile('/alice/files/docs/secret.txt', 'secret');
        $this->tagAssignments[(string)$original] = ['7'];
        $backup = '/alice/files/' . \OCA\Mcp\Platform\FileAccess\BackupLocation::FOLDER . '/docs/secret.txt.20261001-120000.bak';
        $this->tree->addFile($backup, 'secret');

        // The original's own reason (a direct tag) is not the backup's.
        $this->assertSame(['reason' => VisibilityGuard::EXPLAINED_BACKUP, 'folder' => null], self::explained($guard, $this->tree->node($backup)));
        $this->assertSame(VisibilityGuard::EXPLAINED_TAG_DIRECT, $guard->explain($this->tree->node('/alice/files/docs/secret.txt'))['reason']);
    }

    /** M1 of the 13/A5 review: a backup whose original could not be checked is not evaluated, not "a backup of an excluded file". */
    public function testA5ABackupWhoseOriginalCannotBeEvaluatedIsNotEvaluated(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['7']);
        $original = (string)$this->tree->addFile('/alice/files/docs/plain.txt', 'plain');
        $backup = '/alice/files/' . \OCA\Mcp\Platform\FileAccess\BackupLocation::FOLDER . '/docs/plain.txt.20261001-120000.bak';
        $this->tree->addFile($backup, 'plain');
        $mapper = $this->createMock(ISystemTagObjectMapper::class);
        $mapper->method('getTagIdsForObjects')->willReturnCallback(static function (array $ids) use ($original): array {
            if (in_array($original, array_map('strval', $ids), true)) {
                throw new \RuntimeException('tag read of the original failed');
            }
            return array_fill_keys(array_map('strval', $ids), []);
        });
        $guard = new VisibilityGuard($this->config->mock($this), $mapper, $this->tree->filesystemRoot());

        $this->assertSame(VisibilityGuard::EXPLAINED_NOT_EVALUATED, $guard->explain($this->tree->node($backup))['reason']);
        $this->assertFalse($guard->isVisible($this->tree->node($backup)), 'still refused');
    }

    public function testA5AnInvalidConfigurationAndAFailedTagReadAreNamed(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '{"0":"42"}';
        $this->tree->addFile('/alice/files/plain.txt', 'plain');
        $plain = $this->tree->node('/alice/files/plain.txt');
        $this->assertSame(VisibilityGuard::EXPLAINED_INVALID_CONFIGURATION, $this->guard()->explain($plain)['reason']);

        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $mapper = $this->createMock(ISystemTagObjectMapper::class);
        $mapper->method('getTagIdsForObjects')->willThrowException(new \RuntimeException('down'));
        $guard = new VisibilityGuard($this->config->mock($this), $mapper, $this->tree->filesystemRoot());
        $this->assertSame(VisibilityGuard::EXPLAINED_NOT_EVALUATED, $guard->explain($plain)['reason']);
        $this->assertFalse($guard->isVisible($plain));
    }

    public function testA5WithoutAnyTagEverythingIsVisible(): void {
        $this->tree->addFile('/alice/files/plain.txt', 'plain');
        $this->assertSame(VisibilityGuard::EXPLAINED_VISIBLE, $this->guard()->explain($this->tree->node('/alice/files/plain.txt'))['reason']);
    }
}
