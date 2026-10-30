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
use OCA\Mcp\Tools\Files\Deletion\DeleteMessages;
use OCA\Mcp\Tools\Files\Deletion\Folder\DeleteFolderMessages;
use PHPUnit\Framework\TestCase;

/**
 * Effect contract of files_delete (RE-01, grant files.delete): a file and a folder of alice's Local home go to the
 * trash through the native delete, and the receipt never claims more than was observed (`removed_destination_unconfirmed`,
 * `recoveryConfirmed: false`). The tree is {@see TrashHomeTree}, whose native delete files a trash entry; the guards are
 * the real FilesAccessGuard and the real deletion backend gate ({@see GuardedFilesWiring}).
 *
 * The flows name their target through a closure: once the confirmed step consumed the first target, the refusal steps
 * that follow (no grant, guest, hidden) are asked against a sibling that still exists, so each of them refuses a
 * confirmation that would really delete something.
 */
final class FilesDeleteEffectsContractTest extends TestCase {
	/** alice's Local home, its trash and the native delete calls */
	private TrashHomeTree $tree;
	/** the endpoint */
	private EffectsGate $gate;
	/** the Files module of each request and its switches */
	private GuardedFilesWiring $wiring;

	/** @return void the endpoint serving the Files module over alice's Local home */
	protected function setUp(): void {
		$this->tree = new TrashHomeTree($this);
		$this->gate = new EffectsGate($this);
	}

	/** @return void files_delete of a file: plan, strict confirm, trash entry and receipt, and every refusal */
	public function testDeleteOfAFileGoesToTheTrash(): void {
		$targets = $this->files(['/Documentos/rascunho.txt', '/Documentos/copia.txt', '/Documentos/resto.txt']);
		$this->tree->addFile(TrashHomeTree::ROOT . '/Documentos/fica.txt', 'fica');
		$this->serve(true);
		$flow = (new CriticalFlow('delete-file', 'files_delete', 'files', 'delete', fn (): array => ['path' => $this->first($targets)],
			fn (): array => $this->state()))
			->confirmingWith(fn (mixed $plan, array $arguments): array => $this->echoing($plan, $arguments))
			->withoutPlanChanged('files_delete carries no plan_state: the client echoes the id and ETag of the plan, and a '
				. 'target changed in between is refused as a conflict, never answered with a new plan (testStaleConfirmationDeletesNothing)')
			->hiding(fn () => $this->wiring->hidden = [$this->idOf($this->first($targets))], fn () => $this->wiring->hidden = []);
		(new FlowRunner($this, $this->gate, 'files'))->run($flow);
		self::assertSame('/Documentos/rascunho.txt', $this->tree->trash[0]['path'] ?? null, 'the first target went to the trash');
		self::assertSame(['delete /alice/files/Documentos/rascunho.txt', 'delete /alice/files/Documentos/copia.txt'], $this->tree->calls,
			'the confirmed call and the confirmation of the guest, whom Guests always allows Files, are the only native deletes');
		self::assertArrayHasKey('/Documentos/resto.txt', $this->tree->view(), 'the hidden target stays');
	}

	/** @return void files_delete of a folder with its contents: plan, strict confirm, one trash entry, every refusal */
	public function testDeleteOfAFolderGoesToTheTrash(): void {
		$targets = ['/Projetos/antigo', '/Projetos/arquivo', '/Projetos/resto'];
		foreach ($targets as $folder) {
			$this->tree->addFile(TrashHomeTree::ROOT . $folder . '/notas.txt', 'notas');
			$this->tree->addFile(TrashHomeTree::ROOT . $folder . '/sub/plano.txt', 'plano');
		}
		// Folders are refused while an exclusion tag is configured: the folder flow starts without one.
		$this->serve(false);
		$flow = (new CriticalFlow('delete-folder', 'files_delete', 'files', 'delete', fn (): array => ['path' => $this->first($targets)],
			fn (): array => $this->state()))
			->confirmingWith(fn (mixed $plan, array $arguments): array => $this->echoing($plan, $arguments))
			->withoutPlanChanged('files_delete carries no plan_state: the client echoes the id and ETag of the plan, and a '
				. 'folder whose tree changed in between is refused as a conflict, never answered with a new plan')
			// The administrator tags the folder to hide it: with an exclusion configured no folder can be deleted at all.
			->hiding(function () use ($targets): void {
				$this->wiring->exclude(true);
				$this->wiring->hidden = [$this->idOf($this->first($targets))];
			}, function (): void {
				$this->wiring->exclude(false);
				$this->wiring->hidden = [];
			});
		(new FlowRunner($this, $this->gate, 'files'))->run($flow);
		self::assertSame(['id' => $this->tree->trash[0]['id'] ?? 0, 'path' => '/Projetos/antigo', 'type' => 'dir', 'nodes' => 4],
			$this->tree->trash[0] ?? null, 'the folder went to the trash as one entry with its whole subtree');
		self::assertArrayHasKey('/Projetos/resto/sub/plano.txt', $this->tree->view(), 'the hidden folder keeps its contents');
	}

