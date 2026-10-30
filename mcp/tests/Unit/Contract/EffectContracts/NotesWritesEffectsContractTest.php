<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Service\VisibilityGuard;
use OCA\Mcp\Tests\Unit\Contract\Oracle\CriticalFlow;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\FlowRunner;
use OCA\Mcp\Tests\Unit\Contract\Oracle\GoldenFile;
use OCA\Mcp\Tests\Unit\Contract\Oracle\VolatileFields;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tools\Notes\NotesMessages;
use PHPUnit\Framework\TestCase;

/**
 * Effect contract of the writing Notes tools besides the partial edit (RE-01 round 2): the creation, the move and the
 * deletion of a note, the refusal of a deletion the trash bin would not take back, and a creation in a notes folder
 * shared with a team, which needs confirm_shared. The effect is the fake file tree (contents, folders and write log);
 * the real VisibilityGuard hides a target behind a system tag.
 *
 * None of these tools carries a plan fingerprint: notes_create, notes_move and notes_delete have no PlanState and
 * never throw PlanChanged (only the partial edit does, frozen in {@see NotesEffectsContractTest}).
 */
final class NotesWritesEffectsContractTest extends TestCase {
	/** The notes folder of alice. */
	private const NOTES = '/alice/files/Notes';
	/** Why no step of these flows can answer PlanChanged. */
	private const NO_FINGERPRINT = 'notes_create, notes_move and notes_delete keep no plan fingerprint (no PlanState, '
		. 'no PlanChanged): the confirmed call is checked again from its arguments. A note that changed since the plan is '
		. 'refused by the ETag the call carries (a conflict, without a new plan), asserted outside the golden.';
	/** Why a creation has no PlanChanged step. */
	private const NO_FINGERPRINT_CREATE = 'A creation has no existing note to change between the plan and the '
		. 'confirmation, and ' . self::NO_FINGERPRINT;

	private FakeTree $tree;
	private EffectsGate $gate;
	/** @var list<int> node ids carrying the hidden tag */
	private array $hidden = [];

	/** @return void builds the tree, the endpoint and the module of every request */
	protected function setUp(): void {
		$this->tree = new FakeTree($this);
		$this->tree->addFile(self::NOTES . '/Ata.md', "decisões\n", 'text/markdown');
		$this->tree->addFile(self::NOTES . '/Pauta.md', "itens\n", 'text/markdown');
		$this->tree->addFolder(self::NOTES . '/Atas');
		$this->gate = new EffectsGate($this);
		$this->gate->data()->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode([NotesWritesWiring::HIDDEN_TAG]);
		$this->gate->serve(fn (): array => [NotesWritesWiring::module($this, $this->tree, $this->gate, fn (): array => $this->hidden)]);
	}

	/** @return void notes_create in an existing category: plan, strict confirm, the new file and every refusal */
	public function testCreationOfANote(): void {
		$category = $this->tree->nodes[self::NOTES . '/Atas']['id'];
		$flow = (new CriticalFlow('create', 'notes_create', 'notes', 'create',
			['title' => 'Plano', 'content' => "metas do trimestre\n", 'category' => 'Atas'], fn (): array => $this->state()))
			->withoutPlanChanged(self::NO_FINGERPRINT_CREATE)
			->hiding(fn () => $this->hide($category), fn () => $this->hide());
		(new FlowRunner($this, $this->gate, 'notes'))->run($flow);
		self::assertSame("metas do trimestre\n", $this->tree->nodes[self::NOTES . '/Atas/Plano.md']['content'] ?? null);
		self::assertSame(['create ' . self::NOTES . '/Atas/Plano.md'], $this->tree->ops, 'one note created, nothing else');
	}

