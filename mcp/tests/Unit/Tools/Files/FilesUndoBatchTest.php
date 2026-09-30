<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Tools\Files\Batch;
use OCA\Mcp\Tools\Files\BatchStore;

/**
 * files_undo_batch: giving a reorganization back.
 *
 * This is the only place in Files where anything is removed, and it removes exactly one kind of thing: an
 * empty folder the batch itself created. Everything else is a move back. The batch decides how much is
 * safe, so a folder the user filled after the batch ran stays, and a batch whose moves no longer match what
 * it left behind undoes nothing at all rather than half of it.
 */
final class FilesUndoBatchTest extends FilesToolsTestCase {
    protected function setUp(): void {
        parent::setUp();
        $this->tree->addFile('/alice/files/Documentos/plano.md', 'plano', 'text/markdown');
        // The destination folder has to exist before the plan, so it is not something the batch creates here.
        $this->tree->addFolder('/alice/files/Arquivado');
    }

    /** Runs a two-item batch and returns its id. */
    private function runBatch(array $mkdirs = []): int {
        return $this->json('files_move_batch', [
            'moves' => [
                ['from' => '/Documentos/ata.md', 'to' => '/Arquivado/ata.md'],
                ['from' => '/Documentos/plano.md', 'to' => '/Arquivado/plano.md'],
            ],
            'mkdirs' => $mkdirs,
            'dry_run' => false,
            'confirm' => true,
        ])['batch_id'];
    }

    // ----------------------------------------------------------------- the tool

    public function testAnUndoIsDeclaredAsAMoveAndNotAsADelete(): void {
        $undo = array_column($this->module->definitions(), null, 'name')['files_undo_batch'];
        $this->assertSame('move', $undo['operation'], 'desfazer devolve o que files_move moveu');
        $this->assertSame(['batch_id', 'confirm'], $undo['inputSchema']['required']);
        $this->assertTrue($undo['inputSchema']['properties']['confirm']['const']);
        $this->assertSame(1, $undo['inputSchema']['properties']['batch_id']['minimum']);
    }

    public function testAnUndoNeedsTheConfirmation(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->tool('files_undo_batch', ['batch_id' => $this->runBatch()]);
    }

    // ------------------------------------------------------------- what it does

    public function testAnUndoPutsEveryNodeBackWhereItWas(): void {
        $id = $this->runBatch();
        $out = $this->json('files_undo_batch', ['batch_id' => $id, 'confirm' => true]);
        $this->assertSame([
            'batch_id' => $id, 'undone' => 2, 'removed_dirs' => [], 'kept_dirs' => [], 'conflicts' => [],
        ], $out);
        $this->assertArrayHasKey('/alice/files/Documentos/ata.md', $this->tree->nodes);
        $this->assertArrayHasKey('/alice/files/Documentos/plano.md', $this->tree->nodes);
        $this->assertArrayNotHasKey('/alice/files/Arquivado/ata.md', $this->tree->nodes);
        // Backwards, so a folder that receives two items is emptied before the second one lands.
        $this->assertSame([
            'move /alice/files/Arquivado/plano.md /alice/files/Documentos/plano.md',
            'move /alice/files/Arquivado/ata.md /alice/files/Documentos/ata.md',
        ], array_slice($this->tree->ops, -2));
    }

    public function testAnUndoRemovesTheFoldersTheBatchCreatedWhenTheyAreEmpty(): void {
        $id = $this->runBatch(['/Arquivado/2026']);
        $out = $this->json('files_undo_batch', ['batch_id' => $id, 'confirm' => true]);
        $this->assertSame(['/Arquivado/2026'], $out['removed_dirs']);
        $this->assertArrayNotHasKey('/alice/files/Arquivado/2026', $this->tree->nodes);
    }

    /** A folder the user filled after the batch ran is theirs now; the undo skips it and says so. */
    public function testAnUndoKeepsAFolderThatIsNoLongerEmpty(): void {
        $id = $this->runBatch(['/Arquivado/2026']);
        $this->tree->addFile('/alice/files/Arquivado/2026/notas.md', 'anotação', 'text/markdown');
        $out = $this->json('files_undo_batch', ['batch_id' => $id, 'confirm' => true]);
        $this->assertSame([], $out['removed_dirs']);
        $this->assertSame(['/Arquivado/2026'], $out['kept_dirs']);
        $this->assertArrayHasKey('/alice/files/Arquivado/2026/notas.md', $this->tree->nodes);
    }

