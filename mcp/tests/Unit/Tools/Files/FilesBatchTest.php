<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\Files\FilesMessages;
use OCA\Mcp\Tools\Files\ReorganizationLimits;

/**
 * files_move_batch: the reorganization a person reads before anything happens.
 *
 * A batch is two things at once — the plan the agent shows to the user, and the execution the user approved.
 * The plan is what a call without `confirm: true` answers with; the registry asks the module for it, and it
 * never writes nor records a batch, so a rejected plan leaves no trace. The execution stops at the first
 * error and records only what it did, because a half-applied reorganization the user cannot read is worse
 * than one that stopped and said so.
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

    /** A batch is a move; `confirm` comes from the registry and `dry_run` is gone with it. */
    public function testABatchIsDeclaredForMovingFilesAndDefersTheConfirmationToTheRegistry(): void {
        $batch = array_column($this->module->definitions(), null, 'name')['files_move_batch'];
        $this->assertSame('move', $batch['operation']);
        $this->assertSame(['moves'], $batch['inputSchema']['required']);
        $this->assertArrayNotHasKey('dry_run', $batch['inputSchema']['properties'], 'o lote tem um confirm só');
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

    public function testThePlanAnswersEveryItemAndWritesNothing(): void {
        $plan = $this->plan('files_move_batch', self::BATCH);
        $this->assertTrue($plan['ok']);
        $this->assertSame('files_move_batch', $plan['action']);
        $this->assertSame([
            ['from' => '/Documentos/ata.md', 'to' => '/Arquivado/ata.md', 'ok' => true],
            ['from' => '/Documentos/plano.md', 'to' => '/Arquivado/plano.md', 'ok' => true],
        ], $plan['moves']);
        $this->assertSame([], $plan['conflicts']);
        $this->assertSame([], $plan['denied']);
        $this->assertSame([], $plan['shared']);
        $this->assertSame([], $plan['mkdirs']);
        $this->assertSame(['total' => 2, 'planned' => 2, 'conflicts' => 0, 'denied' => 0, 'shared' => 0, 'sharedDirs' => 0, 'mkdirs' => 0],
            $plan['summary']);
        $this->assertSame([['from' => '/Documentos/ata.md', 'to' => '/Arquivado/ata.md'],
            ['from' => '/Documentos/plano.md', 'to' => '/Arquivado/plano.md']], $plan['order'], 'a ordem do lote faz parte do que o usuário aprova');
        $this->assertSame([], $this->tree->ops, 'um plano não toca em nada');
        $this->assertSame([], $this->batches->rows, 'um plano não grava lote');
    }

    public function testThePlanSeesTheFoldersItWouldCreate(): void {
        $plan = $this->plan('files_move_batch', [
            'moves' => [['from' => '/Documentos/ata.md', 'to' => '/2026/03/ata.md']],
            'mkdirs' => ['/2026', '/2026/03'],
        ]);
        $this->assertSame([
            ['path' => '/2026', 'exists' => false, 'willCreate' => true],
            ['path' => '/2026/03', 'exists' => false, 'willCreate' => true],
        ], $plan['mkdirs']);
        $this->assertSame(2, $plan['summary']['mkdirs']);
        $this->assertTrue($plan['ok'], 'moves into planned folders must be accepted');
        $this->assertSame(1, $plan['summary']['planned']);
        $this->assertSame([], $this->tree->ops, 'o plano não cria a pasta, apenas diz que criaria');
    }

    /** A planned folder only grants its own path and its implied parents, not arbitrary descendants. */
    public function testMkdirsDoesNotApproveAnUnplannedDestination(): void {
        $plan = $this->plan('files_move_batch', [
            'moves' => [['from' => '/Documentos/ata.md', 'to' => '/New/Other/ata.md']],
            'mkdirs' => ['/New'],
        ]);
        $this->assertFalse($plan['ok']);
        $this->assertSame(CommonMessages::notFound(), $plan['denied'][0]['reason']);
        $this->assertSame([], $this->tree->ops);
    }

    /** Permissions on the existing ancestor still apply to a missing planned destination. */
    public function testMkdirsDoesNotBypassDestinationPermissions(): void {
        $this->tree->addFolder('/alice/files/ReadOnly', ['permissions' => \OCP\Constants::PERMISSION_READ]);
        $args = [
            'moves' => [['from' => '/Documentos/ata.md', 'to' => '/ReadOnly/New/ata.md']],
            'mkdirs' => ['/ReadOnly/New'],
        ];
        $plan = $this->plan('files_move_batch', $args);
        $this->assertFalse($plan['ok']);
        $this->assertSame(CommonMessages::forbidden(), $plan['denied'][0]['reason']);
        $this->failure('files_move_batch', $args);
        $this->assertSame([], $this->tree->ops);
    }

    /** Hidden ancestors remain not found even when mkdirs supplies the missing child. */
    public function testMkdirsDoesNotBypassDestinationVisibility(): void {
        $tagMapper = $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class);
        $this->config->app['mcp'][\OCA\Mcp\Service\VisibilityGuard::CONFIG_KEY] = json_encode(['999']);
        $this->visibilityGuard = new \OCA\Mcp\Service\VisibilityGuard($this->config->mock($this), $tagMapper);
        $this->setUp();
        $hiddenId = $this->tree->addFolder('/alice/files/Hidden');
        $tagMapper->method('getTagIdsForObjects')->willReturnCallback(static function (array $ids) use ($hiddenId): array {
            $tags = [];
            foreach ($ids as $id) {
                $tags[$id] = (string)$id === (string)$hiddenId ? ['999'] : [];
            }
            return $tags;
        });
        $args = [
            'moves' => [['from' => '/Documentos/ata.md', 'to' => '/Hidden/New/ata.md']],
            'mkdirs' => ['/Hidden/New'],
        ];
        $plan = $this->plan('files_move_batch', $args);
        $this->assertFalse($plan['ok']);
        $this->assertSame(CommonMessages::notFound(), $plan['denied'][0]['reason']);
        $this->failure('files_move_batch', $args);
        $this->assertSame([], $this->tree->ops);
    }

    /**
     * A folder the batch only creates, with a move that does not go there, still lands under the hidden folder: the
     * creation itself has to be refused, in the plan and in the confirmed run, and nothing may be made.
     */
    public function testMkdirsNeverCreatesAFolderInsideAHiddenOne(): void {
        $tagMapper = $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class);
        $this->config->app['mcp'][\OCA\Mcp\Service\VisibilityGuard::CONFIG_KEY] = json_encode(['999']);
        $this->visibilityGuard = new \OCA\Mcp\Service\VisibilityGuard($this->config->mock($this), $tagMapper);
        $this->setUp();
        $hiddenId = $this->tree->addFolder('/alice/files/Hidden');
        $tagMapper->method('getTagIdsForObjects')->willReturnCallback(static function (array $ids) use ($hiddenId): array {
            return array_combine($ids, array_map(static fn ($id) => (string)$id === (string)$hiddenId ? ['999'] : [], $ids));
        });
        foreach (['/Hidden/New' => CommonMessages::notFound(), '/Hidden' => CommonMessages::forbidden()] as $dir => $reason) {
            $args = ['moves' => [['from' => '/Documentos/ata.md', 'to' => '/Arquivado/ata.md']], 'mkdirs' => [$dir]];
            $plan = $this->plan('files_move_batch', $args);
            $this->assertFalse($plan['ok'], "plano de $dir");
            $this->assertSame([['path' => $dir, 'exists' => false, 'willCreate' => false, 'reason' => $reason]], $plan['mkdirs']);
            $this->assertSame(FilesMessages::batchNotOk(0, 1), $this->failure('files_move_batch', $args + ['confirm' => true]), "execução de $dir");
        }
        $this->assertSame([], $this->tree->ops, 'nem a pasta nem o movimento');
        $this->assertArrayNotHasKey('/alice/files/Hidden/New', $this->tree->nodes);
        $this->assertSame([], $this->batches->rows);
    }

    /**
     * A requested folder whose name a file already uses cannot be made. The plan says so instead of `ok`, and the run
     * refuses before the first folder is created: half of the folders made and no batch recorded would be folders
     * nobody can undo.
     */
    public function testMkdirsOverAFileIsRefusedBeforeAnyFolderIsCreated(): void {
        $this->tree->addFile('/alice/files/C', 'um arquivo', 'text/plain');
        $args = ['moves' => [['from' => '/Documentos/ata.md', 'to' => '/A/B/ata.md']], 'mkdirs' => ['/A/B', '/C']];
        $plan = $this->plan('files_move_batch', $args);
        $this->assertFalse($plan['ok'], 'o plano não diz ok para uma pasta que não pode ser criada');
        $this->assertSame([
            ['path' => '/A/B', 'exists' => false, 'willCreate' => true],
            ['path' => '/C', 'exists' => false, 'willCreate' => false, 'reason' => FilesMessages::destinationExists()],
        ], $plan['mkdirs']);
        $this->assertSame(FilesMessages::batchNotOk(0, 1), $this->failure('files_move_batch', $args + ['confirm' => true]));
        $this->assertSame([], $this->tree->ops, 'nenhuma pasta órfã');
        $this->assertArrayNotHasKey('/alice/files/A', $this->tree->nodes);
        $this->assertSame([], $this->batches->rows);
    }

    /**
     * createFolders() checks the whole list itself, not only through the plan: the undo calls it too, and a caller
     * that skipped the plan must still not be left with half of the folders made.
     */
    public function testCreateFoldersChecksEveryFolderBeforeMakingTheFirst(): void {
        $this->tree->addFile('/alice/files/C', 'um arquivo', 'text/plain');
        $access = new \OCA\Mcp\Tools\Common\NodeAccessInfo(
            \OCA\Mcp\Tests\Unit\Tools\FakeUsers::manager($this, \OCA\Mcp\Tests\Unit\Tools\FakeUsers::DEFAULTS), $this->tree->shareManager());
        $reorganization = new \OCA\Mcp\Tools\Files\Reorganization($access, new \OCA\Mcp\Tools\Common\SharedWriteGuard($access),
            $this->report(), $this->users);
        try {
            $reorganization->createFolders($this->tree->rootFolder(), ['/A/B', '/C']);
            $this->fail('createFolders aceitou uma pasta sobre um arquivo');
        } catch (\OCA\Mcp\Tools\ToolFailure $e) {
            $this->assertSame(FilesMessages::destinationExists(), $e->getMessage());
        }
        $this->assertSame([], $this->tree->ops);
    }

    /** A folder that cannot take a new entry is refused for mkdirs as it is for a move into it. */
    public function testMkdirsIntoAFolderThatCannotTakeItIsRefusedBeforeAnythingIsCreated(): void {
        $this->tree->addFolder('/alice/files/ReadOnly', ['permissions' => \OCP\Constants::PERMISSION_READ]);
        $args = ['moves' => [['from' => '/Documentos/ata.md', 'to' => '/New/ata.md']], 'mkdirs' => ['/New', '/ReadOnly/Sub']];
        $this->assertSame(CommonMessages::forbidden(), $this->plan('files_move_batch', $args)['mkdirs'][1]['reason']);
        $this->assertSame(FilesMessages::batchNotOk(0, 1), $this->failure('files_move_batch', $args + ['confirm' => true]));
        $this->assertSame([], $this->tree->ops);
        $this->assertArrayNotHasKey('/alice/files/New', $this->tree->nodes);
    }

    /** A future folder inherits the storage boundary of its real parent. */
    public function testMkdirsDoesNotBypassStorageBoundaries(): void {
        $this->tree->addFolder('/alice/files/External', ['storageId' => 'external']);
        $plan = $this->plan('files_move_batch', [
            'moves' => [['from' => '/Documentos/ata.md', 'to' => '/External/New/ata.md']],
            'mkdirs' => ['/External/New'],
        ]);
        $this->assertFalse($plan['ok']);
        $this->assertCount(1, $plan['conflicts']);
        $this->assertSame([], $this->tree->ops);
    }

    /** Listing a deeper mkdir also creates the receiving parent, but cannot replace a real file. */
    public function testMkdirsSupportsImpliedParentsAndRefusesFileAncestors(): void {
        $args = [
            'moves' => [['from' => '/Documentos/ata.md', 'to' => '/New/ata.md']],
            'mkdirs' => ['/New/Nested'],
        ];
        $this->assertTrue($this->plan('files_move_batch', $args)['ok']);
        $this->tree->addFile('/alice/files/New', 'occupied');
        $plan = $this->plan('files_move_batch', $args);
        $this->assertFalse($plan['ok']);
        $this->assertSame(CommonMessages::forbidden(), $plan['denied'][0]['reason']);
        $this->assertSame([], $this->tree->ops);
    }

    public function testThePlanSaysAFolderThatIsAlreadyThereIsNotCreated(): void {
        $plan = $this->plan('files_move_batch', [
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
        $plan = $this->plan('files_move_batch', [
            'moves' => [
                ['from' => '/Documentos/ata.md', 'to' => '/Arquivado/ata.md'],
                ['from' => '/Documentos/plano.md', 'to' => '/Arquivado/plano.md'],
                ['from' => '/Documentos/orcamento.md', 'to' => '/Arquivado/orcamento.md'],
            ],
        ]);
        $this->assertFalse($plan['ok'], 'um plano com item bloqueado não está ok');
        $this->assertSame([['from' => '/Documentos/ata.md', 'to' => '/Arquivado/ata.md', 'ok' => true]], $plan['moves']);
        $this->assertSame([['from' => '/Documentos/plano.md', 'to' => '/Arquivado/plano.md',
            'reason' => FilesMessages::destinationExists()]], $plan['conflicts']);
        $this->assertSame([['from' => '/Documentos/orcamento.md', 'to' => '/Arquivado/orcamento.md',
            'reason' => CommonMessages::forbidden()]], $plan['denied']);
        $this->assertSame(['total' => 3, 'planned' => 1, 'conflicts' => 1, 'denied' => 1, 'shared' => 0, 'sharedDirs' => 0, 'mkdirs' => 0],
            $plan['summary']);
    }

    public function testThePlanNamesAMissingSourceInsteadOfPretendingItWillMove(): void {
        $plan = $this->plan('files_move_batch', [
            'moves' => [['from' => '/Documentos/nao-existe.md', 'to' => '/Arquivado/nao-existe.md']],
        ]);
        $this->assertFalse($plan['ok']);
        $this->assertSame([['from' => '/Documentos/nao-existe.md', 'to' => '/Arquivado/nao-existe.md',
            'reason' => CommonMessages::notFound()]], $plan['denied']);
    }

    public function testThePlanRefusesAFolderIntoItself(): void {
        $plan = $this->plan('files_move_batch', [
            'moves' => [['from' => '/Documentos', 'to' => '/Documentos/sub']],
        ]);
        $this->assertFalse($plan['ok']);
        $this->assertSame(FilesMessages::folderIntoItself(), $plan['conflicts'][0]['reason']);
    }

    /**
     * The confirmation payload carries the plan with it, so the agent's code path is the same one it already
     * knows for a single move, and it can show the user what the batch would do.
     */
    public function testThePlanSaysAnItemOfSomebodyElseIsSharedInsteadOfAMoveThatWouldHappen(): void {
        $this->tree->addFile('/alice/files/Engenharia/plano.md', 'plano', 'text/markdown', ['scope' => 'team']);
        $this->tree->mountPath = '/alice/files/Engenharia';
        $out = $this->plan('files_move_batch', [
            'moves' => [['from' => '/Engenharia/plano.md', 'to' => '/Arquivado/plano.md']],
        ]);
        $this->assertTrue($out['requiresSharedConfirmation']);
        // A non-personal item is listed as shared, not as a move that would happen.
        $this->assertSame([], $out['moves']);
        $this->assertSame([['from' => '/Engenharia/plano.md', 'to' => '/Arquivado/plano.md', 'scope' => 'team']], $out['shared']);
        $this->assertSame(1, $out['summary']['shared']);
        $this->assertSame([], $this->tree->ops);
    }

    /**
     * M1: a folder the batch creates inside somebody else's share reaches them like `files_mkdir` does, so it asks
     * for the same shared-content confirmation, even when no move uses the folder and only personal items move.
     */
    private function teamFolderForMkdirs(): void {
        $this->tree->addFolder('/alice/files/Engenharia', ['scope' => 'team']);
        $this->tree->mountPath = '/alice/files/Engenharia';
    }

    public function testThePlanAsksForTheSharedConfirmationForAFolderCreatedInASharedFolder(): void {
        $this->teamFolderForMkdirs();
        $out = $this->plan('files_move_batch', [
            'moves' => [['from' => '/Documentos/ata.md', 'to' => '/Arquivado/ata.md']],
            'mkdirs' => ['/Engenharia/Nova'],
        ]);

        $this->assertTrue($out['ok']);
        $this->assertTrue($out['requiresSharedConfirmation']);
        $this->assertSame([['path' => '/Engenharia/Nova', 'scope' => 'team']], $out['sharedDirs']);
        $this->assertSame(1, $out['summary']['sharedDirs']);
        $this->assertSame([], $out['shared'], 'the move itself is personal');
        $this->assertSame([], $this->tree->ops);
    }

    public function testAnExecutionAsksForTheSharedConfirmationBeforeCreatingAFolderInASharedFolder(): void {
        $this->teamFolderForMkdirs();
        $out = $this->json('files_move_batch', [
            'moves' => [['from' => '/Documentos/ata.md', 'to' => '/Arquivado/ata.md']],
            'mkdirs' => ['/Engenharia/Nova'],
        ]);

        $this->assertTrue($out['requiresConfirmation']);
        $this->assertSame('team', $out['scope']);
        $this->assertArrayNotHasKey('batch_id', $out);
        $this->assertSame([], $this->tree->ops, 'neither the folder nor the move may happen before the confirmation');
        $this->assertSame([], $this->batches->rows);
    }

    public function testAConfirmedExecutionCreatesTheFolderInTheSharedFolderAndRecordsIt(): void {
        $this->teamFolderForMkdirs();
        $out = $this->json('files_move_batch', [
            'moves' => [['from' => '/Documentos/ata.md', 'to' => '/Arquivado/ata.md']],
            'mkdirs' => ['/Engenharia/Nova'],
            'confirm_shared' => true,
        ]);

        $this->assertSame(['/Engenharia/Nova'], $out['created_dirs']);
        $this->assertArrayHasKey('/alice/files/Engenharia/Nova', $this->tree->nodes);
        $this->assertSame(['/Engenharia/Nova'], $this->batches->find($out['batch_id'], 'alice')->dirs);
    }

    /** Only a folder the batch would really make counts: one already there is not created, so nothing to confirm. */
    public function testAFolderThatAlreadyExistsInASharedFolderNeedsNoSharedConfirmation(): void {
        $this->teamFolderForMkdirs();
        $this->tree->addFolder('/alice/files/Engenharia/Nova', ['scope' => 'team']);
        $out = $this->plan('files_move_batch', [
            'moves' => [['from' => '/Documentos/ata.md', 'to' => '/Arquivado/ata.md']],
            'mkdirs' => ['/Engenharia/Nova'],
        ]);

        $this->assertFalse($out['requiresSharedConfirmation']);
        $this->assertSame([], $out['sharedDirs']);
    }

    /** A folder in the user's own tree stays as before: no confirmation. */
    public function testAFolderInThePersonalTreeNeedsNoSharedConfirmation(): void {
        $out = $this->plan('files_move_batch', [
            'moves' => [['from' => '/Documentos/ata.md', 'to' => '/Arquivado/ata.md']],
            'mkdirs' => ['/Arquivado/Nova'],
        ]);

        $this->assertFalse($out['requiresSharedConfirmation']);
        $this->assertSame([], $out['sharedDirs']);
        $this->assertSame(0, $out['summary']['sharedDirs']);
    }

    // ------------------------------------------------------------- execution

    public function testAnExecutionMovesInOrderCreatesTheFoldersAndRecordsTheBatch(): void {
        $out = $this->json('files_move_batch', [
            'moves' => self::BATCH['moves'],
            'mkdirs' => ['/Arquivado/2026'],
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

    /** A nested mkdir implies its parents and must finish before the move. */
    public function testABatchMovesIntoANewNestedFolderAndCanBeUndone(): void {
        $args = [
            'moves' => [['from' => '/Documentos/ata.md', 'to' => '/New/Nested/ata.md']],
            'mkdirs' => ['/New/Nested'],
        ];
        $plan = $this->plan('files_move_batch', $args);
        $this->assertTrue($plan['ok']);
        $this->assertSame([], $this->tree->ops);
        $out = $this->json('files_move_batch', $args);
        $this->assertSame(['/New', '/New/Nested'], $out['created_dirs']);
        $this->assertSame([
            'mkdir /alice/files/New',
            'mkdir /alice/files/New/Nested',
            'move /alice/files/Documentos/ata.md /alice/files/New/Nested/ata.md',
        ], $this->tree->ops);
        $this->json('files_undo_batch', ['batch_id' => $out['batch_id']]);
        $this->assertArrayHasKey('/alice/files/Documentos/ata.md', $this->tree->nodes);
        $this->assertArrayNotHasKey('/alice/files/New', $this->tree->nodes);
    }

    public function testABatchWithASharedItemRefusesToMoveAnythingWithoutTheSharedConfirmation(): void {
        $this->tree->addFile('/alice/files/Engenharia/plano.md', 'plano', 'text/markdown', ['scope' => 'team']);
        $this->tree->mountPath = '/alice/files/Engenharia';
        $out = $this->json('files_move_batch', [
            'moves' => [
                ['from' => '/Documentos/ata.md', 'to' => '/Arquivado/ata.md'],
                ['from' => '/Engenharia/plano.md', 'to' => '/Arquivado/plano.md'],
            ],
        ]);
        $this->assertTrue($out['requiresConfirmation']);
        $this->assertArrayNotHasKey('batch_id', $out, 'sem confirm_shared nada é executado nem gravado');
        $this->assertSame([], $this->tree->ops);
        $this->assertSame([], $this->batches->rows);
    }

    /**
     * With confirm_shared the user said yes to the whole list, the shared item included: it moves in its place in the
     * order, is recorded and can be undone. Dropping it would hand the user a reorganization they did not approve.
     */
    public function testABatchConfirmedForSharedItemsMovesThemTooInOrder(): void {
        $this->tree->addFile('/alice/files/Engenharia/plano.md', 'plano', 'text/markdown', ['scope' => 'team']);
        $this->tree->mountPath = '/alice/files/Engenharia';
        $out = $this->json('files_move_batch', [
            'moves' => [
                ['from' => '/Documentos/ata.md', 'to' => '/Arquivado/ata.md'],
                ['from' => '/Engenharia/plano.md', 'to' => '/Arquivado/plano.md'],
                ['from' => '/Documentos/orcamento.md', 'to' => '/Arquivado/orcamento.md'],
            ],
            'confirm_shared' => true,
        ]);
        $this->assertSame(['/Documentos/ata.md', '/Engenharia/plano.md', '/Documentos/orcamento.md'], array_column($out['moved'], 'from'));
        $this->assertSame(3, $out['undos']);
        $this->assertArrayNotHasKey('failed', $out);
        $this->assertSame([
            'move /alice/files/Documentos/ata.md /alice/files/Arquivado/ata.md',
            'move /alice/files/Engenharia/plano.md /alice/files/Arquivado/plano.md',
            'move /alice/files/Documentos/orcamento.md /alice/files/Arquivado/orcamento.md',
        ], $this->tree->ops, 'o item compartilhado vai no lugar dele na ordem');
        $this->assertSame(['/Documentos/ata.md', '/Engenharia/plano.md', '/Documentos/orcamento.md'],
            array_column($this->batches->find($out['batch_id'], 'alice')->moves, 'from'), 'e o desfazer o conhece');

        $undo = $this->json('files_undo_batch', ['batch_id' => $out['batch_id']]);
        $this->assertSame(3, $undo['undone']);
        $this->assertArrayHasKey('/alice/files/Engenharia/plano.md', $this->tree->nodes);
    }

    public function testAnExecutionStopsAtTheFirstErrorAndRecordsOnlyWhatItMoved(): void {
        $this->tree->failMove = ['/alice/files/Documentos/ata.md'];
        $out = $this->json('files_move_batch', [
            'moves' => [
                ['from' => '/Documentos/ata.md', 'to' => '/Arquivado/ata.md'],
                ['from' => '/Documentos/plano.md', 'to' => '/Arquivado/plano.md'],
                ['from' => '/Documentos/orcamento.md', 'to' => '/Arquivado/orcamento.md'],
            ],
        ]);
        // Nothing is refused at plan time here, so the failure has to come from the move itself: a lock or a
        // permission that changed between the plan and the execution.
        $this->assertSame(1, $out['batch_id'], 'o lote é gravado mesmo assim, para o desfazer ter o que reverter');
        $this->assertSame([], $out['moved']);
        $this->assertSame('/Documentos/ata.md', $out['failed']['from']);
        $this->assertSame('/Arquivado/ata.md', $out['failed']['to']);
        $this->assertSame(CommonMessages::forbidden(), $out['failed']['reason']);
        $this->assertSame(['/Documentos/plano.md', '/Documentos/orcamento.md'],
            array_column($out['not_attempted'], 'from'), 'o que não foi tentado é dito, não escondido');
        $this->assertSame([], $this->batches->find(1, 'alice')->moves);
    }

    /**
     * Through the production store: the batch alice ran is not bob's, and a batch of bob is answered to alice exactly
     * like one that never existed, in the plan of the undo (which would list its paths) and in the undo itself.
     */
    public function testABatchOfSomebodyElseIsNotFound(): void {
        $db = new \OCA\Mcp\Tests\Unit\SqliteDatabase($this, [new \OCA\Mcp\Migration\Version000800Date20261002000000()]);
        $this->batchStore = new \OCA\Mcp\Tools\Files\BatchStore($db->connection());
        $this->setUp();
        $mine = $this->json('files_move_batch', self::BATCH)['batch_id'];
        $this->assertNull($this->batchStore->find($mine, 'bob'), 'o lote existe, mas não é de bob');
        $this->assertNotNull($this->batchStore->find($mine, 'alice'));

        $theirs = $this->batchStore->insert(new \OCA\Mcp\Tools\Files\Batch(null, 'bob', 1790000000,
            [['from' => '/Documentos/orcamento.md', 'to' => '/Arquivado/orcamento.md', 'toId' => 1]], [], null));
        foreach ([fn () => $this->plan('files_undo_batch', ['batch_id' => $theirs]),
            fn () => $this->tool('files_undo_batch', ['batch_id' => $theirs, 'confirm' => true])] as $call) {
            try {
                $call();
                $this->fail('o lote de bob foi achado por alice');
            } catch (\OCA\Mcp\Tools\ToolFailure $e) {
                $this->assertSame(FilesMessages::batchNotFound(), $e->getMessage());
            }
        }
        $this->assertNull($db->rows(\OCA\Mcp\Tools\Files\BatchStore::TABLE)[1]['undone_at'], 'o lote de bob segue intacto');
    }
}
