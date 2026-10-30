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
 * Effect contract of the single-node reorganization of Files (RE-01): files_move, files_copy and files_mkdir frozen
 * through the endpoint over a fake tree, with a hidden source (FlowRunner's hidden step) and a hidden destination
 * (asked directly) behind the real FilesAccessGuard.
 *
 * Each flow computes its arguments from the tree as it is, so every confirmation the runner sends is one that would
 * succeed at that moment and only the refusal under test stops it: the move goes back and forth between two folders,
 * the copy and the new folder take the next free name.
 */
final class FilesReorganizationEffectsContractTest extends TestCase {
	/** The folder files start in. */
	private const FROM = '/alice/files/Documentos';
	/** The folder files go to. */
	private const TO = '/alice/files/Arquivo';
	/** The file moved and copied. */
	private const NAME = 'ata.md';

	private FakeTree $tree;
	private EffectsGate $gate;
	private FilesFlowWiring $wiring;

	/** @return void builds the tree and the endpoint serving the Files module */
	protected function setUp(): void {
		$this->tree = new FakeTree($this);
		$this->tree->addFile(self::FROM . '/' . self::NAME, "# Ata\nolá", 'text/markdown');
		$this->tree->addFolder(self::TO);
		$this->tree->addFolder('/alice/files/Projetos');
		$this->gate = new EffectsGate($this);
		$this->wiring = new FilesFlowWiring($this, $this->gate, $this->tree);
		$this->gate->serve(fn (): array => [$this->wiring->module()]);
	}

	/** @return void files_move: plan, strict confirm, the node keeps its id at its new path, every refusal */
	public function testMoveOfAFile(): void {
		$id = $this->tree->nodes[self::FROM . '/' . self::NAME]['id'];
		$flow = (new CriticalFlow('move', 'files_move', 'files', 'move',
			fn (): array => $this->here(self::FROM)
				? ['from' => '/Documentos/' . self::NAME, 'to' => '/Arquivo/' . self::NAME]
				: ['from' => '/Arquivo/' . self::NAME, 'to' => '/Documentos/' . self::NAME],
			fn (): array => FilesEffectState::tree($this->tree)))
			->withoutPlanChanged('files_move carries no plan_state: a source or destination that changed after the plan '
				. 'is refused on the ETag the caller sends or as a taken destination, never answered with a new plan')
			->hiding(fn () => $this->wiring->hidden = [$id], fn () => $this->wiring->hidden = []);
		(new FlowRunner($this, $this->gate, 'files'))->run($flow);
		// Confirmed (to Arquivo), then the guest confirmation (back home); a move never draws a new id.
		$this->assertSame($id, $this->tree->nodes[self::FROM . '/' . self::NAME]['id'] ?? null);
		$this->assertSame(['move ' . self::FROM . '/' . self::NAME . ' ' . self::TO . '/' . self::NAME,
			'move ' . self::TO . '/' . self::NAME . ' ' . self::FROM . '/' . self::NAME], $this->tree->ops);
	}

	/** @return void files_copy: plan, strict confirm, a new node with the same bytes and its own id, every refusal */
	public function testCopyOfAFile(): void {
		$id = $this->tree->nodes[self::FROM . '/' . self::NAME]['id'];
		$flow = (new CriticalFlow('copy', 'files_copy', 'files', 'create',
			fn (): array => ['from' => '/Documentos/' . self::NAME, 'to' => '/Arquivo/' . $this->free(self::TO, 'ata', '.md')],
			fn (): array => FilesEffectState::tree($this->tree)))
			->withoutPlanChanged('files_copy carries no plan_state: a destination taken after the plan is refused as a '
				. 'conflict, never answered with a new plan')
			->hiding(fn () => $this->wiring->hidden = [$id], fn () => $this->wiring->hidden = []);
		(new FlowRunner($this, $this->gate, 'files'))->run($flow);
		$this->assertSame("# Ata\nolá", $this->tree->nodes[self::TO . '/ata-1.md']['content'] ?? null);
		$this->assertNotSame($id, $this->tree->nodes[self::TO . '/ata-1.md']['id']);
		$this->assertSame("# Ata\nolá", $this->tree->nodes[self::FROM . '/' . self::NAME]['content'], 'the source is untouched');
	}

	/** @return void files_mkdir: plan, strict confirm, the folder appears, every refusal; hidden is the parent */
	public function testMkdirOfAFolder(): void {
		$parent = $this->tree->nodes['/alice/files/Projetos']['id'];
		$flow = (new CriticalFlow('mkdir', 'files_mkdir', 'files', 'create',
			fn (): array => ['path' => '/Projetos/' . $this->free('/alice/files/Projetos', '2026', '')],
			fn (): array => FilesEffectState::tree($this->tree)))
			->withoutPlanChanged('files_mkdir carries no plan_state: a name taken after the plan is reported by the '
				. 'confirmed call itself, never answered with a new plan')
			->hiding(fn () => $this->wiring->hidden = [$parent], fn () => $this->wiring->hidden = []);
		(new FlowRunner($this, $this->gate, 'files'))->run($flow);
		$this->assertSame(['mkdir /alice/files/Projetos/2026-1', 'mkdir /alice/files/Projetos/2026-2'], $this->tree->ops);
	}

	/** @return void a hidden destination folder refuses the plan and the confirmation of a move and of a copy */
	public function testAHiddenDestinationIsRefused(): void {
		$transcript = new EffectTranscript($this, 'files.reorganization-hidden-destination');
		$this->wiring->hidden = [$this->tree->nodes[self::TO]['id']];
		$move = ['from' => '/Documentos/' . self::NAME, 'to' => '/Arquivo/' . self::NAME];
		$copy = ['from' => '/Documentos/' . self::NAME, 'to' => '/Arquivo/copia.md'];
		$batch = ['moves' => [$move]];
		foreach ([['files_move', $move], ['files_move', $move + ['confirm' => true]], ['files_copy', $copy],
			['files_copy', $copy + ['confirm' => true]], ['files_mkdir', ['path' => '/Arquivo/Sub', 'confirm' => true]],
			['files_move_batch', $batch + ['confirm' => true]]] as [$tool, $arguments]) {
			$response = $this->gate->call($tool, $arguments);
			$refused = ($response['body']->result->isError ?? false) || isset($response['body']->error)
				|| ($response['body']->result->structuredContent->ok ?? null) === false;
			$transcript->record($tool . (isset($arguments['confirm']) ? '-confirmed' : '-plan'), $arguments, $response, FilesEffectState::tree($this->tree));
			if (isset($arguments['confirm'])) {
				$this->assertTrue($refused, $tool . ': a hidden destination must be refused: ' . json_encode($response['body']));
			}
		}
		$this->assertSame([], $this->tree->ops, 'nothing moved, copied or created into a hidden folder');
		$this->assertSame([], $this->wiring->batches->rows, 'no batch recorded');
		$transcript->assertGolden();
	}

	/**
	 * @param string $folder absolute folder path
	 * @return bool whether the moved file is currently in that folder
	 */
	private function here(string $folder): bool {
		return isset($this->tree->nodes[$folder . '/' . self::NAME]);
	}

	/**
	 * @param string $folder absolute folder path
	 * @param string $base name without the counter
	 * @param string $extension extension, with its dot, or ''
	 * @return string the first `<base>-<n><extension>` not yet in that folder
	 */
	private function free(string $folder, string $base, string $extension): string {
		for ($n = 1; isset($this->tree->nodes[$folder . '/' . $base . '-' . $n . $extension]); $n++) {
		}
		return $base . '-' . $n . $extension;
	}
}