    public function testAnUndoRemovesTheDeepestFolderFirst(): void {
        $id = $this->runBatch(['/Arquivado/2026/03']);
        $out = $this->json('files_undo_batch', ['batch_id' => $id, 'confirm' => true]);
        $this->assertSame(['/Arquivado/2026/03', '/Arquivado/2026'], $out['removed_dirs']);
        $this->assertSame([
            'delete /alice/files/Arquivado/2026/03',
            'delete /alice/files/Arquivado/2026',
        ], array_slice($this->tree->ops, -2));
    }

    // ------------------------------------------------------------- when it refuses

    public function testABatchOfSomebodyElseIsNotFound(): void {
        $id = $this->batches->insert(new Batch(null, 'bob', 1790000000,
            [['from' => '/Documentos/ata.md', 'to' => '/Arquivado/ata.md', 'toId' => 1]], [], null));
        $this->expectException(\OCA\Mcp\Tools\ToolFailure::class);
        $this->expectExceptionMessage('Lote não encontrado.');
        $this->tool('files_undo_batch', ['batch_id' => $id, 'confirm' => true]);
    }

    public function testABatchThatDoesNotExistIsNotFound(): void {
        $this->expectException(\OCA\Mcp\Tools\ToolFailure::class);
        $this->expectExceptionMessage('Lote não encontrado.');
        $this->tool('files_undo_batch', ['batch_id' => 9999, 'confirm' => true]);
    }

    /** Older than the retention: answered exactly like one that never existed. */
    public function testABatchPastItsRetentionIsNotFound(): void {
        $id = $this->runBatch();
        $this->batches->rows[$id]['created_at'] = 1790000000 - BatchStore::RETENTION_SECONDS - 1;
        $this->expectException(\OCA\Mcp\Tools\ToolFailure::class);
        $this->expectExceptionMessage('Lote não encontrado.');
        $this->tool('files_undo_batch', ['batch_id' => $id, 'confirm' => true]);
    }

    public function testABatchCannotBeUndoneTwice(): void {
        $id = $this->runBatch();
        $this->json('files_undo_batch', ['batch_id' => $id, 'confirm' => true]);
        $this->assertSame('Este lote já foi desfeito.', $this->failure('files_undo_batch', ['batch_id' => $id, 'confirm' => true]));
    }

    /** Something else now sits at the original path: undoing would overwrite what the user did after. */
    public function testAnUndoRefusesWhenTheOriginalPathIsTakenAndUndoesNothing(): void {
        $id = $this->runBatch();
        $this->tree->addFile('/alice/files/Documentos/ata.md', 'versão nova', 'text/markdown');
        $out = $this->json('files_undo_batch', ['batch_id' => $id, 'confirm' => true]);
        $this->assertSame(0, $out['undone']);
        $this->assertSame([['from' => '/Documentos/ata.md', 'to' => '/Arquivado/ata.md',
            'reason' => 'Já existe um arquivo ou pasta neste destino.']], $out['conflicts']);
        $this->assertSame('versão nova', $this->tree->nodes['/alice/files/Documentos/ata.md']['content']);
        $this->assertArrayHasKey('/alice/files/Arquivado/ata.md', $this->tree->nodes, 'nada foi movido de volta');
    }

    /** The node at the destination is not the one the batch left there. */
    public function testAnUndoRefusesWhenTheNodeAtTheDestinationIsNotTheOneTheBatchMoved(): void {
        $id = $this->runBatch();
        $this->batches->rows[$id]['moves_json'] = json_encode([
            ['from' => '/Documentos/ata.md', 'to' => '/Arquivado/ata.md', 'toId' => 999999],
            ['from' => '/Documentos/plano.md', 'to' => '/Arquivado/plano.md', 'toId' => 999998],
        ], JSON_THROW_ON_ERROR);
        $out = $this->json('files_undo_batch', ['batch_id' => $id, 'confirm' => true]);
        $this->assertSame(0, $out['undone']);
        $this->assertSame('O item não é mais o que este lote moveu; nada foi desfeito.', $out['conflicts'][0]['reason']);
        $this->assertSame(2, count($out['conflicts']), 'todo item é conferido, não só o primeiro');
    }

