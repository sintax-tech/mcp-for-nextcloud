<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Exclusion;

use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCA\Mcp\Tests\Integration\Support\ToolReply;
use OCP\Files\File;

/**
 * V7 (real): writes with an excluded source or destination are refused before any effect, read back from the real
 * Files tree; and the D7 gate for folders runs against the real share manager, the real copy and the real move.
 */
final class WritesTest extends ExclusionTestCase {
	private const D7 = 'Folders cannot be shared, copied or moved to another directory';

	private function layout(): McpClient {
		$this->makeFile('home', 'locked/inside.txt', 'inside the excluded folder');
		$this->makeFile('home', 'open/o.txt', 'open file');
		$this->makeFile('home', 'open/p.txt', 'another open file');
		$this->tag($this->node(self::ALICE, $this->path('home', 'locked')));
		$this->exclude();
		return $this->mcp();
	}

	private function assertNoEffect(string $relative): void {
		$this->assertFalse($this->exists(self::ALICE, $this->path('home', $relative)), "$relative must not exist after the refusal");
	}

	private function assertContent(string $relative, string $expected): void {
		$node = $this->node(self::ALICE, $this->path('home', $relative));
		$this->assertInstanceOf(File::class, $node);
		$this->assertSame($expected, $node->getContent(), "$relative must be unchanged");
	}

	public function testCreateMkdirAndUploadIntoAnExcludedFolderAreRefusedWithoutEffect(): void {
		$alice = $this->layout();
		$locked = fn (string ...$p): string => $this->path('home', 'locked', ...$p);
		$cases = [
			'files_create' => ['path' => $locked('new.txt'), 'content' => 'x'],
			'files_mkdir' => ['path' => $locked('newdir')],
			'files_mkdir (intermediate folders)' => ['path' => $locked('a', 'b', 'c')],
			'files_upload' => ['path' => $locked('up.bin'), 'size' => 5],
		];
		foreach ($cases as $label => $arguments) {
			$tool = explode(' ', $label)[0];
			// The same tool, planned for a destination folder that does not exist: its refusal may carry a hint of
			// its own (files_create says to create the folder first), and the excluded folder must read the same.
			$absent = ['path' => $this->path('home', 'no-such-folder', basename($arguments['path']))] + $arguments;
			$reference = $alice->tool($tool, $absent);
			foreach (['plan without confirm' => $alice->tool($tool, $arguments), 'confirmed' => $alice->write($tool, $arguments)] as $step => $reply) {
				$this->assertRefusedAsNotFound($reply, "$label, $step");
				if ($reference->isError()) {
					$this->assertSame($reference->fingerprint(), $reply->fingerprint(), "$label, $step, answers like a destination that does not exist (status and whole result)");
				}
			}
		}
		foreach (['locked/new.txt', 'locked/newdir', 'locked/a', 'locked/a/b', 'locked/up.bin'] as $relative) {
			$this->assertNoEffect($relative);
		}
	}

	public function testCopyAndMoveWithAnExcludedSourceOrDestinationAreRefusedWithoutEffect(): void {
		$alice = $this->layout();
		$open = fn (string $n): string => $this->path('home', 'open', $n);
		$locked = fn (string $n): string => $this->path('home', 'locked', $n);
		$cases = [
			'copy into the excluded folder' => ['files_copy', ['from' => $open('o.txt'), 'to' => $locked('c.txt')]],
			'copy out of the excluded folder' => ['files_copy', ['from' => $locked('inside.txt'), 'to' => $open('c2.txt')]],
			'move into the excluded folder' => ['files_move', ['from' => $open('o.txt'), 'to' => $locked('m.txt')]],
			'move out of the excluded folder' => ['files_move', ['from' => $locked('inside.txt'), 'to' => $open('m2.txt')]],
		];
		foreach ($cases as $label => [$tool, $arguments]) {
			$this->assertRefusedAsMissing($alice, $alice->tool($tool, $arguments), "$label, plan without confirm");
			$this->assertRefusedAsMissing($alice, $alice->write($tool, $arguments), "$label, confirmed");
		}
		foreach (['locked/c.txt', 'locked/m.txt', 'open/c2.txt', 'open/m2.txt'] as $relative) {
			$this->assertNoEffect($relative);
		}
		$this->assertContent('open/o.txt', 'open file');
		$this->assertContent('locked/inside.txt', 'inside the excluded folder');
	}

