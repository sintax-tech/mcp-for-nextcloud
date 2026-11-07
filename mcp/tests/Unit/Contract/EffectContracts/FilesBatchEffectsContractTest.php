<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Tests\Unit\Contract\Oracle\CriticalFlow;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\FlowRunner;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use PHPUnit\Framework\TestCase;

/**
 * Effect contract of the undoable batches of Files (RE-01): files_move_batch and files_undo_batch frozen through the
 * endpoint over a fake tree and the in-memory batch store, the batch row being part of the observed effect. The
 * batch_id the endpoint answers is volatile (VolatileFields); the store rows keep their sequential ids.
 */
final class FilesBatchEffectsContractTest extends TestCase {
	/** Where the files of a batch start. */
	private const INBOX = '/alice/files/Entrada';
	/** Root of the folders the batches create. */
	private const OUTBOX = '/alice/files/Saida';
	/** Batches the undo flow starts with: the runner undoes one confirmed, one as a guest, and hides one. */
	private const UNDO_BATCHES = 3;

	private FakeTree $tree;
	private EffectsGate $gate;
	private FilesFlowWiring $wiring;

	/** @return void builds the tree and the endpoint serving the Files module over the batch store */
	protected function setUp(): void {
		$this->tree = new FakeTree($this);
		foreach (['a', 'b'] as $name) {
			$this->tree->addFile(self::INBOX . '/' . $name . '.md', 'conteúdo ' . $name, 'text/markdown');
		}
		$this->tree->addFolder(self::OUTBOX);
		$this->gate = new EffectsGate($this);
		$this->wiring = new FilesFlowWiring($this, $this->gate, $this->tree);
		$this->gate->serve(fn (): array => [$this->wiring->module()]);
	}

	/** @return void files_move_batch: plan, strict confirm, folder created, both moves and the undo record, every refusal */
	public function testBatchMovesAndRecordsItsUndo(): void {
		$a = $this->tree->nodes[self::INBOX . '/a.md']['id'];
		$flow = (new CriticalFlow('move-batch', 'files_move_batch', 'files', 'move',
			fn (): array => isset($this->tree->nodes[self::INBOX . '/a.md']) ? [
				'moves' => [['from' => '/Entrada/a.md', 'to' => '/Saida/Lote/a.md'], ['from' => '/Entrada/b.md', 'to' => '/Saida/Lote/b.md']],
				'mkdirs' => isset($this->tree->nodes[self::OUTBOX . '/Lote']) ? [] : ['/Saida/Lote'],
			] : [
				'moves' => [['from' => '/Saida/Lote/a.md', 'to' => '/Entrada/a.md'], ['from' => '/Saida/Lote/b.md', 'to' => '/Entrada/b.md']],
			],
			fn (): array => $this->state()))
			->withoutPlanChanged('files_move_batch carries no plan_state: the plan is rebuilt by the confirmed call and a '
				. 'batch whose rebuilt plan is not ok is refused whole, never answered with a new plan')
			->hiding(fn () => $this->wiring->hidden = [$a], fn () => $this->wiring->hidden = []);
		(new FlowRunner($this, $this->gate, 'files'))->run($flow);

		$rows = FilesEffectState::batches($this->wiring->batches);
		$this->assertCount(2, $rows, 'the confirmed and the guest batch are recorded');
		$this->assertSame([['from' => '/Entrada/a.md', 'to' => '/Saida/Lote/a.md', 'toId' => $a],
			['from' => '/Entrada/b.md', 'to' => '/Saida/Lote/b.md', 'toId' => $a + 1]], $rows[0]['moves']);
		$this->assertSame(['/Saida/Lote'], $rows[0]['dirs']);
		$this->assertSame('conteúdo a', $this->tree->nodes[self::INBOX . '/a.md']['content'] ?? null, 'the guest batch brought them home');
	}

