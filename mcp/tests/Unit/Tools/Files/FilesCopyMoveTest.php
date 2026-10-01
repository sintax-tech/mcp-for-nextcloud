<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\Files\FilesMessages;
use OCA\Mcp\Tools\Files\ReorganizationLimits;

/**
 * files_copy and files_move: the two operations that put a node somewhere else.
 *
 * Both refuse an occupied destination, both check the source's ETag when one is given, and both take the
 * shared-write confirmation. They differ on purpose: a copy creates a new node with a new id and promises
 * nothing about shares, while a move must stay inside one storage and reports what it could verify about
 * the id, the versions and the shares afterwards.
 */
final class FilesCopyMoveTest extends FilesToolsTestCase {
    private const SOURCE = '/alice/files/Documentos/ata.md';
    private const SOURCE_PATH = '/Documentos/ata.md';

    protected function setUp(): void {
        parent::setUp();
        $this->tree->addFile('/alice/files/Documentos/2026/plano.md', 'plano 2026', 'text/markdown');
        // Copy and move never create the destination folder: that is files_mkdir's job, and a tool that
        // invented a folder mid-reorganization would put files somewhere the user did not describe.
        $this->tree->addFolder('/alice/files/Copia');
        $this->tree->addFolder('/alice/files/Arquivado');
    }

    // ------------------------------------------------------------------ copy

    public function testCopyIsDeclaredForCreatingFiles(): void {
        $copy = array_column($this->module->definitions(), null, 'name')['files_copy'];
        $this->assertSame('create', $copy['operation']);
        $this->assertSame(['from', 'to'], $copy['inputSchema']['required']);
        $this->assertArrayHasKey('etag', $copy['inputSchema']['properties']);
        $this->assertArrayHasKey('confirm_shared', $copy['inputSchema']['properties']);
        $this->assertStringContainsString('confirm_shared', $copy['description']);
        // The promise it must NOT make: a copy is a new node.
        $this->assertStringNotContainsString('preserva', strtolower($copy['description']));
    }

    public function testCopyCreatesANewNodeWithANewIdAndReportsWhatItMeasured(): void {
        $out = $this->json('files_copy', ['from' => self::SOURCE_PATH, 'to' => '/Copia/ata.md']);
        $this->assertSame(['from', 'to', 'idBefore', 'idAfter', 'nodes', 'bytes', 'access'], array_keys($out));
        $this->assertSame(self::SOURCE_PATH, $out['from']);
        $this->assertSame('/Copia/ata.md', $out['to']);
        $this->assertNotSame($out['idBefore'], $out['idAfter'], 'uma cópia é um nó novo');
        $this->assertSame(1, $out['nodes']);
        $this->assertSame(strlen("# Ata\nolá"), $out['bytes']);
        $this->assertSame("# Ata\nolá", $this->tree->nodes['/alice/files/Copia/ata.md']['content']);
        $this->assertSame('personal', $out['access']['scope']);
        // The original is untouched: a copy never moves anything.
        $this->assertSame("# Ata\nolá", $this->tree->nodes[self::SOURCE]['content']);
    }

    public function testCopyRefusesAnOccupiedDestinationWithoutOverwriting(): void {
        $this->tree->addFile('/alice/files/Copia/ata.md', 'outro conteúdo', 'text/markdown');
        $this->assertSame(FilesMessages::destinationExists(), $this->failure('files_copy', ['from' => self::SOURCE_PATH, 'to' => '/Copia/ata.md']));
        $this->assertSame('outro conteúdo', $this->tree->nodes['/alice/files/Copia/ata.md']['content']);
        $this->assertSame([], $this->tree->ops);
    }

    public function testCopyChecksTheSourceEtagAndWritesNothingOnConflict(): void {
        $this->assertSame(CommonMessages::conflict(),
            $this->failure('files_copy', ['from' => self::SOURCE_PATH, 'to' => '/Copia/ata.md', 'etag' => 'outro']));
        $this->assertFalse(array_key_exists('/alice/files/Copia/ata.md', $this->tree->nodes));
        $this->assertSame([], $this->tree->ops);
    }

    public function testCopyCopiesAWholeFolderAndCountsEveryNode(): void {
        $out = $this->json('files_copy', ['from' => '/Documentos/2026', 'to' => '/Arquivado/2026']);
        $this->assertSame(2, $out['nodes'], 'a pasta e o arquivo dentro dela');
        $this->assertSame(strlen("# Ata\nolá"), $out['bytes']);
        $this->assertSame('plano 2026', $this->tree->nodes['/alice/files/Arquivado/2026/plano.md']['content']);
    }