	public function testABatchWithAnExcludedDestinationIsRefusedWholeAndMovesNothing(): void {
		$alice = $this->layout();
		$moves = [
			['from' => $this->path('home', 'open', 'p.txt'), 'to' => $this->path('home', 'open', 'p2.txt')],
			['from' => $this->path('home', 'open', 'o.txt'), 'to' => $this->path('home', 'locked', 'b.txt')],
		];
		$plan = $alice->tool('files_move_batch', ['moves' => $moves]);
		$this->assertStringContainsString('Not allowed by Nextcloud', $plan->text(), 'the plan lists the item as not allowed: ' . $plan->describe());
		$this->assertStringContainsString('Resource not found in Nextcloud.', $plan->text(), 'with the generic reason: ' . $plan->describe());
		$confirmed = $alice->write('files_move_batch', ['moves' => $moves]);
		$this->assertTrue($confirmed->isError(), 'the mixed batch is refused when confirmed: ' . $confirmed->describe());
		$inFolder = $alice->write('files_move_batch', ['moves' => [['from' => $this->path('home', 'open', 'o.txt'), 'to' => $this->path('home', 'open', 'o2.txt')]],
			'mkdirs' => [$this->path('home', 'locked', 'made')]]);
		$this->assertTrue($inFolder->isError(), 'a folder to create inside the excluded folder is refused: ' . $inFolder->describe());
		foreach (['open/p2.txt', 'open/o2.txt', 'locked/b.txt', 'locked/made'] as $relative) {
			$this->assertNoEffect($relative);
		}
		$this->assertContent('open/p.txt', 'another open file');
		$this->assertContent('open/o.txt', 'open file');
	}

	public function testUndoNeitherMovesNorDeletesAnItemThatBecameExcluded(): void {
		$this->makeFile('home', 'open/u.txt', 'to undo');
		$this->exclude();
		$alice = $this->mcp();
		$batch = $alice->write('files_move_batch', ['moves' => [['from' => $this->path('home', 'open', 'u.txt'), 'to' => $this->path('home', 'moved', 'u.txt')]],
			'mkdirs' => [$this->path('home', 'moved')]]);
		$this->assertTrue($batch->ok(), 'the allowed batch runs: ' . $batch->describe());
		$batchId = ($batch->json()['batch_id'] ?? null);
		$this->assertIsInt($batchId, 'the batch gives its id: ' . $batch->describe());

		$this->tag($this->node(self::ALICE, $this->path('home', 'moved')));
		foreach ([$alice->tool('files_undo_batch', ['batch_id' => $batchId]), $alice->write('files_undo_batch', ['batch_id' => $batchId])] as $reply) {
			$this->assertTrue($reply->isError(), 'the undo of an excluded item is refused: ' . $reply->describe());
		}
		$this->assertContent('moved/u.txt', 'to undo');
		$this->assertNoEffect('open/u.txt');
	}

	public function testRestoringAVersionOfAnExcludedFileIsRefusedAndLeavesItUntouched(): void {
		$file = $this->makeFile('home', 'open/v.txt', 'first');
		$file->putContent('second');
		$file->putContent('third');
		$alice = $this->mcp();
		$versions = $alice->tool('files_versions_list', ['path' => $this->path('home', 'open', 'v.txt')]);
		$this->assertTrue($versions->ok(), 'files_versions_list: ' . $versions->describe());
		$revision = $versions->json()['versions'][0]['revision'] ?? null;
		$this->assertIsString($revision, 'the file has a version to restore: ' . $versions->describe());

		$this->tag($this->node(self::ALICE, $this->path('home', 'open', 'v.txt')));
		$this->exclude();
		$arguments = ['path' => $this->path('home', 'open', 'v.txt'), 'version' => $revision];
		$this->assertRefusedAsMissing($alice, $alice->tool('files_version_restore', $arguments), 'restore, plan without confirm');
		$this->assertRefusedAsMissing($alice, $alice->write('files_version_restore', $arguments), 'restore, confirmed');
		$this->assertRefusedAsMissing($alice, $alice->tool('files_versions_list', ['path' => $this->path('home', 'open', 'v.txt')]), 'versions list');
		$this->assertContent('open/v.txt', 'third');
	}

