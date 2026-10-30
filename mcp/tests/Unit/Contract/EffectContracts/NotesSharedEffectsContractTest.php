<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use PHPUnit\Framework\TestCase;

/** Notes edit/move/delete in a team folder: a valid confirmation still needs confirm_shared. */
final class NotesSharedEffectsContractTest extends TestCase {
	/** @return void the unconfirmed shared write asks, writes nothing, then executes once explicitly approved */
	public function testEachSharedWriteNeedsTheAdditionalConfirmation(): void {
		foreach (['notes_edit' => ['content' => 'new'], 'notes_move' => ['category' => 'Archive'], 'notes_delete' => []] as $tool => $args) {
			$gate = new EffectsGate($this);
			$gate->data()->user['alice']['notes']['notesPath'] = 'Team';
			$tree = new FakeTree($this);
			$tree->mountPath = '/alice/files/Team';
			$tree->addFolder('/alice/files/Team', ['scope' => 'team']);
			$id = $tree->addFile('/alice/files/Team/old.md', 'old', 'text/markdown', ['scope' => 'team']);
			$gate->serve(fn (): array => [NotesWritesWiring::module($this, $tree, $gate, fn (): array => [])]);
			$t = new EffectTranscript($this, $tool . '.shared');
			$args += ['id' => $id, 'etag' => $tree->nodes['/alice/files/Team/old.md']['etag']];
			$plan = $gate->call($tool, $args);
			$args += ['confirm' => true];
			if (isset($plan['body']->result->structuredContent->plan_state)) {
				$args['plan_state'] = $plan['body']->result->structuredContent->plan_state;
			}
			$before = FilesEffectState::tree($tree);
			$out = $gate->call($tool, $args);
			$t->record('shared-unconfirmed', $args, $out, FilesEffectState::tree($tree));
			$payload = json_decode($out['body']->result->content[0]->text ?? 'null', true);
			$this->assertTrue($payload['requiresConfirmation'] ?? false, json_encode($out));
			$this->assertEquals($before, FilesEffectState::tree($tree));
			$args['confirm_shared'] = true;
			$out = $gate->call($tool, $args);
			$t->record('shared-confirmed', $args, $out, FilesEffectState::tree($tree));
			$this->assertFalse($out['body']->result->isError ?? false, json_encode($out));
			$this->assertNotEquals($before, FilesEffectState::tree($tree));
			$t->assertGolden();
		}
	}
}
