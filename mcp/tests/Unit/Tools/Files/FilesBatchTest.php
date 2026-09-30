<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Tools\Files\ReorganizationLimits;

/**
 * files_move_batch: the reorganization a person reads before anything happens.
 *
 * A batch is two things at once — a plan the agent shows to the user, and an execution the user approved.
 * The plan never writes and never records a batch, so a rejected plan leaves no trace. The execution stops
 * at the first error and records only what it did, because a half-applied reorganization the user cannot
 * read is worse than one that stopped and said so.
 */
final class FilesBatchTest extends FilesToolsTestCase {
    protected function setUp(): void {
        parent::setUp();
        $this->tree->addFile('/alice/files/Documentos/plano.md', 'plano', 'text/markdown');
        $this->tree->addFile('/alice/files/Documentos/orcamento.md', 'orcamento', 'text/markdown');
        $this->tree->addFolder('/alice/files/Arquivado');
    }

    private const BATCH = ['moves' => [
        ['from' => '/Documentos/ata.md', 'to' => '/Arquivado/ata.md'],
        ['from' => '/Documentos/plano.md', 'to' => '/Arquivado/plano.md'],
    ]];

    // -------------------------------------------------------------- the plan

    public function testABatchIsDeclaredForMovingFilesAndDefaultsToADryRun(): void {
        $batch = array_column($this->module->definitions(), null, 'name')['files_move_batch'];
        $this->assertSame('move', $batch['operation']);
        $this->assertSame(['moves'], $batch['inputSchema']['required']);
        $this->assertTrue($batch['inputSchema']['properties']['dry_run']['default'], 'o padrão é planejar, não executar');
        $this->assertTrue($batch['inputSchema']['properties']['confirm']['const'], 'confirm tem de valer exatamente true');
        $this->assertSame(['from', 'to'], $batch['inputSchema']['properties']['moves']['items']['required']);
        $this->assertSame(ReorganizationLimits::BATCH_ITEMS, $batch['inputSchema']['properties']['moves']['maxItems']);
        $this->assertSame(ReorganizationLimits::BATCH_ITEMS, $batch['inputSchema']['properties']['mkdirs']['maxItems']);
        $this->assertArrayHasKey('confirm_shared', $batch['inputSchema']['properties']);
    }