	public function testNotesCannotBeCreatedOrMovedInOrOutOfAnExcludedCategory(): void {
		$notes = $this->ensureFolder(self::ALICE, 'Notes');
		$this->later(fn () => $notes->nodeExists('Locked') ? $notes->get('Locked')->delete() : null);
		$this->later(fn () => $notes->nodeExists('ok.md') ? $notes->get('ok.md')->delete() : null);
		$this->later(fn () => $notes->nodeExists('control.md') ? $notes->get('control.md')->delete() : null);
		$ok = $notes->newFile('ok.md', 'a note');
		$locked = $notes->newFolder('Locked');
		$inside = $locked->newFile('secret.md', 'a secret note');
		$this->tag($locked);
		$this->exclude();
		$alice = $this->mcp();

		$create = ['title' => 'sneaky', 'content' => 'x', 'category' => 'Locked'];
		$this->assertTrue($alice->tool('notes_create', $create)->isError(), 'notes_create in the excluded category, plan, is refused');
		$this->assertTrue($alice->write('notes_create', $create)->isError(), 'notes_create in the excluded category is refused');
		$moveIn = ['id' => (int)$ok->getId(), 'category' => 'Locked'];
		$this->assertTrue($alice->write('notes_move', $moveIn)->isError(), 'notes_move into the excluded category is refused');
		$moveOut = ['id' => (int)$inside->getId(), 'category' => ''];
		$this->assertTrue($alice->write('notes_move', $moveOut)->isError(), 'notes_move out of the excluded category is refused');

		$this->assertSame(['secret.md'], array_map(static fn ($n) => $n->getName(), $locked->getDirectoryListing()), 'nothing was added to the category');
		$this->assertTrue($notes->nodeExists('ok.md'), 'the note stays where it was');
		$this->assertTrue($locked->nodeExists('secret.md'), 'the excluded note stays where it was');

		$control = $alice->write('notes_create', ['title' => 'control', 'content' => 'visible category']);
		$this->assertTrue($control->ok(), 'a note outside the excluded category is still created: ' . $control->describe());
	}

	// --- D7: folders ----------------------------------------------------------------------------------------------

	private function d7Layout(): McpClient {
		$this->makeFile('home', 'open/folder/inner.txt', 'inside a folder');
		$this->makeFile('home', 'open/file.txt', 'a file');
		$this->makeFile('home', 'locked/x.txt', 'excluded elsewhere');
		$this->tag($this->node(self::ALICE, $this->path('home', 'locked')));
		$this->exclude();
		return $this->mcp();
	}

	private function assertD7(ToolReply $reply, string $label): void {
		$this->assertTrue($reply->isError(), "$label must be refused: " . $reply->describe());
		$this->assertStringContainsString(self::D7, $reply->text(), $label);
	}

	public function testSharingAFolderIsRefusedWithTheD7MessageAndCreatesNothing(): void {
		$alice = $this->d7Layout();
		$folder = $this->path('home', 'open', 'folder');
		foreach (['user:' . self::BOB, 'link'] as $with) {
			$arguments = ['path' => $folder, 'with' => $with, 'permission' => 'view'];
			$this->assertD7($alice->tool('files_share', $arguments), "folder share with $with, plan");
			$this->assertD7($alice->write('files_share', $arguments + ['plan_state' => 'not-a-real-state']), "folder share with $with, confirmed");
		}
		$this->assertSame([], $this->userSharesOf($this->node(self::ALICE, $folder), self::ALICE), 'no share was created');
	}

	public function testSharingASingleFileStillWorksWhileAnExclusionIsConfigured(): void {
		$alice = $this->d7Layout();
		$arguments = ['path' => $this->path('home', 'open', 'file.txt'), 'with' => 'user:' . self::BOB, 'permission' => 'view'];
		$plan = $alice->tool('files_share', $arguments);
		$this->assertTrue($plan->ok(), 'the plan of a file share: ' . $plan->describe());
		$state = $plan->structured()['plan_state'] ?? null;
		$this->assertIsString($state, 'the plan carries its state: ' . $plan->describe());
		$done = $alice->write('files_share', $arguments + ['plan_state' => $state]);
		$this->assertTrue($done->ok(), 'the confirmed file share: ' . $done->describe());
		$this->assertCount(1, $this->userSharesOf($this->node(self::ALICE, $this->path('home', 'open', 'file.txt')), self::ALICE));
		$this->assertReadable($this->mcp(self::BOB), '/file.txt', 'a file', 'the shared file, read by bob');
	}

