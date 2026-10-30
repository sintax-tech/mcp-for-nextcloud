<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Tools\Common\FakeLock;
use OCA\Mcp\Tests\Unit\Tools\Common\FakeLockManager;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCP\Files\Lock\ILock;
use PHPUnit\Framework\TestCase;

/** Manual locks refuse another user and permit their owner, with native state observed in both cases. */
final class WriteLockEffectsContractTest extends TestCase {
	/** @return void content and reorganization refusals freeze the complete tree and operation log */
	public function testFilesLocksPrecedeTheBackupAndMove(): void {
		foreach (['files_edit' => ['content' => 'new'], 'files_replace' => ['old' => 'old', 'new' => 'new'],
			'files_move' => ['from' => '/Docs/old.md', 'to' => '/Docs/new.md']] as $tool => $args) {
			$gate = new EffectsGate($this);
			$tree = new FakeTree($this);
			$id = $tree->addFile('/alice/files/Docs/old.md', 'old', 'text/markdown');
			$wiring = new FilesFlowWiring($this, $gate, $tree);
			$gate->serve(fn (): array => [$wiring->module()]);
			$wiring->locks->put(new FakeLock($id, ILock::TYPE_USER, 'pedro'));
			$this->refused($gate, $tree, $tool, isset($args['from']) ? $args : ['path' => '/Docs/old.md', 'etag' => $tree->nodes['/alice/files/Docs/old.md']['etag']] + $args);
			$this->assertSame([], $wiring->locks->forbidden);
		}
	}

	/** @return void Notes edit/move/delete likewise keep both files and backup/category state unchanged */
	public function testNotesLocksWriteNothing(): void {
		foreach (['notes_edit' => ['content' => 'new'], 'notes_move' => ['category' => 'Arquivo'], 'notes_delete' => []] as $tool => $args) {
			$gate = new EffectsGate($this);
			$tree = new FakeTree($this);
			$id = $tree->addFile('/alice/files/Notes/old.md', 'old', 'text/markdown');
			$locks = new FakeLockManager();
			$locks->put(new FakeLock($id, ILock::TYPE_USER, 'pedro'));
			$gate->serve(fn (): array => [NotesWritesWiring::module($this, $tree, $gate, fn (): array => [], $locks)]);
			$this->refused($gate, $tree, $tool, ['id' => $id, 'etag' => $tree->nodes['/alice/files/Notes/old.md']['etag']] + $args);
			$this->assertSame([], $locks->forbidden);
		}
	}

	/** @return void Alice edits and moves her manually locked file, and edits her own locked note */
	public function testOwnManualLocksPermitTheWrites(): void {
		foreach (['files_edit', 'files_move', 'notes_edit'] as $tool) {
			$gate = new EffectsGate($this);
			$tree = new FakeTree($this);
			$path = $tool === 'notes_edit' ? '/alice/files/Notes/old.md' : '/alice/files/Docs/old.md';
			$id = $tree->addFile($path, 'old', 'text/markdown');
			$locks = new FakeLockManager();
			if ($tool === 'notes_edit') {
				$gate->serve(fn (): array => [NotesWritesWiring::module($this, $tree, $gate, fn (): array => [], $locks)]);
				$args = ['id' => $id, 'content' => 'new', 'etag' => $tree->nodes[$path]['etag']];
			} else {
				$wiring = new FilesFlowWiring($this, $gate, $tree);
				$locks = $wiring->locks;
				$gate->serve(fn (): array => [$wiring->module()]);
				$args = $tool === 'files_move' ? ['from' => '/Docs/old.md', 'to' => '/Docs/new.md']
					: ['path' => '/Docs/old.md', 'content' => 'new', 'etag' => $tree->nodes[$path]['etag']];
			}
			$lock = new FakeLock($id, ILock::TYPE_USER, 'alice');
			$locks->put($lock);
			$t = new EffectTranscript($this, $tool . '.own-lock');
			$plan = $gate->call($tool, $args);
			$t->record('own-locked-preview', $args, $plan, FilesEffectState::tree($tree));
			$this->assertTrue($plan['body']->result->structuredContent->requiresConfirmation ?? false, json_encode($plan));
			$args += ['confirm' => true];
			if (isset($plan['body']->result->structuredContent->plan_state)) {
				$args['plan_state'] = $plan['body']->result->structuredContent->plan_state;
			}
			$out = $gate->call($tool, $args);
			$t->record('own-locked-confirmed', $args, $out, FilesEffectState::tree($tree));
			$this->assertFalse($out['body']->result->isError ?? false, json_encode($out));
			if ($tool === 'files_move') {
				$this->assertArrayNotHasKey($path, $tree->nodes);
				$this->assertSame($id, $tree->nodes['/alice/files/Docs/new.md']['id']);
				$this->assertSame('old', $tree->nodes['/alice/files/Docs/new.md']['content']);
			} else {
				$this->assertSame('new', $tree->nodes[$path]['content']);
			}
			$this->assertNotSame([], $tree->ops);
			$this->assertSame([$lock], $locks->locks[$id], 'the manual lock itself remains owned by Alice');
			$this->assertSame([], $locks->forbidden, 'no release or borrowed lock scope');
			$t->assertGolden();
		}
	}

	/**
	 * @param EffectsGate $gate endpoint
	 * @param FakeTree $tree observed state
	 * @param string $tool tool
	 * @param array<string, mixed> $args valid arguments of an otherwise writable resource
	 * @return void
	 */
	private function refused(EffectsGate $gate, FakeTree $tree, string $tool, array $args): void {
		$t = new EffectTranscript($this, $tool . '.locked');
		$before = FilesEffectState::tree($tree);
		$plan = $gate->call($tool, $args);
		$t->record('locked-preview', $args, $plan, FilesEffectState::tree($tree));
		$args += ['confirm' => true];
		if (isset($plan['body']->result->structuredContent->plan_state)) {
			$args['plan_state'] = $plan['body']->result->structuredContent->plan_state;
		}
		$out = $gate->call($tool, $args);
		$t->record('locked-confirmed', $args, $out, FilesEffectState::tree($tree));
		$this->assertTrue($out['body']->result->isError ?? false, json_encode($out));
		$this->assertEquals($before, FilesEffectState::tree($tree), 'no backup, folder or native write for a locked target');
		$t->assertGolden();
	}
}