	/** @return void notes_move into a new category: plan, strict confirm, the move and every refusal */
	public function testMoveOfANote(): void {
		$id = $this->tree->nodes[self::NOTES . '/Ata.md']['id'];
		// Each plan moves the note to the other place, so every refusal is asked with a move that would really happen.
		$flow = (new CriticalFlow('move', 'notes_move', 'notes', 'move', fn (): array => ['id' => $id,
			'category' => str_contains($this->pathOf($id), '/Arquivo/') ? '' : 'Arquivo', 'etag' => $this->etagOf($id)],
			fn (): array => $this->state()))
			->withoutPlanChanged(self::NO_FINGERPRINT)
			->hiding(fn () => $this->hide($id), fn () => $this->hide());
		(new FlowRunner($this, $this->gate, 'notes'))->run($flow);
		self::assertSame(self::NOTES . '/Arquivo/Ata.md', $this->pathOf($id), 'the confirmed move must land in the category');
		self::assertSame(['mkdir ' . self::NOTES . '/Arquivo', 'move ' . self::NOTES . '/Ata.md ' . self::NOTES . '/Arquivo/Ata.md'],
			$this->tree->ops, 'the category is created and the note moved, nothing else');
	}

	/** @return void notes_delete of a note on a storage with a trash bin: plan, strict confirm, the deletion, refusals */
	public function testDeletionOfANoteGoesToTheTrash(): void {
		// The first note still there: after the confirmed deletion every refusal targets the other one.
		$target = fn (): int => $this->tree->nodes[isset($this->tree->nodes[self::NOTES . '/Ata.md'])
			? self::NOTES . '/Ata.md' : self::NOTES . '/Pauta.md']['id'];
		$flow = (new CriticalFlow('delete', 'notes_delete', 'notes', 'delete',
			fn (): array => ['id' => $target(), 'etag' => $this->etagOf($target())], fn (): array => $this->state()))
			->withoutPlanChanged(self::NO_FINGERPRINT)
			->hiding(fn () => $this->hide($target()), fn () => $this->hide());
		(new FlowRunner($this, $this->gate, 'notes'))->run($flow);
		self::assertArrayNotHasKey(self::NOTES . '/Ata.md', $this->tree->nodes);
		self::assertArrayHasKey(self::NOTES . '/Pauta.md', $this->tree->nodes, 'only the confirmed note is deleted');
		self::assertSame(['delete ' . self::NOTES . '/Ata.md'], $this->tree->ops, 'one native delete, of the confirmed note');
		// The receipt of a confirmed deletion, read directly: deleted, into the trash bin, after a native delete.
		$pauta = $target();
		$body = $this->gate->call('notes_delete', ['id' => $pauta, 'etag' => $this->etagOf($pauta), 'confirm' => true])['body'];
		self::assertSame(['id' => $pauta, 'deleted' => true, 'trash' => true],
			json_decode($body->result->content[0]->text ?? 'null', true), json_encode($body));
		self::assertSame('delete ' . self::NOTES . '/Pauta.md', $this->tree->ops[1] ?? null, 'the receipt follows a native delete');
	}

	/** @return void a note whose storage has no trash bin is refused on the plan and on a direct confirmation */
	public function testDeletionWithoutTrashBinIsRefused(): void {
		$id = $this->tree->addFile(self::NOTES . '/Externa.md', "fora\n", 'text/markdown', ['trash' => false]);
		$before = $this->state();
		$steps = [];
		foreach (['preview' => ['id' => $id], 'confirmed' => ['id' => $id, 'confirm' => true]] as $name => $arguments) {
			$response = $this->gate->call('notes_delete', $arguments);
			self::assertTrue($response['body']->result->isError ?? false, $name . ' must be refused: ' . json_encode($response['body']));
			self::assertSame(NotesMessages::notRecoverable(), $response['body']->result->content[0]->text ?? null);
			self::assertEquals($before, $this->state(), $name . ' must delete nothing');
			$steps[] = ['step' => $name, 'arguments' => $arguments, 'response' => $response, 'state' => $this->state()];
		}
		GoldenFile::assertMatches($this, __DIR__ . '/../effects/notes.delete-without-trash.json',
			VolatileFields::normalize(['tool' => 'notes_delete', 'grant' => 'notes.delete', 'steps' => $steps]));
	}