	public function testCopyingAFolderIsRefusedAndCopyingAFileStillWorks(): void {
		$alice = $this->d7Layout();
		$folder = ['from' => $this->path('home', 'open', 'folder'), 'to' => $this->path('home', 'open', 'folder-copy')];
		$this->assertD7($alice->tool('files_copy', $folder), 'folder copy, plan');
		$this->assertD7($alice->write('files_copy', $folder), 'folder copy, confirmed');
		$this->assertNoEffect('open/folder-copy');

		$file = $alice->write('files_copy', ['from' => $this->path('home', 'open', 'file.txt'), 'to' => $this->path('home', 'open', 'file-copy.txt')]);
		$this->assertTrue($file->ok(), 'file copy: ' . $file->describe());
		$this->assertContent('open/file-copy.txt', 'a file');
	}

	public function testAnExclusionConfiguredBetweenThePlanAndTheConfirmationStopsTheCopy(): void {
		$this->makeFile('home', 'open/folder/inner.txt', 'inside a folder');
		$this->makeFile('home', 'locked/x.txt', 'excluded elsewhere');
		$this->tag($this->node(self::ALICE, $this->path('home', 'locked')));
		$alice = $this->mcp();
		$folder = ['from' => $this->path('home', 'open', 'folder'), 'to' => $this->path('home', 'open', 'folder-copy')];

		$plan = $alice->tool('files_copy', $folder);
		$this->assertTrue($plan->ok(), 'with no exclusion the folder copy plans: ' . $plan->describe());
		$this->exclude();
		$this->assertD7($alice->write('files_copy', $folder), 'the confirmation after the exclusion was configured');
		$this->assertNoEffect('open/folder-copy');
	}

	public function testMovingAFolderToAnotherDirectoryIsRefusedButRenamingItInPlaceIsNot(): void {
		$alice = $this->d7Layout();
		$this->makeFolder('home', 'elsewhere');
		$from = $this->path('home', 'open', 'folder');

		$away = ['from' => $from, 'to' => $this->path('home', 'elsewhere', 'folder')];
		$this->assertD7($alice->tool('files_move', $away), 'folder move to another directory, plan');
		$this->assertD7($alice->write('files_move', $away), 'folder move to another directory, confirmed');
		$this->assertNoEffect('elsewhere/folder');

		$batch = ['moves' => [['from' => $from, 'to' => $this->path('home', 'elsewhere', 'folder')],
			['from' => $this->path('home', 'open', 'file.txt'), 'to' => $this->path('home', 'elsewhere', 'file.txt')]]];
		$this->assertD7($alice->tool('files_move_batch', $batch), 'batch with a folder changing directory, plan');
		$this->assertD7($alice->write('files_move_batch', $batch), 'batch with a folder changing directory, confirmed');
		$this->assertNoEffect('elsewhere/file.txt');

		$rename = $alice->write('files_move', ['from' => $from, 'to' => $this->path('home', 'open', 'folder-renamed')]);
		$this->assertTrue($rename->ok(), 'renaming in the same parent is allowed: ' . $rename->describe());
		$this->assertContent('open/folder-renamed/inner.txt', 'inside a folder');

		$file = $alice->write('files_move', ['from' => $this->path('home', 'open', 'file.txt'), 'to' => $this->path('home', 'elsewhere', 'file.txt')]);
		$this->assertTrue($file->ok(), 'a single file moves to another directory under the normal checks: ' . $file->describe());
	}

	public function testListingSharesHidesTheLinkOfAFolderButNotOfAFileAndRevokesNothing(): void {
		$this->makeFile('home', 'open/folder/inner.txt', 'inside a folder');
		$this->makeFile('home', 'open/file.txt', 'a file');
		$this->makeFile('home', 'locked/x.txt', 'excluded elsewhere');
		$folderLink = $this->linkTo($this->node(self::ALICE, $this->path('home', 'open', 'folder')), self::ALICE);
		$fileLink = $this->linkTo($this->node(self::ALICE, $this->path('home', 'open', 'file.txt')), self::ALICE);
		$this->tag($this->node(self::ALICE, $this->path('home', 'locked')));
		$this->exclude();
		$alice = $this->mcp();

		$folder = $alice->tool('files_list_shares', ['path' => $this->path('home', 'open', 'folder')]);
		$this->assertTrue($folder->ok(), 'files_list_shares of the folder: ' . $folder->describe());
		$item = $folder->json()['shares'][0] ?? [];
		$this->assertArrayHasKey('url', $item, 'the link item carries the url key');
		$this->assertNull($item['url'], 'with an exclusion configured the folder link has no url');
		$this->assertStringNotContainsString($folderLink->getToken(), $folder->raw, 'and its token appears nowhere in the answer');

		$file = $alice->tool('files_list_shares', ['path' => $this->path('home', 'open', 'file.txt')]);
		$this->assertTrue($file->ok(), 'files_list_shares of the file: ' . $file->describe());
		$this->assertStringContainsString($fileLink->getToken(), (string)($file->json()['shares'][0]['url'] ?? ''), 'the file link keeps its url');

		$manager = \OCP\Server::get(\OCP\Share\IManager::class);
		$this->assertSame($folderLink->getId(), $manager->getShareById($folderLink->getFullId())->getId(), 'nothing was revoked');
	}

