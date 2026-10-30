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
 * Effect contract of the protected Files edits (RE-01): files_edit and files_replace with the mandatory original copy
 * (backup settings at their default: copies on, in "MCP backups"), frozen through the endpoint over a fake tree, the
 * real FilesAccessGuard behind a hidden system tag, and the stale-ETag refusal asked directly.
 */
final class FilesEditEffectsContractTest extends TestCase {
	/** The file alice edits, as she sees it. */
	private const PATH = '/Documentos/ata.md';
	/** Its absolute path in the fake tree. */
	private const NODE = '/alice/files/Documentos/ata.md';
	/** Its content before any edit. */
	private const ORIGINAL = "# Ata\nolá";
	/** The two contents the edit alternates between, so every confirmation the runner sends is a real change. */
	private const VERSIONS = ["# Ata\nversão 1\n", "# Ata\nversão 2\n"];
	/** Folder of the original copies, mirrored below the backup root. */
	private const BACKUPS = '/alice/files/MCP backups/Documentos';

	private FakeTree $tree;
	private EffectsGate $gate;
	private FilesFlowWiring $wiring;

	/** @return void builds the tree and the endpoint serving the Files module with backup copies on */
	protected function setUp(): void {
		$this->tree = new FakeTree($this);
		$this->tree->addFile(self::NODE, self::ORIGINAL, 'text/markdown');
		$this->gate = new EffectsGate($this);
		$this->wiring = new FilesFlowWiring($this, $this->gate, $this->tree);
		// The canonical document stated explicitly: Files copies required, in the default folder.
		$this->gate->data()->app['mcp']['backup_settings'] = json_encode(['version' => 1, 'folder' => 'MCP backups',
			'reserved' => ['MCP backups'], 'copies' => true]);
		$this->gate->serve(fn (): array => [$this->wiring->module()]);
	}

	/** @return void files_edit: plan with diff and backup, strict confirm, the copy then the write, every refusal */
	public function testEditTakesTheOriginalCopyBeforeTheWrite(): void {
		$flow = (new CriticalFlow('edit-backup', 'files_edit', 'files', 'edit',
			fn (): array => ['path' => self::PATH, 'content' => $this->content() === self::VERSIONS[0] ? self::VERSIONS[1] : self::VERSIONS[0],
				'etag' => $this->tree->nodes[self::NODE]['etag']],
			fn (): array => FilesEffectState::tree($this->tree)))
			->withoutPlanChanged('files_edit carries no plan_state: the plan is bound to the ETag the caller sends, and a '
				. 'file changed after the plan is refused as a conflict (see files.edit-refusals), never answered with a new plan');
		$this->hidingTheFile($flow);
		(new FlowRunner($this, $this->gate, 'files'))->run($flow);

		// The first confirmed edit copied the untouched original before writing; the copy is never rewritten afterwards.
		$first = self::BACKUPS . '/ata.md.' . $this->stamp() . '.bak';
		$this->assertSame(self::ORIGINAL, $this->tree->nodes[$first]['content'] ?? null, 'the original copy holds the old bytes');
		$this->assertLessThan(array_search('write ' . self::NODE, $this->tree->ops, true),
			array_search('write ' . $first, $this->tree->ops, true), 'the copy is written before the file');
		$this->assertSame(self::VERSIONS[0], $this->tree->nodes[self::BACKUPS . '/ata.md.' . $this->stamp() . '-2.bak']['content'] ?? null,
			'the guest edit copied the first edit before replacing it');
	}

	/** @return void files_replace: the same copy before a snippet replacement, plan, strict confirm and every refusal */
	public function testReplaceTakesTheOriginalCopyBeforeTheWrite(): void {
		$flow = (new CriticalFlow('replace-backup', 'files_replace', 'files', 'edit',
			fn (): array => str_contains($this->content(), 'olá')
				? ['path' => self::PATH, 'old' => 'olá', 'new' => 'mundo', 'etag' => $this->tree->nodes[self::NODE]['etag']]
				: ['path' => self::PATH, 'old' => 'mundo', 'new' => 'olá', 'etag' => $this->tree->nodes[self::NODE]['etag']],
			fn (): array => FilesEffectState::tree($this->tree)))
			->withoutPlanChanged('files_replace carries no plan_state: a file changed after the plan is refused as a '
				. 'conflict on its ETag or as a snippet no longer found, never answered with a new plan');
		$this->hidingTheFile($flow);
		(new FlowRunner($this, $this->gate, 'files'))->run($flow);

		$this->assertSame(self::ORIGINAL, $this->tree->nodes[self::BACKUPS . '/ata.md.' . $this->stamp() . '.bak']['content'] ?? null);
		$this->assertSame("# Ata\nmundo", $this->tree->nodes[self::BACKUPS . '/ata.md.' . $this->stamp() . '-2.bak']['content'] ?? null);
	}

	/** @return void a stale ETag refuses the plan and the confirmation alike, with no copy and no write */
	public function testAStaleEtagIsAConflictThatWritesNothing(): void {
		$transcript = new EffectTranscript($this, 'files.edit-refusals');
		$stale = ['path' => self::PATH, 'content' => self::VERSIONS[0], 'etag' => $this->tree->nodes[self::NODE]['etag']];
		// Another client writes the file after alice read its ETag.
		$this->tree->nodes[self::NODE]['content'] = "# Ata\noutra pessoa";
		$this->tree->nodes[self::NODE]['etag'] .= '-outro';
		foreach (['plan' => $stale, 'confirmed' => $stale + ['confirm' => true],
			'replace-confirmed' => ['path' => self::PATH, 'old' => 'outra', 'new' => 'minha', 'etag' => $stale['etag'], 'confirm' => true]] as $step => $arguments) {
			$tool = isset($arguments['old']) ? 'files_replace' : 'files_edit';
			$response = $this->gate->call($tool, $arguments);
			$this->assertTrue($response['body']->result->isError ?? false, $step . ': a stale ETag must be refused');
			$transcript->record($step, ['tool' => $tool, 'arguments' => $arguments], $response, FilesEffectState::tree($this->tree));
		}
		$this->assertSame([], $this->tree->ops, 'no copy and no write after a conflict');
		$transcript->assertGolden();
	}

	/**
	 * @param CriticalFlow $flow the edit flow
	 * @return void hides the edited file with the hidden system tag for the hidden step
	 */
	private function hidingTheFile(CriticalFlow $flow): void {
		$file = $this->tree->nodes[self::NODE]['id'];
		$flow->hiding(function () use ($file): void {
			$this->wiring->hidden = [$file];
		}, function (): void {
			$this->wiring->hidden = [];
		});
	}

	/** @return string the current bytes of the edited file */
	private function content(): string {
		return $this->tree->nodes[self::NODE]['content'];
	}

	/** @return string the backup stamp of the fixed clock: alice has no timezone set, so it is rendered in UTC */
	private function stamp(): string {
		return '20260921-141320';
	}
}