	/** @return void a move or a deletion confirmed with the ETag of an older version of the note is refused, no write */
	public function testWriteWithAStaleEtagIsRefused(): void {
		$id = $this->tree->nodes[self::NOTES . '/Ata.md']['id'];
		foreach (['notes_move' => ['category' => 'Arquivo'], 'notes_delete' => []] as $tool => $fields) {
			$stale = ['id' => $id, 'etag' => $this->etagOf($id), 'confirm' => true] + $fields;
			// Another client saves the note after the plan.
			$this->tree->nodes[self::NOTES . '/Ata.md']['etag'] = 'outra-versao-' . $tool;
			$before = $this->state();
			$body = $this->gate->call($tool, $stale)['body'];
			self::assertTrue($body->result->isError ?? false, $tool . ': a stale ETag must be refused: ' . json_encode($body));
			self::assertEquals($before, $this->state(), $tool . ': a stale ETag must write nothing');
		}
	}

	/**
	 * notes_create in a notes folder alice shares with her team (a team folder): the confirmed call carries
	 * confirm_shared and creates the note; without confirm_shared the same confirmation creates nothing and asks for it
	 * (non-error). A folder shared by another account (ISharedStorage) is not used here: the real VisibilityGuard then
	 * resolves the owner's tree through the root folder, which the fake tree does not model, and refuses the node.
	 *
	 * @return void
	 */
	public function testCreationInASharedNotesFolderNeedsConfirmShared(): void {
		$this->gate->data()->user[EffectsGate::USER]['notes']['notesPath'] = 'Equipe';
		$folder = $this->tree->addFolder('/alice/files/Equipe', ['scope' => 'team']);
		$this->tree->mountPath = '/alice/files/Equipe';
		$arguments = ['title' => 'Combinado', 'content' => "prazo sexta\n"];
		$before = $this->state();
		$asked = $this->gate->call('notes_create', $arguments + ['confirm' => true])['body'];
		$payload = json_decode($asked->result->content[0]->text ?? 'null', true);
		self::assertFalse($asked->result->isError ?? false, 'the shared-write question is not an error: ' . json_encode($asked));
		self::assertTrue($payload['requiresConfirmation'] ?? false, 'without confirm_shared the write must ask: ' . json_encode($asked));
		self::assertSame('team', $payload['scope'] ?? null);
		self::assertSame('Equipe', $payload['teamFolder'] ?? null);
		self::assertEquals($before, $this->state(), 'without confirm_shared nothing is created');
		$flow = (new CriticalFlow('create-shared-folder', 'notes_create', 'notes', 'create',
			$arguments + ['confirm_shared' => true], fn (): array => $this->state()))
			->withoutPlanChanged(self::NO_FINGERPRINT_CREATE)
			->hiding(fn () => $this->hide($folder), fn () => $this->hide());
		(new FlowRunner($this, $this->gate, 'notes'))->run($flow);
		self::assertSame("prazo sexta\n", $this->tree->nodes['/alice/files/Equipe/Combinado.md']['content'] ?? null);
		self::assertSame(['create /alice/files/Equipe/Combinado.md'], $this->tree->ops, 'one note created, nothing else');
	}

	/**
	 * @param int ...$ids node ids that carry the hidden tag from now on; none unhides everything
	 * @return void
	 */
	private function hide(int ...$ids): void {
		$this->hidden = array_values($ids);
	}

	/**
	 * @param int $id node id
	 * @return string its path in the tree now, '' when it is gone
	 */
	private function pathOf(int $id): string {
		foreach ($this->tree->nodes as $path => $node) {
			if ($node['id'] === $id) {
				return $path;
			}
		}
		return '';
	}

	/**
	 * @param int $id node id
	 * @return string its ETag now, '' when it is gone
	 */
	private function etagOf(int $id): string {
		return $this->tree->nodes[$this->pathOf($id)]['etag'] ?? '';
	}

	/** @return array{files: array<string, string>, folders: list<string>, ops: list<string>} contents, folders, write log */
	private function state(): array {
		$files = [];
		$folders = [];
		foreach ($this->tree->nodes as $path => $node) {
			if ($node['type'] === 'file') {
				$files[$path] = $node['content'];
			} else {
				$folders[] = $path;
			}
		}
		return ['files' => $files, 'folders' => $folders, 'ops' => $this->tree->ops];
	}
}