	/** @return void the receipts of a confirmed file and folder deletion say what was observed, and no more */
	public function testReceiptsNeverClaimARecovery(): void {
		$file = $this->tree->addFile(TrashHomeTree::ROOT . '/a.txt', 'a');
		$folder = $this->tree->addFolder(TrashHomeTree::ROOT . '/pasta');
		$this->tree->addFile(TrashHomeTree::ROOT . '/pasta/b.txt', 'b');
		$this->serve(false);
		foreach (['/a.txt' => [$file, DeleteMessages::removed()], '/pasta' => [$folder, DeleteFolderMessages::removed()]] as $path => [$id, $message]) {
			$receipt = $this->confirm($path);
			self::assertFalse($receipt->result->isError ?? false, $path . ': ' . json_encode($receipt));
			$data = json_decode($receipt->result->content[0]->text, true, 512, JSON_THROW_ON_ERROR);
			self::assertSame(['status' => 'removed_destination_unconfirmed', 'path' => $path, 'idBefore' => $id, 'recoveryConfirmed' => false],
				array_diff_key($data, ['message' => true]), $path);
			self::assertSame($message . ' ' . DeleteMessages::risk(), $data['message'], $path);
		}
		self::assertSame(['delete /alice/files/a.txt', 'delete /alice/files/pasta'], $this->tree->calls);
		self::assertSame([[$file, '/a.txt'], [$folder, '/pasta']], array_map(static fn (array $entry): array => [$entry['id'], $entry['path']],
			$this->tree->trash));
	}

	/** @return void a file changed after its plan, or on a storage without the trash bin, is never deleted */
	public function testStaleConfirmationAndStorageWithoutTrashDeleteNothing(): void {
		$this->tree->addFile(TrashHomeTree::ROOT . '/a.txt', 'a');
		$this->tree->addFile(TrashHomeTree::ROOT . '/sem-lixeira/b.txt', 'b', false);
		$this->serve(true);
		$plan = $this->gate->call('files_delete', ['path' => '/a.txt'])['body'];
		$this->tree->touch(TrashHomeTree::ROOT . '/a.txt');
		$stale = $this->gate->call('files_delete', $this->echoing($plan, ['path' => '/a.txt']))['body'];
		self::assertTrue($stale->result->isError ?? false, json_encode($stale));
		$noTrashPlan = $this->gate->call('files_delete', ['path' => '/sem-lixeira/b.txt'])['body'];
		$forged = ['path' => '/sem-lixeira/b.txt', 'confirm' => true, 'id' => $this->idOf('/sem-lixeira/b.txt'), 'etag' => 'e' . $this->idOf('/sem-lixeira/b.txt')];
		$noTrash = $this->gate->call('files_delete', $forged)['body'];
		foreach ([$noTrashPlan, $noTrash] as $answer) {
			self::assertTrue($answer->result->isError ?? false, json_encode($answer));
			self::assertStringContainsString(DeleteMessages::unavailable(), $answer->result->content[0]->text);
		}
		self::assertSame([], $this->tree->calls, 'nothing reached the native delete');
		self::assertSame([], $this->tree->trash);
	}

	/**
	 * @param bool $excluding whether the exclusion tag is configured
	 * @return void serves the Files module over the tree
	 */
	private function serve(bool $excluding): void {
		$this->wiring = new GuardedFilesWiring($this, $this->gate, fn () => $this->tree->rootFolder(), fn () => $this->tree->filesystemRoot(),
			$this->tree->shareManager(), $excluding);
		$this->gate->serve(fn (): array => [$this->wiring->module()]);
	}

	/**
	 * @param list<string> $paths files to create, relative to alice's root
	 * @return list<string> the same paths
	 */
	private function files(array $paths): array {
		foreach ($paths as $path) {
			$this->tree->addFile(TrashHomeTree::ROOT . $path, 'conteúdo de ' . basename($path));
		}
		return $paths;
	}

	/**
	 * @param list<string> $paths candidate targets, relative to alice's root
	 * @return string the first that still exists
	 */
	private function first(array $paths): string {
		foreach ($paths as $path) {
			if (isset($this->tree->nodes[TrashHomeTree::ROOT . $path])) {
				return $path;
			}
		}
		return $paths[array_key_last($paths)];
	}

	/**
	 * @param string $path relative to alice's root
	 * @return int the id of the node there, 0 when it is gone
	 */
	private function idOf(string $path): int {
		return $this->tree->nodes[TrashHomeTree::ROOT . $path]['id'] ?? 0;
	}

	/**
	 * @param mixed $plan JSON-RPC body of the plan
	 * @param array<string, mixed> $arguments arguments of the plan
	 * @return array<string, mixed> the confirmation the tool description asks for: confirm, and the id and ETag of the plan
	 */
	private function echoing(mixed $plan, array $arguments): array {
		$shown = $plan->result->structuredContent ?? null;
		return $arguments + ['confirm' => true] + (isset($shown->id, $shown->etag) ? ['id' => $shown->id, 'etag' => $shown->etag] : []);
	}

	/**
	 * @param string $path relative to alice's root
	 * @return mixed the JSON-RPC body of a plan confirmed at once
	 */
	private function confirm(string $path): mixed {
		$plan = $this->gate->call('files_delete', ['path' => $path])['body'];
		return $this->gate->call('files_delete', $this->echoing($plan, ['path' => $path]))['body'];
	}

	/** @return array{nodes: array<string, int>, trash: list<array<string, mixed>>, calls: list<string>} tree, trash and native calls */
	private function state(): array {
		return ['nodes' => $this->tree->view(), 'trash' => $this->tree->trash, 'calls' => $this->tree->calls];
	}
}