    /** The cost of a recursive copy is the same storage or not, so the ceiling is the same. */
    public function testCopyRefusesATreeOverTheNodeCeilingWithoutCopyingAnything(): void {
        for ($i = 0; $i < ReorganizationLimits::NODES + 1; $i++) {
            $this->tree->addFile("/alice/files/Grande/arquivo-$i.txt", 'x', 'text/plain');
        }
        $this->assertSame(FilesMessages::copyTooManyNodes(ReorganizationLimits::NODES),
            $this->failure('files_copy', ['from' => '/Grande', 'to' => '/Copia/Grande']));
        $this->assertFalse(array_key_exists('/alice/files/Copia/Grande', $this->tree->nodes));
        $this->assertSame([], $this->tree->ops);
    }

    public function testCopyRefusesATreeOverTheByteCeilingWithoutCopyingAnything(): void {
        $this->tree->addFile('/alice/files/Pesada/grande.bin', str_repeat('x', 16), 'application/octet-stream');
        $this->tree->nodes['/alice/files/Pesada/grande.bin']['size'] = ReorganizationLimits::BYTES + 1;
        $this->assertSame(FilesMessages::copyTooLarge(ReorganizationLimits::BYTES),
            $this->failure('files_copy', ['from' => '/Pesada', 'to' => '/Copia/Pesada']));
        $this->assertSame([], $this->tree->ops);
    }

    /** The walk must stop early: a huge tree is not measured to the end just to be refused. */
    public function testTheMeasuringWalkStopsOnceTheCeilingIsPassed(): void {
        for ($i = 0; $i < ReorganizationLimits::NODES + 1; $i++) {
            $this->tree->addFile("/alice/files/Grande/arquivo-$i.txt", 'x', 'text/plain');
        }
        $this->expectException(\OCA\Mcp\Tools\ToolFailure::class);
        $this->expectExceptionMessage(FilesMessages::copyTooManyNodes(ReorganizationLimits::NODES));
        $this->reorganization()->measure($this->tree->node('/alice/files/Grande'));
    }

    /** Everything the two operations share, built the way the module builds it. */
    private function reorganization(): \OCA\Mcp\Tools\Files\Reorganization {
        $access = new \OCA\Mcp\Tools\Common\NodeAccessInfo(
            \OCA\Mcp\Tests\Unit\Tools\FakeUsers::manager($this, \OCA\Mcp\Tests\Unit\Tools\FakeUsers::DEFAULTS),
            $this->tree->shareManager(),
        );
        return new \OCA\Mcp\Tools\Files\Reorganization(
            $access,
            new \OCA\Mcp\Tools\Common\SharedWriteGuard($access),
            $this->report(),
            $this->users,
        );
    }

    public function testCopyOfASharedSourceAsksForConfirmation(): void {
        $this->tree->addFile('/alice/files/Compartilhado/plano.md', 'plano', 'text/markdown', ['scope' => 'shared']);
        $out = $this->json('files_copy', ['from' => '/Compartilhado/plano.md', 'to' => '/Copia/plano.md']);
        $this->assertTrue($out['requiresConfirmation']);
        $this->assertSame([], $this->tree->ops);
    }

    public function testCopyIntoASharedDestinationAsksForConfirmationToo(): void {
        $this->tree->mountPath = '/alice/files/Engenharia';
        $this->tree->addFolder('/alice/files/Engenharia', ['scope' => 'team']);
        $out = $this->json('files_copy', ['from' => self::SOURCE_PATH, 'to' => '/Engenharia/ata.md']);
        $this->assertTrue($out['requiresConfirmation']);
        $this->assertSame('team', $out['scope']);
        $this->assertSame([], $this->tree->ops);
    }

    // ------------------------------------------------------------------ move

    public function testMoveIsDeclaredForMovingFiles(): void {
        $move = array_column($this->module->definitions(), null, 'name')['files_move'];
        $this->assertSame('move', $move['operation']);
        $this->assertSame(['from', 'to'], $move['inputSchema']['required']);
        $this->assertArrayHasKey('confirm_shared', $move['inputSchema']['properties']);
    }