	/** @return void files_undo_batch: plan, strict confirm, every item goes back and the batch folder goes, every refusal */
	public function testUndoPutsEveryItemBack(): void {
		$this->runBatches();
		$hidden = $this->tree->nodes[self::OUTBOX . '/Lote1/a1.md']['id'];
		$flow = (new CriticalFlow('undo-batch', 'files_undo_batch', 'files', 'move',
			// The newest batch not yet undone: each confirmation the runner sends undoes a batch that is still open.
			fn (): array => ['batch_id' => max(array_keys(array_filter($this->wiring->batches->rows,
				static fn (array $row): bool => $row['undone_at'] === null)) ?: [1])],
			fn (): array => $this->state()))
			->withoutPlanChanged('files_undo_batch carries no plan_state: every condition is checked again by the '
				. 'confirmed call, and an item that diverged is reported as a conflict, never answered with a new plan')
			->hiding(fn () => $this->wiring->hidden = [$hidden], fn () => $this->wiring->hidden = []);
		(new FlowRunner($this, $this->gate, 'files'))->run($flow);

		// Batch 3 undone by the confirmed step, batch 2 by the guest step, batch 1 refused while its item was hidden.
		foreach ([3, 2] as $n) {
			foreach (['a', 'b'] as $name) {
				$this->assertSame('conteúdo ' . $name . $n, $this->tree->nodes[self::INBOX . '/' . $name . $n . '.md']['content'] ?? null,
					'batch ' . $n . ': ' . $name . ' went back');
			}
			$this->assertArrayNotHasKey(self::OUTBOX . '/Lote' . $n, $this->tree->nodes, 'batch ' . $n . ': its emptied folder is gone');
			$this->assertSame(FilesFlowWiring::NOW, $this->wiring->batches->rows[$n]['undone_at']);
			$this->assertSame('[]', $this->wiring->batches->rows[$n]['moves_json'], 'nothing left to undo');
		}
		$this->assertNull($this->wiring->batches->rows[1]['undone_at'], 'the hidden batch is still open');
		$this->assertArrayHasKey(self::OUTBOX . '/Lote1/a1.md', $this->tree->nodes);
	}

	/** @return void undoing a batch whose item went somewhere else is a conflict that moves nothing back */
	public function testUndoOfADivergedBatchMovesNothing(): void {
		$this->runBatches();
		$transcript = new EffectTranscript($this, 'files.undo-batch-refusals');
		// Another client renames one item of batch 3 after it ran.
		$this->tree->node(self::OUTBOX . '/Lote3/b3.md')->move(self::OUTBOX . '/Lote3/outro.md');
		$this->tree->ops = [];
		foreach (['diverged-plan' => ['batch_id' => 3], 'diverged-confirmed' => ['batch_id' => 3, 'confirm' => true],
			'unknown-confirmed' => ['batch_id' => 99, 'confirm' => true]] as $step => $arguments) {
			$response = $this->gate->call('files_undo_batch', $arguments);
			$transcript->record($step, $arguments, $response, $this->state());
		}
		$this->assertSame([], $this->tree->ops, 'a diverged batch moves nothing back');
		$this->assertNull($this->wiring->batches->rows[3]['undone_at']);
		$transcript->assertGolden();
	}

	/** @return void runs {@see self::UNDO_BATCHES} batches through the endpoint, each moving two files into its own new folder */
	private function runBatches(): void {
		for ($n = 1; $n <= self::UNDO_BATCHES; $n++) {
			foreach (['a', 'b'] as $name) {
				$this->tree->addFile(self::INBOX . '/' . $name . $n . '.md', 'conteúdo ' . $name . $n, 'text/markdown');
			}
			$response = $this->gate->call('files_move_batch', ['confirm' => true, 'mkdirs' => ['/Saida/Lote' . $n], 'moves' => [
				['from' => '/Entrada/a' . $n . '.md', 'to' => '/Saida/Lote' . $n . '/a' . $n . '.md'],
				['from' => '/Entrada/b' . $n . '.md', 'to' => '/Saida/Lote' . $n . '/b' . $n . '.md'],
			]]);
			$this->assertTrue(isset($response['body']->result) && !($response['body']->result->isError ?? false), json_encode($response['body']));
		}
		$this->assertCount(self::UNDO_BATCHES, $this->wiring->batches->rows);
		$this->tree->ops = [];
	}

	/** @return array<string, mixed> the tree and the recorded batches */
	private function state(): array {
		return ['tree' => FilesEffectState::tree($this->tree), 'batches' => FilesEffectState::batches($this->wiring->batches)];
	}
}
