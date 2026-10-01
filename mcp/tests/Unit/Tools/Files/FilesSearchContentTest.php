<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Service\VisibilityGuard;
use OCA\Mcp\Tools\Files\FilesMessages;
use OCA\Mcp\Tools\Files\FilesModule;
use OCP\Files\IRootFolder;
use OCP\FullTextSearch\IFullTextSearchManager;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\FullTextSearch\Model\ISearchResult;

final class FilesSearchContentTest extends FilesToolsTestCase {
    public function testFullTextSearchReturnsContentMatchesWithExcerpts(): void {
        $this->enabled[] = 'fulltextsearch';
        $this->enabled[] = 'files_fulltextsearch';

        $fileId = $this->tree->nodes['/alice/files/Documentos/ata.md']['id'];

        $doc = $this->createMock(IIndexDocument::class);
        $doc->method('getId')->willReturn((string)$fileId);
        $doc->method('getExcerpts')->willReturn([['excerpt' => 'Trecho com <b>destaque</b> do documento']]);

        $searchResult = $this->createMock(ISearchResult::class);
        $searchResult->method('getDocuments')->willReturn([$doc]);

        $ftsManager = $this->createMock(IFullTextSearchManager::class);
        $ftsManager->method('isAvailable')->willReturn(true);
        $ftsManager->method('isProviderIndexed')->with('files')->willReturn(true);
        $ftsManager->method('search')->willReturn([$searchResult]);

        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(fn (string $uid) => $this->tree->rootFolder());

        $this->module = $this->buildModuleWithFts($root, $ftsManager);

        $res = $this->json('files_search', ['query' => 'destaque']);
        $this->assertSame('content', $res['search_mode']);
        $this->assertTrue($res['full_text_active']);
        $this->assertArrayNotHasKey('notice', $res);
        $this->assertCount(1, $res['files']);
        $this->assertSame('/Documentos/ata.md', $res['files'][0]['path']);
        $this->assertSame(['Trecho com destaque do documento'], $res['files'][0]['excerpts']);
    }

    public function testFullTextSearchFallbackWhenAppDisabled(): void {
        // fulltextsearch not enabled in $this->enabled
        $ftsManager = $this->createMock(IFullTextSearchManager::class);
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(fn (string $uid) => $this->tree->rootFolder());

        $this->module = $this->buildModuleWithFts($root, $ftsManager);

        $res = $this->json('files_search', ['query' => 'Ata']);
        $this->assertSame('name_only', $res['search_mode']);
        $this->assertFalse($res['full_text_active']);
        $this->assertSame(FilesMessages::searchNameOnlyNotice(), $res['notice']);
        $this->assertSame(['/Documentos/ata.md'], array_column($res['files'], 'path'));
    }

    public function testFullTextSearchFallbackWhenFtsThrowsException(): void {
        $this->enabled[] = 'fulltextsearch';
        $this->enabled[] = 'files_fulltextsearch';

        $ftsManager = $this->createMock(IFullTextSearchManager::class);
        $ftsManager->method('isAvailable')->willReturn(true);
        $ftsManager->method('isProviderIndexed')->with('files')->willReturn(true);
        $ftsManager->method('search')->willThrowException(new \RuntimeException('FTS cluster unavailable'));

        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(fn (string $uid) => $this->tree->rootFolder());

        $this->module = $this->buildModuleWithFts($root, $ftsManager);

        $res = $this->json('files_search', ['query' => 'Ata']);
        $this->assertSame('name_only', $res['search_mode']);
        $this->assertFalse($res['full_text_active']);
        $this->assertSame(FilesMessages::searchFallbackNotice(), $res['notice']);
        $this->assertSame(['/Documentos/ata.md'], array_column($res['files'], 'path'));
    }

    public function testModeNameForcesNameOnlySearchEvenWhenFtsAvailable(): void {
        $this->enabled[] = 'fulltextsearch';
        $this->enabled[] = 'files_fulltextsearch';

        $ftsManager = $this->createMock(IFullTextSearchManager::class);
        $ftsManager->method('isAvailable')->willReturn(true);
        $ftsManager->method('isProviderIndexed')->with('files')->willReturn(true);
        // search() should not be called when mode is 'name'
        $ftsManager->expects($this->never())->method('search');

        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(fn (string $uid) => $this->tree->rootFolder());

        $this->module = $this->buildModuleWithFts($root, $ftsManager);

        $res = $this->json('files_search', ['query' => 'Ata', 'mode' => 'name']);
        $this->assertSame('name_only', $res['search_mode']);
        $this->assertTrue($res['full_text_active']);
        $this->assertArrayNotHasKey('notice', $res);
        $this->assertSame(['/Documentos/ata.md'], array_column($res['files'], 'path'));
    }