    public function testMoveRelocatesTheNodeAndKeepsItsId(): void {
        $id = $this->tree->nodes[self::SOURCE]['id'];
        $out = $this->json('files_move', ['from' => self::SOURCE_PATH, 'to' => '/Arquivado/ata.md']);
        $this->assertSame([
            'from', 'to', 'idBefore', 'idAfter', 'idPreserved', 'versionsBefore', 'versionsAfter',
            'sharesBefore', 'sharesAfter', 'access',
        ], array_keys($out));
        $this->assertSame($id, $out['idBefore']);
        $this->assertTrue($out['idPreserved'], 'o id precisa sobreviver ao movimento no mesmo storage');
        $this->assertSame($id, $out['idAfter']);
        $this->assertSame('move /alice/files/Documentos/ata.md /alice/files/Arquivado/ata.md',
            $this->tree->ops[count($this->tree->ops) - 1]);
        $this->assertSame("# Ata\nolá", $this->tree->nodes['/alice/files/Arquivado/ata.md']['content']);
    }

    public function testMovingAFolderTakesTheWholeSubtreeAndClaimsNoVersions(): void {
        $out = $this->json('files_move', ['from' => '/Documentos', 'to' => '/Arquivado/Documentos']);
        $this->assertSame('personal', $out['access']['scope']);
        $this->assertTrue($out['idPreserved']);
        // Versions belong to files; a folder has none of its own to count.
        $this->assertNull($out['versionsBefore']);
        $this->assertNull($out['versionsAfter']);
        $this->assertSame('plano 2026', $this->tree->nodes['/alice/files/Arquivado/Documentos/2026/plano.md']['content']);
        $this->assertFalse(array_key_exists('/alice/files/Documentos', $this->tree->nodes));
    }

    public function testAMoveIntoASharedDestinationAsksForConfirmation(): void {
        $this->tree->mountPath = '/alice/files/Engenharia';
        $this->tree->addFolder('/alice/files/Engenharia', ['scope' => 'team']);
        $out = $this->json('files_move', ['from' => self::SOURCE_PATH, 'to' => '/Engenharia/ata.md']);
        $this->assertTrue($out['requiresConfirmation']);
        $this->assertSame('team', $out['scope']);
        $this->assertSame([], $this->tree->ops);
    }

    /** A move between storages is a copy plus a delete in Nextcloud's terms, and it changes the id. */
    public function testARefusesAMoveAcrossStoragesAndTheNodeStaysPut(): void {
        // A mount point is what puts a subtree on another storage, so the folder carries the id.
        $this->tree->addFolder('/alice/files/Externo', ['storageId' => 'externo']);
        $this->tree->addFile('/alice/files/Externo/plano.md', 'plano', 'text/markdown');
        $this->assertSame(FilesMessages::crossStorage(),
            $this->failure('files_move', ['from' => '/Documentos/ata.md', 'to' => '/Externo/ata.md']));
        $this->assertSame("# Ata\nolá", $this->tree->nodes[self::SOURCE]['content'], 'a origem tem que continuar onde estava');
        $this->assertSame([], $this->tree->ops);
    }

    public function testARefusesAnOccupiedDestinationWithoutOverwriting(): void {
        $this->tree->addFile('/alice/files/Documentos/outro.md', 'outro', 'text/markdown');
        $this->assertSame(FilesMessages::destinationExists(), $this->failure('files_move', ['from' => self::SOURCE_PATH, 'to' => '/Documentos/outro.md']));
        $this->assertSame("# Ata\nolá", $this->tree->nodes[self::SOURCE]['content']);
        $this->assertSame('outro', $this->tree->nodes['/alice/files/Documentos/outro.md']['content']);
        $this->assertSame([], $this->tree->ops);
    }

    public function testARefusesAFolderIntoItself(): void {
        $this->assertSame(FilesMessages::folderIntoItself(),
            $this->failure('files_move', ['from' => '/Documentos', 'to' => '/Documentos/2026/sub']));
        $this->assertSame([], $this->tree->ops);
    }

    public function testARefusesAFolderIntoItsOwnSubfolder(): void {
        $this->assertSame(FilesMessages::folderIntoItself(),
            $this->failure('files_move', ['from' => '/Documentos', 'to' => '/Documentos/2026']));
    }

    public function testARefusesAMoveWithoutPermissionAndLeavesTheNodeWhereItWas(): void {
        $this->tree->nodes[self::SOURCE]['updateable'] = false;
        $this->assertSame(CommonMessages::forbidden(),
            $this->failure('files_move', ['from' => self::SOURCE_PATH, 'to' => '/Arquivado/ata.md']));
        $this->assertSame([], $this->tree->ops);
    }

    public function testARefusesAMoveIntoAFolderThatCannotTakeIt(): void {
        $this->tree->addFolder('/alice/files/Cheia', ['permissions' => \OCP\Constants::PERMISSION_READ]);
        $this->assertSame(CommonMessages::forbidden(),
            $this->failure('files_move', ['from' => self::SOURCE_PATH, 'to' => '/Cheia/ata.md']));
        $this->assertSame([], $this->tree->ops);
    }

