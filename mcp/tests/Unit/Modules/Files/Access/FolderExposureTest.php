<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files\Access;

use OCA\Mcp\Tests\Support\Files\FilesToolsTestCase;
use OCA\Mcp\Modules\Files\Reorganization\ReorganizationMessages;
use OCA\Mcp\Modules\Files\Sharing\SharingMessages;

use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Support\Platform\FileAccess\Locks\FakeLock;
use OCA\Mcp\Platform\FileAccess\FileAccessMessages as CommonMessages;
use OCA\Mcp\Modules\Files\Reorganization\Batch;
use OCA\Mcp\Modules\Files\Access\FolderExposureMessages as FilesMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Lock\ILock;
use OCP\IConfig;
use OCP\Share\IShare;
use OCP\SystemTag\ISystemTagObjectMapper;

/** V7/V12: D6, then D7, then recursion and mutation; permitted paths retain their normal guards. */
final class FolderExposureTest extends FilesToolsTestCase {
    private string $hiddenConfig = '["999"]';
    private array $tagged = [];

    protected function setUp(): void {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(function (): string {
            if ($this->hiddenConfig === 'unreadable') {
                throw new \RuntimeException('config unavailable');
            }
            return $this->hiddenConfig;
        });
        $mapper = $this->createMock(ISystemTagObjectMapper::class);
        $mapper->method('getTagIdsForObjects')->willReturnCallback(fn (array $ids): array => array_combine($ids,
            array_map(fn ($id): array => in_array((int)$id, $this->tagged, true) ? ['999'] : [], $ids)));
        $this->visibilityGuard = \OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this, $config, $mapper, $this->visibilityRoot());
        parent::setUp();
        $this->tree->addFolder('/alice/files/Destination');
        $this->sharePolicy->setGrant('alice', 'files', 'share', true);
        $this->sharePolicy->setGrant('alice', 'files', 'link', true);
    }

    private function config(string $raw): void {
        $this->hiddenConfig = $raw;
        $this->visibilityGuard->clearCache(); // a fresh operation/request
    }

    private function refused(string $name, array $args, bool $confirm): string {
        try {
            $confirm ? $this->tool($name, $args) : $this->plan($name, $args);
            $this->fail('Operation must be refused: ' . $name);
        } catch (ToolFailure $e) {
            return $e->getMessage();
        }
    }

    private function operations(): array {
        return [
            ['files_copy', ['from' => '/Documentos', 'to' => '/Destination/Documentos']],
            ['files_move', ['from' => '/Documentos', 'to' => '/Destination/Documentos']],
            ['files_move_batch', ['moves' => [
                ['from' => '/Documentos/ata.md', 'to' => '/Destination/ata.md'],
                ['from' => '/Documentos', 'to' => '/Destination/Documentos'],
            ], 'mkdirs' => ['/NeverCreated/deep']]],
            ['files_share', ['path' => '/Documentos', 'with' => 'user:bruno']],
            ['files_share', ['path' => '/Documentos', 'with' => 'group:finance']],
            ['files_share', ['path' => '/Documentos', 'with' => 'link']],
        ];
    }

    /** @return iterable<string, array{string, bool}> */
    public static function exclusionStates(): iterable {
        yield 'configured clean folder' => ['["999"]', false];
        yield 'configured protected descendant' => ['["999"]', true];
        yield 'invalid config' => ['{', false];
        yield 'unreadable config' => ['unreadable', false];
    }

    /** @dataProvider exclusionStates */
    public function testRefusalPrecedesRecursiveHelpersAndShareManager(string $raw, bool $hiddenChild): void {
        if ($hiddenChild) {
            $this->tagged[] = $this->tree->nodes['/alice/files/Documentos/ata.md']['id'];
        }
        $this->config($raw);
        $this->tree->beforeListing = static fn () => throw new \LogicException('No recursive helper may run');
        foreach ($this->operations() as [$name, $args]) {
            foreach ([false, true] as $confirm) {
                $message = $this->refused($name, $args, $confirm);
                $expected = $raw === '["999"]' ? FilesMessages::folderExposureRefused() : CommonMessages::notFound();
                $this->assertSame($expected, $message, $name);
            }
        }
        $this->assertSame([], $this->tree->listings, 'measure, lock walks and folder helpers never enumerate');
        $this->assertSame([], $this->locks->asked, 'planNotice/evaluate/assertWritable/run never inspect locks');
        $this->assertSame([], $this->shares->calls);
        $this->assertSame([], $this->shares->lookups);
        $this->assertSame([], $this->shares->ruleReads);
        $this->assertSame([], $this->shares->writes);
        $this->assertSame(0, $this->shares->newShares);
        $this->assertSame([], $this->tree->ops);
        $this->assertSame([], $this->batches->rows);
    }

    public function testDirectlyExcludedFoldersRemainIndistinguishableFromMissing(): void {
        $this->tagged[] = $this->tree->nodes['/alice/files/Documentos']['id'];
        foreach ($this->operations() as [$name, $args]) {
            if ($name === 'files_move_batch') {
                continue;
            }
            foreach ([false, true] as $confirm) {
                $this->visibilityGuard->clearCache();
                $excluded = $this->refused($name, $args, $confirm);
                $nodes = $this->tree->nodes;
                unset($this->tree->nodes['/alice/files/Documentos']);
                $this->visibilityGuard->clearCache();
                $this->assertSame($this->refused($name, $args, $confirm), $excluded);
                $this->assertSame(CommonMessages::notFound(), $excluded);
                $this->tree->nodes = $nodes;
            }
        }
    }

    public function testUndoRefusesBeforeConflictsLocksAndEmptyDirs(): void {
        $id = $this->batches->insert(new Batch(null, 'alice', $this->time->getTime(), [
            ['from' => '/Destination/ata.md', 'to' => '/Documentos/ata.md', 'toId' => $this->tree->nodes['/alice/files/Documentos/ata.md']['id']],
            ['from' => '/Destination/Documentos', 'to' => '/Documentos', 'toId' => $this->tree->nodes['/alice/files/Documentos']['id']],
        ], ['/RecordedEmpty'], null));
        $this->tree->addFolder('/alice/files/RecordedEmpty');
        $this->tree->beforeListing = static fn () => throw new \LogicException('emptyDirs must not run');
        foreach ([false, true] as $confirm) {
            $this->assertSame(FilesMessages::folderExposureRefused(), $this->refused('files_undo_batch', ['batch_id' => $id], $confirm));
        }
        $this->assertSame([], $this->tree->listings);
        $this->assertSame([], $this->locks->asked);
        $this->assertSame([], $this->tree->ops);
        $this->assertNull($this->batches->rows[$id]['undone_at']);
    }

    public function testConfigurationActivatedBetweenPlanAndConfirmRefusesWithoutRecursion(): void {
        foreach ($this->operations() as [$name, $args]) {
            $this->config('[]');
            $plan = $this->plan($name, $args);
            $this->config('["999"]');
            $this->tree->listings = [];
            $this->locks->asked = [];
            $this->shares->calls = $this->shares->ruleReads = [];
            $this->tree->beforeListing = static fn () => throw new \LogicException('No recursion after activation');
            $args += isset($plan['plan_state']) ? ['plan_state' => $plan['plan_state']] : [];
            $this->assertSame(FilesMessages::folderExposureRefused(), $this->refused($name, $args, true));
            $this->assertSame([], $this->tree->listings);
            $this->assertSame([], $this->locks->asked);
            $this->assertSame([], $this->shares->calls);
            $this->assertSame([], $this->shares->ruleReads);
            $this->tree->beforeListing = null;
        }
        $this->assertSame([], $this->tree->ops);
    }

    public function testFolderLinksAreListedWithoutCapabilitiesAndCanStillBeRemoved(): void {
        $folderId = $this->tree->nodes['/alice/files/Documentos']['id'];
        $fileId = $this->tree->nodes['/alice/files/Documentos/ata.md']['id'];
        $folderShare = $this->shares->add(['node' => $folderId, 'nodeType' => 'folder', 'type' => IShare::TYPE_LINK, 'token' => 'FOLDER_SECRET', 'nodeObject' => $this->tree->node('/alice/files/Documentos')]);
        $this->shares->add(['node' => $fileId, 'type' => IShare::TYPE_LINK, 'token' => 'FILE_TOKEN']);
        $path = $this->json('files_list_shares', ['path' => '/Documentos']);
        $this->assertNull($path['shares'][0]['url']);
        $page = $this->json('files_list_shares');
        $this->assertNull($page['shares'][0]['url']);
        $this->assertStringContainsString('FILE_TOKEN', $page['shares'][1]['url']);
        $this->assertStringNotContainsString('FOLDER_SECRET', json_encode($page));
        $this->assertArrayNotHasKey('token', $page['shares'][0]);
        $this->assertSame([], $this->shares->writes);
        $this->assertSame(0, $this->shares->newShares);
        $this->config('[]');
        $path = $this->json('files_list_shares', ['path' => '/Documentos']);
        $this->assertStringContainsString('FOLDER_SECRET', $path['shares'][0]['url']);
        $this->config('["999"]');
        $this->json('files_unshare', ['shareId' => $folderShare->getFullId()]);
        $this->assertSame('delete', $this->shares->writes[0][0]);
    }

    public function testVisibleFilesAndFoldersWithoutExclusionsKeepTheirBehavior(): void {
        $this->json('files_copy', ['from' => '/Documentos/ata.md', 'to' => '/Destination/copied.md']);
        $this->json('files_share', ['path' => '/Documentos/ata.md', 'with' => 'link']);
        $this->config('[]');
        $plan = $this->plan('files_copy', ['from' => '/Documentos', 'to' => '/Destination/Copy']);
        $this->assertGreaterThan(0, $plan['nodes']);
        $this->assertNotEmpty($this->tree->listings, 'permitted copy still measures the tree');
        $this->json('files_copy', ['from' => '/Documentos', 'to' => '/Destination/Copy']);
        $this->json('files_share', ['path' => '/Documentos', 'with' => 'user:bruno']);
        $this->json('files_move', ['from' => '/Destination/Copy', 'to' => '/Moved']);
        $this->assertArrayHasKey('/alice/files/Moved/ata.md', $this->tree->nodes);
    }

    public function testFolderRenameKeepsLocksIncludingALockAppearingAfterThePlan(): void {
        $args = ['from' => '/Documentos', 'to' => '/Renamed'];
        $this->plan('files_move', $args);
        $this->assertTrue($this->plan('files_move_batch', ['moves' => [$args]])['ok']);
        $this->locks->put(new FakeLock($this->tree->nodes['/alice/files/Documentos/ata.md']['id'], ILock::TYPE_USER, 'pedro'));
        $plan = $this->plan('files_move', $args);
        $this->assertTrue($plan['lock']['blocking']);
        $this->assertStringContainsString('locked', $this->refused('files_move', $args, true));
        $batchArgs = ['moves' => [$args]];
        $plan = $this->plan('files_move_batch', $batchArgs);
        $this->assertFalse($plan['ok']);
        $this->assertStringContainsString('locked', $plan['denied'][0]['reason']);
        $this->assertSame(ReorganizationMessages::batchNotOk(0, 1), $this->refused('files_move_batch', $batchArgs, true));
        $this->assertSame([], $this->tree->ops);
    }
    public function testRenameBatchAndUndoStillRunTheirDescendantLockGuards(): void {
        $result = $this->json('files_move_batch', ['moves' => [['from' => '/Documentos', 'to' => '/Renamed']]]);
        $id = $result['batch_id'];
        $this->assertTrue($this->plan('files_undo_batch', ['batch_id' => $id])['ok']);
        $this->tree->ops = [];
        $this->locks->put(new FakeLock($this->tree->nodes['/alice/files/Renamed/ata.md']['id'], ILock::TYPE_USER, 'pedro'));
        $plan = $this->plan('files_undo_batch', ['batch_id' => $id]);
        $this->assertFalse($plan['ok']);
        $this->assertStringContainsString('locked', $plan['conflicts'][0]['reason']);
        $out = $this->json('files_undo_batch', ['batch_id' => $id]);
        $this->assertSame(0, $out['undone']);
        $this->assertSame([], $this->tree->ops);
        $this->locks->locks = [];
        $this->assertSame(1, $this->json('files_undo_batch', ['batch_id' => $id])['undone']);
        $this->assertArrayHasKey('/alice/files/Documentos/ata.md', $this->tree->nodes);
    }

    public function testUndoRechecksActivationAndInvalidConfigurationsBeforeAnyHelper(): void {
        $id = $this->batches->insert(new Batch(null, 'alice', $this->time->getTime(), [
            ['from' => '/Destination/Documentos', 'to' => '/Documentos', 'toId' => $this->tree->nodes['/alice/files/Documentos']['id']],
        ], ['/RecordedEmpty'], null));
        $this->tree->addFolder('/alice/files/RecordedEmpty');
        $this->config('[]');
        $this->assertTrue($this->plan('files_undo_batch', ['batch_id' => $id])['ok']);
        foreach (['["999"]', '{', 'unreadable'] as $raw) {
            $this->config($raw);
            $this->tree->listings = $this->locks->asked = [];
            $this->tree->beforeListing = static fn () => throw new \LogicException('No emptyDirs/lock walk');
            foreach ([false, true] as $confirm) {
                $this->assertSame($raw === '["999"]' ? FilesMessages::folderExposureRefused() : CommonMessages::notFound(),
                    $this->refused('files_undo_batch', ['batch_id' => $id], $confirm));
            }
            $this->assertSame([], $this->tree->listings);
            $this->assertSame([], $this->locks->asked);
        }
        $this->assertSame([], $this->tree->ops);
    }

    public function testExistingFolderSharesCannotBeUpdated(): void {
        foreach ([IShare::TYPE_USER, IShare::TYPE_LINK] as $type) {
            $share = $this->shares->add(['node' => $this->tree->nodes['/alice/files/Documentos']['id'],
                'nodeType' => 'folder', 'type' => $type, 'permissions' => 1, 'token' => 'SECRET']);
            foreach ([false, true] as $confirm) {
                $args = ['path' => '/Documentos', 'with' => $type === IShare::TYPE_LINK ? 'link' : 'user:bruno',
                    'expires' => '2027-01-01'];
                $this->assertSame(FilesMessages::folderExposureRefused(), $this->refused('files_share', $args, $confirm));
            }
            $this->assertSame(1, $share->getPermissions());
            $this->assertNull($share->getExpirationDate());
        }
        $this->assertSame([], $this->shares->ruleReads);
        $this->assertSame([], $this->shares->calls);
        $this->assertSame([], $this->shares->writes);
        $this->assertSame(0, $this->shares->newShares);
    }

    public function testRenameRequiresCanonicalParentIdentityAndNeverAssumesMissingParents(): void {
        $source = $this->createMock(\OCP\Files\Folder::class);
        $currentParent = $this->createMock(\OCP\Files\Folder::class);
        $currentParent->method('getId')->willReturn(91);
        $source->method('getParent')->willReturn($currentParent);
        $aliasParent = $this->createMock(\OCP\Files\Folder::class);
        $aliasParent->method('getId')->willReturn(91);
        $root = $this->createMock(\OCP\Files\Folder::class);
        $root->method('get')->willReturnCallback(static fn (string $path) => $path === 'alias'
            ? $aliasParent : throw new \OCP\Files\NotFoundException());
        \OCA\Mcp\Modules\Files\Access\FolderExposureGate::assertMove($root, $source, '/alias/renamed', $this->visibilityGuard);
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(FilesMessages::folderExposureRefused());
        \OCA\Mcp\Modules\Files\Access\FolderExposureGate::assertMove($root, $source, '/missing/renamed', $this->visibilityGuard);
    }

    public function testUndoRechecksD7PerItemAndPreservesRemainingMovesForRetry(): void {
        $this->config('[]');
        $folder = $this->tree->node('/alice/files/Documentos')->move('/alice/files/Destination/Documentos');
        $fileId = $this->tree->addFile('/alice/files/Destination/later.md', 'x');
        $id = $this->batches->insert(new Batch(null, 'alice', $this->time->getTime(), [
            ['from' => '/Documentos', 'to' => '/Destination/Documentos', 'toId' => $folder->getId()],
            ['from' => '/later.md', 'to' => '/Destination/later.md', 'toId' => $fileId],
        ], [], null));
        $this->tree->ops = [];
        $this->tree->afterMove = function (string $from): void {
            if ($from === '/alice/files/Destination/later.md') {
                $this->config('["999"]');
            }
        };
        $out = $this->json('files_undo_batch', ['batch_id' => $id]);
        $this->assertSame(1, $out['undone']);
        $this->assertSame(FilesMessages::folderExposureRefused(), $out['conflicts'][0]['reason']);
        $this->assertArrayHasKey('/alice/files/Destination/Documentos', $this->tree->nodes);
        $this->assertArrayNotHasKey('/alice/files/Documentos', $this->tree->nodes);
        $this->assertCount(1, $this->batches->find($id, 'alice')->moves);
        $this->assertNull($this->batches->rows[$id]['undone_at']);
        $this->tree->afterMove = null;
        $this->config('[]');
        $this->assertSame(1, $this->json('files_undo_batch', ['batch_id' => $id])['undone']);
        $this->assertArrayHasKey('/alice/files/Documentos', $this->tree->nodes);
    }

    public function testChainedRenameAndMoveIsRefusedAsAWholeWithoutMutation(): void {
        $args = ['moves' => [
            ['from' => '/Documentos', 'to' => '/Renamed'],
            ['from' => '/Renamed', 'to' => '/Destination/Renamed'],
        ]];
        $plan = $this->plan('files_move_batch', $args);
        $this->assertFalse($plan['ok']);
        $this->assertSame(CommonMessages::notFound(), $plan['denied'][0]['reason']);
        $this->assertSame(ReorganizationMessages::batchNotOk(0, 1), $this->refused('files_move_batch', $args, true));
        $this->assertSame([], $this->tree->ops);
        $this->assertSame([], $this->batches->rows);
        $this->assertArrayHasKey('/alice/files/Documentos', $this->tree->nodes);
        $this->assertArrayNotHasKey('/alice/files/Renamed', $this->tree->nodes);
        $this->assertArrayNotHasKey('/alice/files/Destination/Renamed', $this->tree->nodes);
    }

    public function testOnlyFolderLinksHaveNullUrlsWhileOtherShareTypesKeepTheKeyAbsent(): void {
        foreach ([IShare::TYPE_USER, IShare::TYPE_GROUP, IShare::TYPE_ROOM, IShare::TYPE_LINK] as $type) {
            $this->shares->add(['node' => $this->tree->nodes['/alice/files/Documentos']['id'],
                'nodeType' => 'folder', 'type' => $type, 'token' => 'SECRET']);
        }
        foreach ([['path' => '/Documentos'], []] as $args) {
            $out = $this->json('files_list_shares', $args);
            $this->assertCount(4, $out['shares']);
            foreach ($out['shares'] as $share) {
                if ($share['type'] === 'link') {
                    $this->assertArrayHasKey('url', $share);
                    $this->assertNull($share['url']);
                } else {
                    $this->assertArrayNotHasKey('url', $share);
                }
            }
        }
        $this->assertStringContainsString('null for a folder link while files are excluded from MCP access', SharingMessages::listSharesTool());
    }

    public function testNonpositiveParentIdentityCannotAuthorizeARename(): void {
        $parent = $this->createMock(\OCP\Files\Folder::class);
        $parent->method('getId')->willReturn(0);
        $source = $this->createMock(\OCP\Files\Folder::class);
        $source->method('getParent')->willReturn($parent);
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(FilesMessages::folderExposureRefused());
        \OCA\Mcp\Modules\Files\Access\FolderExposureGate::assertMove($parent, $source, '/renamed', $this->visibilityGuard);
    }

    public function testD7MessageIsTranslatedInEveryShippedLanguage(): void {
        $english = FilesMessages::folderExposureRefused();
        try {
            foreach (array_map(static fn (string $file): string => basename($file, '.json'), glob(__DIR__ . '/../../../../../l10n/*.json')) as $lang) {
                \OCA\Mcp\L10n\Translator::use(new \OCA\Mcp\Tests\Unit\L10n\JsonL10n($lang));
                $translated = FilesMessages::folderExposureRefused();
                $this->assertNotSame($english, $translated, $lang);
                $this->assertStringContainsString('Nextcloud', $translated, $lang);
            }
        } finally {
            \OCA\Mcp\L10n\Translator::reset();
        }
        $this->assertSame($english, FilesMessages::folderExposureRefused());
    }

}