	/**
	 * Folders bob received as shares: moving one into another received share is refused without effect (by core across
	 * two share storages, or by the D7 gate); renaming it where it is stays allowed.
	 */
	public function testAReceivedFolderCannotBeMovedIntoAnotherReceivedShare(): void {
		$p = 'P-' . $this->run;
		$s = 'S-' . $this->run;
		$this->makeFile('home', $p . '/p.txt', 'in P');
		$this->makeFile('home', $s . '/s.txt', 'in S');
		$this->makeFile('home', 'locked/x.txt', 'excluded elsewhere');
		$this->shareToUser($this->node(self::ALICE, $this->path('home', $p)), self::ALICE, self::BOB, true);
		$this->shareToUser($this->node(self::ALICE, $this->path('home', $s)), self::ALICE, self::BOB, true);
		$this->tag($this->node(self::ALICE, $this->path('home', 'locked')));
		$this->exclude();
		$bob = $this->mcp(self::BOB);
		$this->assertContains($s, $this->listNames($bob, '/') ?? [], 'bob received S');
		$this->assertContains($p, $this->listNames($bob, '/') ?? [], 'bob received P');

		$into = ['from' => '/' . $s, 'to' => '/' . $p . '/' . $s];
		// Refused before any effect. Core refuses a move across two share storages on its own, and the D7 gate also
		// stands in front of the move; whichever answers first, the move must not happen. The answer is recorded.
		foreach (['plan' => $bob->tool('files_move', $into), 'confirmed' => $bob->write('files_move', $into)] as $step => $reply) {
			$this->assertTrue($reply->isError(), "moving a received share into another one, $step, must be refused: " . $reply->describe());
			$this->observe("received folder moved into another received share, $step -> " . substr($reply->text(), 0, 160));
		}
		$this->assertFalse($this->exists(self::ALICE, $this->path('home', $p, $s)), 'nothing was created in the shared P');
		$this->assertContains($s, $this->listNames($bob, '/') ?? [], 'S is still where it was received');

		// Core does not rename the mount point of a received share through a move (it answers that it would cross
		// storages); that is what happens without any exclusion too, so it is recorded, not asserted.
		$renameShare = $bob->write('files_move', ['from' => '/' . $s, 'to' => '/' . $s . '-renamed']);
		$this->observe('renaming the mount point of a received share through files_move -> ' . ($renameShare->ok() ? 'allowed' : substr($renameShare->text(), 0, 160)));

		// A folder of bob's own is renamed in place, with the exclusion configured, as the gate allows.
		$own = 'own-' . $this->run;
		$this->later(fn () => $this->deleteQuietly(self::BOB, $own));
		$this->later(fn () => $this->deleteQuietly(self::BOB, $own . '-renamed'));
		$this->dav(self::BOB)->mkcol('/' . $own);
		$this->dav(self::BOB)->put('/' . $own . '/o.txt', 'own');
		$rename = $bob->write('files_move', ['from' => '/' . $own, 'to' => '/' . $own . '-renamed']);
		$this->assertTrue($rename->ok(), "renaming bob's own folder in place stays allowed: " . $rename->describe());
		$this->assertContains($own . '-renamed', $this->listNames($bob, '/') ?? [], 'the renamed folder is listed');
	}

	public function testWithoutAnyExclusionFoldersBehaveAsBefore(): void {
		$this->makeFile('home', 'open/folder/inner.txt', 'inside a folder');
		$alice = $this->mcp();
		$copy = $alice->write('files_copy', ['from' => $this->path('home', 'open', 'folder'), 'to' => $this->path('home', 'open', 'folder-copy')]);
		$this->assertTrue($copy->ok(), 'folder copy without exclusions: ' . $copy->describe());
		$this->assertContent('open/folder-copy/inner.txt', 'inside a folder');
	}
}