    public function testAMoveOfASharedFileAsksForConfirmationAndMovesNothing(): void {
        $this->tree->addFile('/alice/files/Compartilhado/plano.md', 'plano', 'text/markdown', ['scope' => 'shared']);
        $out = $this->json('files_move', ['from' => '/Compartilhado/plano.md', 'to' => '/Arquivado/plano.md']);
        $this->assertTrue($out['requiresConfirmation']);
        $this->assertSame([], $this->tree->ops);
    }

    public function testAMoveInsideTheSameShareNeedsConfirmationButIsAllowedWithIt(): void {
        $this->tree->addFile('/alice/files/Compartilhado/plano.md', 'plano', 'text/markdown', ['scope' => 'shared']);
        $this->json('files_move', ['from' => '/Compartilhado/plano.md', 'to' => '/Arquivado/plano.md', 'confirm_shared' => true]);
        $this->assertSame('plano', $this->tree->nodes['/alice/files/Arquivado/plano.md']['content']);
    }

    public function testAMoveOfAnUnreadableSourceIsRefused(): void {
        $this->tree->nodes[self::SOURCE]['readable'] = false;
        $this->assertSame(CommonMessages::forbidden(),
            $this->failure('files_move', ['from' => self::SOURCE_PATH, 'to' => '/Arquivado/ata.md']));
        $this->assertSame([], $this->tree->ops);
    }

    /** The destination folder has to exist; creating it is files_mkdir's job, and the user asked for that. */
    public function testAMoveToAMissingFolderIsRefusedWithoutCreatingIt(): void {
        $this->assertSame(CommonMessages::notFound(),
            $this->failure('files_move', ['from' => self::SOURCE_PATH, 'to' => '/NaoExiste/ata.md']));
        $this->assertFalse(array_key_exists('/alice/files/NaoExiste', $this->tree->nodes));
    }

    public function testAMoveToExistingHiddenTargetIsRefusedWithForbiddenRatherThanDestinationExists(): void {
        $tagMapper = $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class);
        $this->config->app['mcp'][\OCA\Mcp\Service\VisibilityGuard::CONFIG_KEY] = json_encode(['999']);
        $this->visibilityGuard = new \OCA\Mcp\Service\VisibilityGuard($this->config->mock($this), $tagMapper);
        $this->setUp();

        $id = $this->tree->addFile('/alice/files/Arquivado/secret.md', 'secret');
        $tagMapper->method('getTagIdsForObjects')->willReturn([(string)$id => ['999']]);

        $this->assertSame(CommonMessages::forbidden(),
            $this->failure('files_move', ['from' => self::SOURCE_PATH, 'to' => '/Arquivado/secret.md']));
    }

    public function testACopyToExistingHiddenTargetIsRefusedWithForbiddenRatherThanDestinationExists(): void {
        $tagMapper = $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class);
        $this->config->app['mcp'][\OCA\Mcp\Service\VisibilityGuard::CONFIG_KEY] = json_encode(['999']);
        $this->visibilityGuard = new \OCA\Mcp\Service\VisibilityGuard($this->config->mock($this), $tagMapper);
        $this->setUp();

        $id = $this->tree->addFile('/alice/files/Arquivado/secret.md', 'secret');
        $tagMapper->method('getTagIdsForObjects')->willReturn([(string)$id => ['999']]);

        $this->assertSame(CommonMessages::forbidden(),
            $this->failure('files_copy', ['from' => self::SOURCE_PATH, 'to' => '/Arquivado/secret.md']));
    }

    public function testHiddenSourceFileIsRefusedAsNotFound(): void {
        $tagMapper = $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class);
        $this->config->app['mcp'][\OCA\Mcp\Service\VisibilityGuard::CONFIG_KEY] = json_encode(['999']);
        $this->visibilityGuard = new \OCA\Mcp\Service\VisibilityGuard($this->config->mock($this), $tagMapper);
        $this->setUp();

        $sourceId = $this->tree->nodes[self::SOURCE]['id'];
        $tagMapper->method('getTagIdsForObjects')->willReturn([(string)$sourceId => ['999']]);

        $this->assertSame(CommonMessages::notFound(),
            $this->failure('files_copy', ['from' => self::SOURCE_PATH, 'to' => '/Arquivado/ata-copy.md']));
        $this->assertSame(CommonMessages::notFound(),
            $this->failure('files_move', ['from' => self::SOURCE_PATH, 'to' => '/Arquivado/ata-moved.md']));
    }
}