    public function testVisibilityGuardFiltersHiddenFileFromFtsAndNameSearch(): void {
        $this->enabled[] = 'fulltextsearch';
        $this->enabled[] = 'files_fulltextsearch';

        $fileId = $this->tree->nodes['/alice/files/Documentos/ata.md']['id'];

        $doc = $this->createMock(IIndexDocument::class);
        $doc->method('getId')->willReturn((string)$fileId);
        $doc->method('getExcerpts')->willReturn(['Trecho do arquivo']);

        $searchResult = $this->createMock(ISearchResult::class);
        $searchResult->method('getDocuments')->willReturn([$doc]);

        $ftsManager = $this->createMock(IFullTextSearchManager::class);
        $ftsManager->method('isAvailable')->willReturn(true);
        $ftsManager->method('isProviderIndexed')->with('files')->willReturn(true);
        $ftsManager->method('search')->willReturn([$searchResult]);

        $guard = $this->createMock(VisibilityGuard::class);
        $guard->method('filter')->willReturn([]); // hides all nodes

        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(fn (string $uid) => $this->tree->rootFolder());

        $this->module = $this->buildModuleWithFts($root, $ftsManager, $guard);

        // Content search should filter out the hidden document
        $res = $this->json('files_search', ['query' => 'destaque']);
        $this->assertSame('content', $res['search_mode']);
        $this->assertSame([], $res['files']);

        // Name search should also filter out hidden files
        $resName = $this->json('files_search', ['query' => 'Ata', 'mode' => 'name']);
        $this->assertSame('name_only', $resName['search_mode']);
        $this->assertSame([], $resName['files']);
    }

    private function buildModuleWithFts(
        IRootFolder $root,
        IFullTextSearchManager $ftsManager,
        ?VisibilityGuard $guard = null,
    ): FilesModule {
        $extractor = new \OCA\Mcp\Tools\Files\TextExtractor($this->temp);
        $backup = new \OCA\Mcp\Tools\Files\FileBackup($this->apps, $this->users, $this->time, $this->config->mock($this));
        $access = new \OCA\Mcp\Tools\Common\NodeAccessInfo(\OCA\Mcp\Tests\Unit\Tools\FakeUsers::manager($this, \OCA\Mcp\Tests\Unit\Tools\FakeUsers::DEFAULTS), $this->tree->shareManager());
        $db = $this->createMock(\OCP\IDBConnection::class);
        $db->method('escapeLikeParameter')->willReturnCallback(fn (string $s) => addcslashes($s, '\\_%'));

        $imageTools = new \OCA\Mcp\Tools\Files\ImageTools(
            $this->previewManager,
            $access,
            $this->config->mock($this),
            $this->tagManager,
            $this->tagMapper,
            $this->users,
            $this->logger,
            $this->createMock(\Psr\Container\ContainerInterface::class),
            $db,
        );

        return new FilesModule(
            $root,
            $extractor,
            $backup,
            $this->users,
            $db,
            $access,
            new \OCA\Mcp\Tools\Common\SharedWriteGuard($access),
            new \OCA\Mcp\Tools\Files\CheckoutService(
                $this->createMock(\OCP\IURLGenerator::class),
                $this->config->mock($this),
                $this->time,
                new \OCA\Mcp\OAuth\TokenHasher($this->config->mock($this)),
                $this->store,
                $this->apps,
                $this->users,
            ),
            new \OCA\Mcp\Tools\Files\VersionTools($this->apps, $this->users, $extractor, $backup, $access, $this->createMock(\Psr\Container\ContainerInterface::class), new \OCA\Mcp\Tools\Files\OcrSupport($this->apps)),
            new \OCA\Mcp\Tools\Files\Reorganization($access, new \OCA\Mcp\Tools\Common\SharedWriteGuard($access), $this->report(), $this->users),
            new \OCA\Mcp\Tools\Files\MovePlanner(new \OCA\Mcp\Tools\Files\Reorganization($access, new \OCA\Mcp\Tools\Common\SharedWriteGuard($access), $this->report(), $this->users), $access, new \OCA\Mcp\Tools\Common\SharedWriteGuard($access)),
            $this->batches,
            $this->time,
            $imageTools,
            new \OCA\Mcp\Tools\Files\OcrSupport($this->apps),
            $guard,
            $this->apps,
            $ftsManager,
        );
    }
}
