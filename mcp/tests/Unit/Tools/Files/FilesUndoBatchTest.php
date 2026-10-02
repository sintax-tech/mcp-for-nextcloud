<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Tools\Files\Batch;
use OCA\Mcp\Tools\Files\BatchStore;
use OCA\Mcp\Tools\Files\FilesMessages;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\ToolFailure;

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
            'confirm' => true,
        ])['batch_id'];
    }

    // ----------------------------------------------------------------- the tool

    public function testAnUndoIsDeclaredAsAMoveAndNotAsADelete(): void {
        $undo = array_column($this->module->definitions(), null, 'name')['files_undo_batch'];
        $this->assertSame('move', $undo['operation'], 'desfazer devolve o que files_move moveu');
        $this->assertSame(['batch_id'], $undo['inputSchema']['required']);
        $this->assertSame(1, $undo['inputSchema']['properties']['batch_id']['minimum']);
    }

    public function testAnUndoNeedsTheConfirmation(): void {
        $id = $this->runBatch();
        $plan = $this->plan('files_undo_batch', ['batch_id' => $id]);
        $this->assertSame('files_undo_batch', $plan['action']);
        $this->assertSame($id, $plan['batch_id']);
        $this->assertTrue($plan['ok']);
        $this->assertArrayHasKey('/alice/files/Arquivado/ata.md', $this->tree->nodes);
    }

    /**
     * The only removal of the module is previewed exactly: a folder the batch created and filled is empty once the
     * undo moves its items back, so the plan says it goes, and the run removes just that. A folder the user filled
     * afterwards is kept in both.
     */
    public function testTheUndoPlanSaysWhichFoldersGoExactlyAsTheRunDoes(): void {
        $id = $this->json('files_move_batch', [
            'moves' => [
                ['from' => '/Documentos/ata.md', 'to' => '/Novo/ata.md'],
                ['from' => '/Documentos/plano.md', 'to' => '/Outro/plano.md'],
            ],
            'mkdirs' => ['/Novo', '/Outro'],
            'confirm' => true,
        ])['batch_id'];
        $this->tree->addFile('/alice/files/Outro/notas.md', 'do usuário', 'text/markdown');

        $plan = $this->plan('files_undo_batch', ['batch_id' => $id]);
        $this->assertSame(['/Novo'], $plan['removed_dirs'], 'a pasta fica vazia depois do desfazer');
        $this->assertSame(['/Outro'], $plan['kept_dirs'], 'a pasta com conteúdo do usuário fica');
        $this->assertSame([['to' => '/Outro/plano.md', 'from' => '/Documentos/plano.md'], ['to' => '/Novo/ata.md', 'from' => '/Documentos/ata.md']],
            $plan['undo'], 'na ordem inversa do lote');

        $out = $this->json('files_undo_batch', ['batch_id' => $id, 'confirm' => true]);
        $this->assertSame($plan['removed_dirs'], $out['removed_dirs']);
        $this->assertSame($plan['kept_dirs'], $out['kept_dirs']);
        $this->assertArrayNotHasKey('/alice/files/Novo', $this->tree->nodes);
        $this->assertArrayHasKey('/alice/files/Outro/notas.md', $this->tree->nodes);
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
        $this->expectExceptionMessage(FilesMessages::batchNotFound());
        $this->tool('files_undo_batch', ['batch_id' => $id, 'confirm' => true]);
    }

    public function testABatchThatDoesNotExistIsNotFound(): void {
        $this->expectException(\OCA\Mcp\Tools\ToolFailure::class);
        $this->expectExceptionMessage(FilesMessages::batchNotFound());
        $this->tool('files_undo_batch', ['batch_id' => 9999, 'confirm' => true]);
    }

    /** Older than the lifetime: answered exactly like one that never existed. */
    public function testABatchPastItsLifetimeIsNotFound(): void {
        $id = $this->runBatch();
        $this->batches->rows[$id]['created_at'] = 1790000000 - BatchStore::LIFETIME_SECONDS - 1;
        $this->expectException(\OCA\Mcp\Tools\ToolFailure::class);
        $this->expectExceptionMessage(FilesMessages::batchNotFound());
        $this->tool('files_undo_batch', ['batch_id' => $id, 'confirm' => true]);
    }

    public function testABatchCannotBeUndoneTwice(): void {
        $id = $this->runBatch();
        $this->json('files_undo_batch', ['batch_id' => $id, 'confirm' => true]);
        $this->assertSame(FilesMessages::batchAlreadyUndone(), $this->failure('files_undo_batch', ['batch_id' => $id, 'confirm' => true]));
    }

    /** Something else now sits at the original path: undoing would overwrite what the user did after. */
    public function testAnUndoRefusesWhenTheOriginalPathIsTakenAndUndoesNothing(): void {
        $id = $this->runBatch();
        $this->tree->addFile('/alice/files/Documentos/ata.md', 'versão nova', 'text/markdown');
        $out = $this->json('files_undo_batch', ['batch_id' => $id, 'confirm' => true]);
        $this->assertSame(0, $out['undone']);
        $this->assertSame([['from' => '/Documentos/ata.md', 'to' => '/Arquivado/ata.md',
            'reason' => FilesMessages::destinationExists()]], $out['conflicts']);
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
        $this->assertSame(FilesMessages::notTheBatchNode(), $out['conflicts'][0]['reason']);
        $this->assertSame(2, count($out['conflicts']), 'todo item é conferido, não só o primeiro');
    }

    public function testAnUndoRefusesWhenTheNodeCannotBeMovedBackAndUndoesNothing(): void {
        $id = $this->runBatch();
        // isDeletable() on the destination is what a move back needs: the item has to be able to leave there.
        $this->tree->nodes['/alice/files/Arquivado/plano.md']['deletable'] = false;
        $out = $this->json('files_undo_batch', ['batch_id' => $id, 'confirm' => true]);
        $this->assertSame(0, $out['undone']);
        $this->assertSame('/Documentos/plano.md', $out['conflicts'][0]['from']);
        $this->assertSame(CommonMessages::forbidden(), $out['conflicts'][0]['reason']);
        $this->assertArrayHasKey('/alice/files/Arquivado/ata.md', $this->tree->nodes, 'nem o item que passaria foi movido');
    }

    /** The parent of the original path is gone and the batch did not create it. */
    public function testAnUndoRefusesWhenTheOriginalFolderIsGone(): void {
        $id = $this->runBatch();
        unset($this->tree->nodes['/alice/files/Documentos'], $this->tree->nodes['/alice/files/Documentos/ata.md'],
            $this->tree->nodes['/alice/files/Documentos/plano.md']);
        $out = $this->json('files_undo_batch', ['batch_id' => $id, 'confirm' => true]);
        $this->assertSame(0, $out['undone']);
        $this->assertSame(CommonMessages::notFound(), $out['conflicts'][0]['reason']);
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
        $this->assertNull($this->batches->find($id, 'alice')->undoneAt, 'o lote continua aberto');
    }

    /**
     * The case that made the first fix necessary: an undo that stopped halfway used to be a dead end, because
     * the items already back were not in their destination any more and the next attempt read that absence
     * as a conflict and refused the whole batch for good. It now keeps only what is left, so the retry
     * finishes the job and the tree ends exactly where it started.
     */
    public function testAnUndoThatStoppedHalfwayCanBeRetriedUntilTheBatchIsWhole(): void {
        $this->tree->addFile('/alice/files/Documentos/notas.md', 'notas', 'text/markdown');
        $id = $this->json('files_move_batch', [
            'moves' => [
                ['from' => '/Documentos/ata.md', 'to' => '/Arquivado/ata.md'],
                ['from' => '/Documentos/plano.md', 'to' => '/Arquivado/plano.md'],
                ['from' => '/Documentos/notas.md', 'to' => '/Arquivado/notas.md'],
            ],
            'mkdirs' => ['/Arquivado/2026'],
            'confirm' => true,
        ])['batch_id'];
        // Reverse order is notas, plano, ata: the middle one breaks.
        $this->tree->failMove = ['/alice/files/Arquivado/plano.md'];

        $first = $this->json('files_undo_batch', ['batch_id' => $id, 'confirm' => true]);
        $this->assertSame(1, $first['undone']);
        $this->assertSame('/Documentos/plano.md', $first['conflicts'][0]['from']);
        $this->assertNull($this->batches->find($id, 'alice')->undoneAt, 'o lote continua aberto');

        $this->tree->failMove = [];
        $second = $this->json('files_undo_batch', ['batch_id' => $id, 'confirm' => true]);
        $this->assertSame([], $second['conflicts'], 'a segunda tentativa não vê conflito: o que já voltou sumiu da fila');
        $this->assertSame(2, $second['undone']);
        $this->assertSame(['/Arquivado/2026'], $second['removed_dirs']);
        foreach (['ata.md', 'plano.md', 'notas.md'] as $name) {
            $this->assertArrayHasKey("/alice/files/Documentos/$name", $this->tree->nodes, "$name voltou para casa");
            $this->assertArrayNotHasKey("/alice/files/Arquivado/$name", $this->tree->nodes);
        }
        $this->assertArrayNotHasKey('/alice/files/Arquivado/2026', $this->tree->nodes);
        $finished = $this->batches->find($id, 'alice');
        $this->assertNotNull($finished->undoneAt, 'o lote terminou');
        // The row still holds the two it reverted on the retry; the one that went back on the first attempt
        // was narrowed out then. undone_at, not the list, is what stops the batch being replayed.
        $this->assertCount(2, $finished->moves);
        $this->assertTrue($finished->isUndone());
    }

    /**
     * B3: any exception in the middle of the undo keeps only what did not go back, not only the ones Nextcloud types.
     * A generic file exception used to leave the row whole, and the retry read the items already home as conflicts.
     */
    public function testAGenericExceptionInTheMiddleOfTheUndoKeepsOnlyWhatDidNotGoBack(): void {
        $this->tree->addFile('/alice/files/Documentos/notas.md', 'notas', 'text/markdown');
        $id = $this->json('files_move_batch', [
            'moves' => [
                ['from' => '/Documentos/ata.md', 'to' => '/Arquivado/ata.md'],
                ['from' => '/Documentos/plano.md', 'to' => '/Arquivado/plano.md'],
                ['from' => '/Documentos/notas.md', 'to' => '/Arquivado/notas.md'],
            ],
            'confirm' => true,
        ])['batch_id'];
        // Reverse order is notas, plano, ata: the middle one breaks with an exception nobody typed.
        $this->tree->throwOnMove = ['/alice/files/Arquivado/plano.md' => new \RuntimeException('disk exploded: /secret/path')];

        $first = $this->json('files_undo_batch', ['batch_id' => $id, 'confirm' => true]);

        $this->assertSame(1, $first['undone']);
        $this->assertSame(FilesMessages::moveFailed(), $first['conflicts'][0]['reason'], 'the message is the generic one');
        $this->assertStringNotContainsString('secret', json_encode($first));
        $this->assertSame(['/Documentos/ata.md', '/Documentos/plano.md'],
            array_column($this->batches->find($id, 'alice')->moves, 'from'), 'the row keeps only what did not go back');
        $this->assertNull($this->batches->find($id, 'alice')->undoneAt);

        $this->tree->throwOnMove = [];
        $second = $this->json('files_undo_batch', ['batch_id' => $id, 'confirm' => true]);
        $this->assertSame([], $second['conflicts']);
        $this->assertSame(2, $second['undone']);
        foreach (['ata.md', 'plano.md', 'notas.md'] as $name) {
            $this->assertArrayHasKey("/alice/files/Documentos/$name", $this->tree->nodes);
        }
        $this->assertTrue($this->batches->find($id, 'alice')->isUndone());
    }

    /**
     * The same retry against the production store over SQLite. The double used to carry a method the real store did
     * not have, so this path was green in the tests and a fatal "undefined method" on the server: the undo stopped
     * halfway, recorded nothing, and the next attempt read the items already home as conflicts.
     */
    public function testAnUndoThatStoppedHalfwayCanBeRetriedWithTheProductionStore(): void {
        $db = new \OCA\Mcp\Tests\Unit\SqliteDatabase($this, [new \OCA\Mcp\Migration\Version000800Date20261002000000()]);
        $this->batchStore = new BatchStore($db->connection());
        $this->setUp();
        $this->tree->addFile('/alice/files/Documentos/notas.md', 'notas', 'text/markdown');
        $id = $this->json('files_move_batch', [
            'moves' => [
                ['from' => '/Documentos/ata.md', 'to' => '/Arquivado/ata.md'],
                ['from' => '/Documentos/plano.md', 'to' => '/Arquivado/plano.md'],
                ['from' => '/Documentos/notas.md', 'to' => '/Arquivado/notas.md'],
            ],
            'confirm' => true,
        ])['batch_id'];
        $this->tree->failMove = ['/alice/files/Arquivado/plano.md'];

        $first = $this->json('files_undo_batch', ['batch_id' => $id, 'confirm' => true]);
        $this->assertSame(1, $first['undone']);
        $this->assertSame(['/Documentos/ata.md', '/Documentos/plano.md'],
            array_column($this->batchStore->find($id, 'alice')->moves, 'from'), 'a linha guarda só o que não voltou');

        $this->tree->failMove = [];
        $second = $this->json('files_undo_batch', ['batch_id' => $id, 'confirm' => true]);
        $this->assertSame([], $second['conflicts']);
        $this->assertSame(2, $second['undone']);
        foreach (['ata.md', 'plano.md', 'notas.md'] as $name) {
            $this->assertArrayHasKey("/alice/files/Documentos/$name", $this->tree->nodes, "$name voltou para casa");
        }
        $this->assertTrue($this->batchStore->find($id, 'alice')->isUndone());
    }

    /** Once the batch is whole there is nothing left to undo, and saying so beats moving things twice. */
    public function testAFinishedBatchCannotBeUndoneAgain(): void {
        $id = $this->runBatch();
        $this->json('files_undo_batch', ['batch_id' => $id, 'confirm' => true]);
        $this->assertSame(FilesMessages::batchAlreadyUndone(),
            $this->failure('files_undo_batch', ['batch_id' => $id, 'confirm' => true]));
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