    public function testAnUndoRefusesWhenTheNodeCannotBeMovedBackAndUndoesNothing(): void {
        $id = $this->runBatch();
        // isDeletable() on the destination is what a move back needs: the item has to be able to leave there.
        $this->tree->nodes['/alice/files/Arquivado/plano.md']['deletable'] = false;
        $out = $this->json('files_undo_batch', ['batch_id' => $id, 'confirm' => true]);
        $this->assertSame(0, $out['undone']);
        $this->assertSame('/Documentos/plano.md', $out['conflicts'][0]['from']);
        $this->assertSame('Sem acesso a este recurso no Nextcloud.', $out['conflicts'][0]['reason']);
        $this->assertArrayHasKey('/alice/files/Arquivado/ata.md', $this->tree->nodes, 'nem o item que passaria foi movido');
    }

    /** The parent of the original path is gone and the batch did not create it. */
    public function testAnUndoRefusesWhenTheOriginalFolderIsGone(): void {
        $id = $this->runBatch();
        unset($this->tree->nodes['/alice/files/Documentos'], $this->tree->nodes['/alice/files/Documentos/ata.md'],
            $this->tree->nodes['/alice/files/Documentos/plano.md']);
        $out = $this->json('files_undo_batch', ['batch_id' => $id, 'confirm' => true]);
        $this->assertSame(0, $out['undone']);
        $this->assertSame('Recurso não encontrado no Nextcloud.', $out['conflicts'][0]['reason']);
    }

    /**
     * A lock between the check and the move: the count says how much already went back and the batch stays
     * undoable, so the user can retry instead of discovering half of it in the wrong place later.
     */
    public function testARuntimeFailureWhileUndoingSaysHowMuchWentBackAndLeavesTheBatchUndoable(): void {
        $id = $this->runBatch();
        $this->tree->failMove = ['/alice/files/Arquivado/ata.md'];
        $out = $this->json('files_undo_batch', ['batch_id' => $id, 'confirm' => true]);
        $this->assertSame(1, $out['undone'], 'plano voltou antes de ata falhar');
        $this->assertSame('/Documentos/ata.md', $out['conflicts'][0]['from']);
        $this->assertArrayHasKey('/alice/files/Documentos/plano.md', $this->tree->nodes);
        $this->assertArrayHasKey('/alice/files/Arquivado/ata.md', $this->tree->nodes);
        $this->assertNull($this->batches->find($id, 'alice')->undoneAt, 'o lote continua desfazível');
    }

    /**
     * The way back lands in a folder this batch created and the user has since removed: the undo recreates
     * it instead of giving up. The situation cannot come out of a normal run — the source has to exist at
     * plan time — so the row is built by hand to pin the rule down.
     */
    public function testAnUndoPutsBackAFolderTheBatchCreatedBeforeMovingBack(): void {
        $moved = $this->tree->addFile('/alice/files/Arquivado/plano.md', 'plano', 'text/markdown');
        $id = $this->batches->insert(new Batch(null, 'alice', 1790000000,
            [['from' => '/2026/plano.md', 'to' => '/Arquivado/plano.md', 'toId' => $moved]],
            ['/2026'], null));
        $out = $this->json('files_undo_batch', ['batch_id' => $id, 'confirm' => true]);
        $this->assertSame(1, $out['undone']);
        $this->assertSame('mkdir /alice/files/2026', $this->tree->ops[count($this->tree->ops) - 2], 'a pasta é recriada antes do movimento');
        $this->assertArrayHasKey('/alice/files/2026/plano.md', $this->tree->nodes);
        $this->assertSame(['/2026'], $out['kept_dirs'], 'a pasta recriada tem o arquivo de volta dentro, então fica');
    }
}