    /** The ceiling is the validator's, not a comment: a plan nobody can read is not a plan. */
    public function testABatchOverTheCeilingIsRefusedBeforeAnythingIsRead(): void {
        $tooMany = array_fill(0, ReorganizationLimits::BATCH_ITEMS + 1,
            ['from' => '/Documentos/ata.md', 'to' => '/Arquivado/ata.md']);
        try {
            $this->tool('files_move_batch', ['moves' => $tooMany]);
            $this->fail('a batch over the ceiling was accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('moves', $e->getMessage());
        }
        $this->assertSame([], $this->tree->ops);
    }

    public function testABatchWithoutMovesIsRefused(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->tool('files_move_batch', ['moves' => []]);
    }

    public function testADryRunPlansEveryItemAndWritesNothing(): void {
        $plan = $this->json('files_move_batch', self::BATCH);
        $this->assertTrue($plan['dryRun']);
        $this->assertTrue($plan['ok']);
        $this->assertSame([
            ['from' => '/Documentos/ata.md', 'to' => '/Arquivado/ata.md', 'ok' => true],
            ['from' => '/Documentos/plano.md', 'to' => '/Arquivado/plano.md', 'ok' => true],
        ], $plan['moves']);
        $this->assertSame([], $plan['conflicts']);
        $this->assertSame([], $plan['denied']);
        $this->assertSame([], $plan['shared']);
        $this->assertSame([], $plan['mkdirs']);
        $this->assertSame(['total' => 2, 'planned' => 2, 'conflicts' => 0, 'denied' => 0, 'shared' => 0, 'mkdirs' => 0],
            $plan['summary']);
        $this->assertSame([], $this->tree->ops, 'um plano não toca em nada');
        $this->assertSame([], $this->batches->all(), 'um plano não grava lote');
    }

    public function testThePlanSeesTheFoldersItWouldCreate(): void {
        $plan = $this->json('files_move_batch', [
            'moves' => [['from' => '/Documentos/ata.md', 'to' => '/2026/03/ata.md']],
            'mkdirs' => ['/2026', '/2026/03'],
        ]);
        $this->assertSame([
            ['path' => '/2026', 'exists' => false, 'willCreate' => true],
            ['path' => '/2026/03', 'exists' => false, 'willCreate' => true],
        ], $plan['mkdirs']);
        $this->assertSame(2, $plan['summary']['mkdirs']);
        $this->assertSame([], $this->tree->ops, 'o plano não cria a pasta, apenas diz que criaria');
    }

    public function testThePlanSaysAFolderThatIsAlreadyThereIsNotCreated(): void {
        $plan = $this->json('files_move_batch', [
            'moves' => [['from' => '/Documentos/ata.md', 'to' => '/Arquivado/ata.md']],
            'mkdirs' => ['/Arquivado'],
        ]);
        $this->assertSame([['path' => '/Arquivado', 'exists' => true, 'willCreate' => false]], $plan['mkdirs']);
        $this->assertSame(0, $plan['summary']['mkdirs'], 'o que já existe não entra na conta do que será criado');
    }

    /** A blocked item must not hide the rest of the plan: the agent needs to see everything to fix it. */
    public function testThePlanListsBlockedItemsWithoutHidingTheOthers(): void {
        $this->tree->addFile('/alice/files/Arquivado/plano.md', 'versão antiga', 'text/markdown');
        $this->tree->nodes['/alice/files/Documentos/orcamento.md']['updateable'] = false;
        $plan = $this->json('files_move_batch', [
            'moves' => [
                ['from' => '/Documentos/ata.md', 'to' => '/Arquivado/ata.md'],
                ['from' => '/Documentos/plano.md', 'to' => '/Arquivado/plano.md'],
                ['from' => '/Documentos/orcamento.md', 'to' => '/Arquivado/orcamento.md'],
            ],
        ]);
        $this->assertFalse($plan['ok'], 'um plano com item bloqueado não está ok');
        $this->assertSame([['from' => '/Documentos/ata.md', 'to' => '/Arquivado/ata.md', 'ok' => true]], $plan['moves']);
        $this->assertSame([['from' => '/Documentos/plano.md', 'to' => '/Arquivado/plano.md',
            'reason' => 'Já existe um arquivo ou pasta neste destino.']], $plan['conflicts']);
        $this->assertSame([['from' => '/Documentos/orcamento.md', 'to' => '/Arquivado/orcamento.md',
            'reason' => 'Sem acesso a este recurso no Nextcloud.']], $plan['denied']);
        $this->assertSame(['total' => 3, 'planned' => 1, 'conflicts' => 1, 'denied' => 1, 'shared' => 0, 'mkdirs' => 0],
            $plan['summary']);
    }

    public function testThePlanNamesAMissingSourceInsteadOfPretendingItWillMove(): void {
        $plan = $this->json('files_move_batch', [
            'moves' => [['from' => '/Documentos/nao-existe.md', 'to' => '/Arquivado/nao-existe.md']],
        ]);
        $this->assertFalse($plan['ok']);
        $this->assertSame([['from' => '/Documentos/nao-existe.md', 'to' => '/Arquivado/nao-existe.md',
            'reason' => 'Recurso não encontrado no Nextcloud.']], $plan['denied']);
    }

    public function testThePlanRefusesAFolderIntoItself(): void {
        $plan = $this->json('files_move_batch', [
            'moves' => [['from' => '/Documentos', 'to' => '/Documentos/sub']],
        ]);
        $this->assertFalse($plan['ok']);
        $this->assertSame('Não é possível mover uma pasta para dentro dela mesma.', $plan['conflicts'][0]['reason']);
    }

    /**
     * The confirmation payload carries the plan with it, so the agent's code path is the same one it already
     * knows for a single move, and it can show the user what the batch would do.
     */
    public function testABatchWithASharedItemAsksForConfirmationAndStillShowsThePlan(): void {
        $this->tree->addFile('/alice/files/Engenharia/plano.md', 'plano', 'text/markdown', ['scope' => 'team']);
        $this->tree->mountPath = '/alice/files/Engenharia';
        $out = $this->json('files_move_batch', [
            'moves' => [['from' => '/Engenharia/plano.md', 'to' => '/Arquivado/plano.md']],
        ]);
        $this->assertTrue($out['requiresConfirmation']);
        $this->assertSame('team', $out['scope']);
        // A non-personal item is listed as shared, not as a move that would happen.
        $this->assertSame([], $out['moves']);
        $this->assertSame([['from' => '/Engenharia/plano.md', 'to' => '/Arquivado/plano.md', 'scope' => 'team']], $out['shared']);
        $this->assertSame(1, $out['summary']['shared']);
        $this->assertSame([], $this->tree->ops);
    }

    // ------------------------------------------------------------- execution

    public function testAnExecutionWithoutConfirmationIsRefusedBeforeAnythingHappens(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('confirm');
        $this->tool('files_move_batch', ['moves' => self::BATCH['moves'], 'dry_run' => false]);
    }

    public function testAnExecutionMovesInOrderCreatesTheFoldersAndRecordsTheBatch(): void {
        $out = $this->json('files_move_batch', [
            'moves' => self::BATCH['moves'],
            'mkdirs' => ['/Arquivado/2026'],
            'dry_run' => false,
            'confirm' => true,
        ]);
        $this->assertSame(1, $out['batch_id']);
        $this->assertSame(['/Documentos/ata.md', '/Documentos/plano.md'], array_column($out['moved'], 'from'));
        $this->assertSame(['/Arquivado/2026'], $out['created_dirs']);
        $this->assertSame(2, $out['undos'], 'cada movimento é desfazível');
        $this->assertSame([
            'mkdir /alice/files/Arquivado/2026',
            'move /alice/files/Documentos/ata.md /alice/files/Arquivado/ata.md',
            'move /alice/files/Documentos/plano.md /alice/files/Arquivado/plano.md',
        ], $this->tree->ops, 'as pastas primeiro, os movimentos na ordem do lote');
        $batch = $this->batches->find(1, 'alice');
        $this->assertSame([
            ['from' => '/Documentos/ata.md', 'to' => '/Arquivado/ata.md', 'toId' => $this->tree->nodes['/alice/files/Arquivado/ata.md']['id']],
            ['from' => '/Documentos/plano.md', 'to' => '/Arquivado/plano.md', 'toId' => $this->tree->nodes['/alice/files/Arquivado/plano.md']['id']],
        ], $batch->moves, 'o lote guarda o id observável de cada destino, para o desfazer conferir');
        $this->assertSame(['/Arquivado/2026'], $batch->dirs);
        $this->assertNull($batch->undoneAt);
    }

    public function testABatchWithASharedItemRefusesToMoveAnythingWithoutTheSharedConfirmation(): void {
        $this->tree->addFile('/alice/files/Engenharia/plano.md', 'plano', 'text/markdown', ['scope' => 'team']);
        $this->tree->mountPath = '/alice/files/Engenharia';
        $out = $this->json('files_move_batch', [
            'moves' => [
                ['from' => '/Documentos/ata.md', 'to' => '/Arquivado/ata.md'],
                ['from' => '/Engenharia/plano.md', 'to' => '/Arquivado/plano.md'],
            ],
            'dry_run' => false,
            'confirm' => true,
        ]);
        $this->assertTrue($out['requiresConfirmation']);
        $this->assertArrayNotHasKey('batch_id', $out, 'sem confirm_shared nada é executado nem gravado');
        $this->assertSame([], $this->tree->ops);
        $this->assertSame([], $this->batches->all());
    }

    public function testAnExecutionStopsAtTheFirstErrorAndRecordsOnlyWhatItMoved(): void {
        $this->tree->failMove = ['/alice/files/Documentos/ata.md'];
        $out = $this->json('files_move_batch', [
            'moves' => [
                ['from' => '/Documentos/ata.md', 'to' => '/Arquivado/ata.md'],
                ['from' => '/Documentos/plano.md', 'to' => '/Arquivado/plano.md'],
                ['from' => '/Documentos/orcamento.md', 'to' => '/Arquivado/orcamento.md'],
            ],
            'dry_run' => false,
            'confirm' => true,
        ]);
        // Nothing is refused at plan time here, so the failure has to come from the move itself: a lock or a
        // permission that changed between the plan and the execution.
        $this->assertSame(1, $out['batch_id'], 'o lote é gravado mesmo assim, para o desfazer ter o que reverter');
        $this->assertSame([], $out['moved']);
        $this->assertSame('/Documentos/ata.md', $out['failed']['from']);
        $this->assertSame('/Arquivado/ata.md', $out['failed']['to']);
        $this->assertSame('Sem acesso a este recurso no Nextcloud.', $out['failed']['reason']);
        $this->assertSame(['/Documentos/plano.md', '/Documentos/orcamento.md'],
            array_column($out['not_attempted'], 'from'), 'o que não foi tentado é dito, não escondido');
        $this->assertSame([], $this->batches->find(1, 'alice')->moves);
    }

    public function testABatchOfSomebodyElseIsNotFound(): void {
        $this->json('files_move_batch', ['moves' => self::BATCH['moves'], 'dry_run' => false, 'confirm' => true]);
        $this->assertNull($this->batches->find(1, 'bob'), 'o lote existe, mas não é de bob');
    }
}
