<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Access\FileTypes\FileWritePolicy;
use OCA\Mcp\Access\FileTypes\FileTypeDenied;
use OCA\Mcp\Modules\Files\{Batch, CopyMoveContext, FileTypePreflight, ListedTreeCopy, ReorganizationLimits};
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;

/** Both T15-6 milestones: no effects on whole preflight denial, and exact listed-copy partial receipts. */
final class TreeBatchPolicyTest extends FilesToolsTestCase {
	/** Seed a dotted source tree with only txt children under an active global policy.
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		CopyMovePolicyFixture::forTest($this)->value = array_replace(FileWritePolicy::defaults(), ['mode' => 'allowlist', 'extensions' => ['txt']]);
		$this->tree->addFolder('/alice/files/src.php');
		$this->tree->addFolder('/alice/files/src.php/sub');
		$this->tree->addFile('/alice/files/src.php/a.txt', 'alpha', 'text/plain');
		$this->tree->addFile('/alice/files/src.php/sub/b.txt', 'beta', 'text/plain');
	}

	/** @return CopyMoveContext The very same real context and injected policy used by the module. */
	private function context(): CopyMoveContext {
		return FilesCatalogWiring::of($this->module)->named('reorganization')->copyMoveContext();
	}

	/** A forbidden child refuses both plans/confirmations, without creating any target or batch row.
	 * @return void
	 */
	public function testForbiddenDescendantDeniesBothToolsBeforeEffects(): void {
		$this->tree->addFile('/alice/files/src.php/sub/secret.php', 'secret', 'text/plain');
		foreach (['files_copy', 'files_move'] as $tool) {
			foreach ([false, true] as $plan) {
				try {
					$args = ['from' => '/src.php', 'to' => '/out.txt', 'confirm_shared' => true];
					$plan ? $this->plan($tool, $args) : $this->tool($tool, $args);
					self::fail('The whole tree must be refused');
				} catch (FileTypeDenied) {
					self::assertSame([], $this->tree->ops);
					self::assertSame([], $this->batches->rows);
					self::assertArrayNotHasKey('/alice/files/out.txt', $this->tree->nodes);
				}
			}
		}
	}

	/** A late child is excluded, a renamed listed source conflicts, and remaining entries retain their bytes.
	 * @return void
	 */
	public function testListedCopyExcludesLateChildrenAndReportsRenamedSource(): void {
		$context = $this->context();
		$root = $this->tree->rootFolder();
		$source = $root->get('src.php');
		$manifest = (new FileTypePreflight($context))->inspect($source, '/copy', $context->policy()->snapshot(), true);
		$this->tree->addFile('/alice/files/src.php/sub/late.php', 'intruder', 'text/plain');
		$root->get('src.php/a.txt')->move('/alice/files/src.php/a.php');
		$this->tree->ops = [];
		$result = (new ListedTreeCopy($context))->execute($root, 'alice', $source, '/copy', $manifest);
		self::assertTrue($result['partial']);
		self::assertNotEmpty($result['conflicts']);
		self::assertArrayNotHasKey('/alice/files/copy/sub/late.php', $this->tree->nodes);
		self::assertArrayNotHasKey('/alice/files/copy/a.php', $this->tree->nodes);
		self::assertArrayNotHasKey('/alice/files/copy/a.txt', $this->tree->nodes);
		self::assertContains('/copy/a.txt', $result['not_copied']);
		self::assertContains(['path' => '/copy/a.txt', 'reason' => \OCA\Mcp\Platform\FileAccess\CommonMessages::notFound()], $result['conflicts']);
		self::assertSame('alpha', $root->get('src.php/a.php')->getContent());
		self::assertSame('beta', $root->get('copy/sub/b.txt')->getContent());
		self::assertSame([], array_filter($this->tree->ops, static fn ($op) => str_starts_with($op, 'delete')));
	}

	/** A file-id replacement at the same listed path is never accepted as the listed source.
	 * @return void
	 */
	public function testListedCopyRejectsAReplacementIdentity(): void {
		$context = $this->context();
		$root = $this->tree->rootFolder();
		$source = $root->get('src.php');
		$manifest = (new FileTypePreflight($context))->inspect($source, '/copy', $context->policy()->snapshot(), true);
		$this->tree->nodes['/alice/files/src.php/a.txt']['id'] += 10000;
		$result = (new ListedTreeCopy($context))->execute($root, 'alice', $source, '/copy', $manifest);
		self::assertTrue($result['partial']);
		self::assertArrayNotHasKey('/alice/files/copy/a.txt', $this->tree->nodes);
	}

	/** Intermediates are checked even if the last name would be permitted, before any planned folder.
	 * @return void
	 */
	public function testIntermediateBatchNameRefusesBeforeMkdir(): void {
		$args = ['mkdirs' => ['/New'], 'moves' => [
			['from' => '/src.php/a.txt', 'to' => '/New/stage.php'],
			['from' => '/New/stage.php', 'to' => '/New/final.txt'],
		]];
		$plan = $this->plan('files_move_batch', $args);
		self::assertFalse($plan['ok']);
		self::assertContains(['from' => '/src.php/a.txt', 'to' => '/New/stage.php',
			'reason' => \OCA\Mcp\Access\FileTypes\FileTypeMessages::denied()], $plan['denied']);
		$this->failure('files_move_batch', $args);
		self::assertSame([], $this->tree->ops);
		self::assertArrayNotHasKey('/alice/files/New', $this->tree->nodes);
		self::assertSame([], $this->batches->rows);
	}

	/** D8 checks the inverse before recreating a missing recorded parent, and preserves the retry row.
	 * @return void
	 */
	public function testUndoTypeConflictPreservesMissingParentAndRecord(): void {
		$id = $this->batches->insert(new Batch(null, 'alice', 1790000000,
			[['from' => '/Gone/a.php', 'to' => '/src.php/a.txt', 'toId' => (int)$this->tree->node('/alice/files/src.php/a.txt')->getId()]], ['/Gone'], null));
		$before = $this->batches->rows;
		$plan = $this->plan('files_undo_batch', ['batch_id' => $id]);
		self::assertFalse($plan['ok']);
		$out = $this->json('files_undo_batch', ['batch_id' => $id]);
		self::assertSame(0, $out['undone']);
		self::assertNotEmpty($out['conflicts']);
		self::assertArrayNotHasKey('/alice/files/Gone', $this->tree->nodes);
		self::assertSame($before, $this->batches->rows);
		self::assertSame([], $this->tree->ops);
	}

	/** Off does not add a directory traversal; copy retains its existing native ceilings separately.
	 * @return void
	 */
	public function testOffDoesNotWalkForTypes(): void {
		CopyMovePolicyFixture::forTest($this)->value = FileWritePolicy::defaults();
		$folder = $this->createMock(Folder::class);
		$folder->expects(self::never())->method('getDirectoryListing');
		$context = $this->context();
		self::assertNull((new FileTypePreflight($context))->inspect($folder, '/copy', $context->policy()->snapshot(), true));
	}

	/** One policy read authorizes 2,000 projected names in a boundary; no per-child cache invalidations.
	 * @return void
	 */
	public function testTwoThousandNodesUseOnePolicyRead(): void {
		$this->tree->addFolder('/alice/files/wide');
		for ($i = 1; $i < 2000; $i++) {
			$this->tree->addFile('/alice/files/wide/' . $i . '.txt', 'x', 'text/plain');
		}
		$fixture = CopyMovePolicyFixture::forTest($this);
		$before = $fixture->count;
		$context = $this->context();
		$result = (new FileTypePreflight($context))->inspect($this->tree->node('/alice/files/wide'), '/target', $context->policy()->snapshot(), true);
		self::assertSame(2000, $result['nodes']);
		self::assertSame($before + 1, $fixture->count);
	}

	/** Copy retains 1 GiB; same-storage move adds no byte ceiling.
	 * @return void
	 */
	public function testByteCeilingAppliesToCopyOnly(): void {
		$this->tree->nodes['/alice/files/src.php/a.txt']['size'] = ReorganizationLimits::BYTES + 1;
		$context = $this->context();
		$source = $this->tree->node('/alice/files/src.php');
		self::assertNotNull((new FileTypePreflight($context))->inspect($source, '/target', $context->policy()->snapshot()));
		$this->expectException(ToolFailure::class);
		(new FileTypePreflight($context))->inspect($source, '/target', $context->policy()->snapshot(), true);
	}
	/** A changed policy after the first move preserves the receipt and prevents the next sink.
	 * @return void
	 */
	public function testBatchRechecksEverySinkAndRecordsPartialProgress(): void {
		$fixture = CopyMovePolicyFixture::forTest($this);
		$active = $fixture->value;
		$deny = array_replace($active, ['extensions' => ['md']]);
		$fixture->reads = [$active, $active, $deny];
		$out = $this->json('files_move_batch', ['moves' => [
			['from' => '/src.php/a.txt', 'to' => '/first.txt'],
			['from' => '/src.php/sub/b.txt', 'to' => '/second.txt'],
		]]);
		self::assertCount(1, $out['moved']);
		self::assertSame('alpha', $this->tree->rootFolder()->get('first.txt')->getContent());
		self::assertSame('beta', $this->tree->rootFolder()->get('src.php/sub/b.txt')->getContent());
		self::assertArrayNotHasKey('/alice/files/second.txt', $this->tree->nodes);
		self::assertCount(1, $this->batches->rows);
	}

	/** Undo keeps the original record for only the not-yet-returned items after a sink refusal.
	 * @return void
	 */
	public function testUndoRechecksEverySinkAndKeepsOnlyRemainingMoves(): void {
		$moves = [
			['from' => '/original-a.txt', 'to' => '/src.php/a.txt', 'toId' => (int)$this->tree->node('/alice/files/src.php/a.txt')->getId()],
			['from' => '/original-b.txt', 'to' => '/src.php/sub/b.txt', 'toId' => (int)$this->tree->node('/alice/files/src.php/sub/b.txt')->getId()],
		];
		$id = $this->batches->insert(new Batch(null, 'alice', 1790000000, $moves, [], null));
		$fixture = CopyMovePolicyFixture::forTest($this);
		$active = $fixture->value;
		$fixture->reads = [$active, $active, array_replace($active, ['extensions' => ['md']])];
		$out = $this->json('files_undo_batch', ['batch_id' => $id]);
		self::assertSame(1, $out['undone']);
		self::assertNotEmpty($out['conflicts']);
		self::assertSame('beta', $this->tree->rootFolder()->get('original-b.txt')->getContent());
		self::assertSame('alpha', $this->tree->rootFolder()->get('src.php/a.txt')->getContent());
		self::assertSame([$moves[0]], $this->batches->find($id, 'alice')->moves);
	}

	/** The node ceiling refuses the whole tree before creating its target.
	 * @return void
	 */
	public function testOversizedTreeHasNoEffects(): void {
		$this->tree->addFolder('/alice/files/wide');
		for ($i = 0; $i < 2000; $i++) {
			$this->tree->addFile('/alice/files/wide/' . $i . '.txt', 'x', 'text/plain');
		}
		$context = $this->context();
		try {
			(new FileTypePreflight($context))->inspect($this->tree->node('/alice/files/wide'), '/target', $context->policy()->snapshot());
			self::fail('2,001 nodes must be refused');
		} catch (ToolFailure) {
			self::assertSame([], $this->tree->ops);
			self::assertArrayNotHasKey('/alice/files/target', $this->tree->nodes);
		}
	}
	/** A hidden descendant yields a generic refusal even when another child has a forbidden type.
	 * @return void
	 */
	public function testHiddenDescendantRefusesGenericallyBeforeAnyTypeDetail(): void {
		$source = $this->tree->node('/alice/files/src.php');
		$hidden = $this->tree->addFile('/alice/files/src.php/hidden.php', 'hidden', 'text/plain');
		$visible = $this->createMock(\OCA\Mcp\Access\ResourceScope\FilesAccessGuard::class);
		$visible->method('isVisible')->willReturnCallback(static fn (\OCP\Files\Node $node): bool => (int)$node->getId() !== $hidden);
		$old = $this->context();
		$context = new CopyMoveContext($old->reorganization, $old->access, $old->report, $visible, $old->locks, CopyMovePolicyFixture::guard($this));
		try {
			(new FileTypePreflight($context))->inspect($source, '/target', $context->policy()->snapshot(), true);
			self::fail('Hidden descendants must refuse generically');
		} catch (ToolFailure $e) {
			self::assertSame(\OCA\Mcp\Platform\FileAccess\CommonMessages::notFound(), $e->getMessage());
			self::assertStringNotContainsString('hidden.php', $e->getMessage());
			self::assertSame([], $this->tree->ops);
		}
	}

	/** Off keeps the native folder copy, including existing extensions that active mode would refuse.
	 * @return void
	 */
	public function testOffFolderCopyKeepsNativeBehavior(): void {
		CopyMovePolicyFixture::forTest($this)->value = FileWritePolicy::defaults();
		$this->tree->addFile('/alice/files/src.php/sub/existing.php', 'legacy', 'text/plain');
		$out = $this->json('files_copy', ['from' => '/src.php', 'to' => '/native']);
		self::assertSame(['from', 'to', 'idBefore', 'idAfter', 'nodes', 'bytes', 'access'], array_keys($out));
		self::assertSame('legacy', $this->tree->rootFolder()->get('native/sub/existing.php')->getContent());
	}

	/** D9 refuses an unreadable policy for folder plan and confirmation before any effect.
	 * @return void
	 */
	public function testInvalidPolicyRefusesFolderBoundaries(): void {
		CopyMovePolicyFixture::forTest($this)->value = ['version' => 99];
		foreach (['files_copy', 'files_move'] as $tool) {
			foreach ([false, true] as $plan) {
				try {
					$args = ['from' => '/src.php', 'to' => '/target'];
					$plan ? $this->plan($tool, $args) : $this->tool($tool, $args);
					self::fail('D9 must refuse applicable folder writes');
				} catch (\OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable) {
					self::assertSame([], $this->tree->ops);
				}
			}
		}
	}
	/** Folder batches retain native IDs; their inverse also checks every descendant before effects.
	 * @return void
	 */
	public function testFolderBatchAndUndoUseTheWholeDescendantPolicy(): void {
		$id = $this->tree->node('/alice/files/src.php')->getId();
		$move = $this->json('files_move_batch', ['moves' => [['from' => '/src.php', 'to' => '/archive.php']]]);
		self::assertCount(1, $move['moved']);
		self::assertSame($id, $this->tree->node('/alice/files/archive.php')->getId());
		$before = $this->tree->nodes;
		$rows = $this->batches->rows;
		$fixture = CopyMovePolicyFixture::forTest($this);
		$fixture->value['extensions'] = ['md'];
		$undo = $this->json('files_undo_batch', ['batch_id' => $move['batch_id']]);
		self::assertSame(0, $undo['undone']);
		self::assertNotEmpty($undo['conflicts']);
		self::assertSame($before, $this->tree->nodes);
		self::assertSame($rows, $this->batches->rows);
		$fixture->value['extensions'] = ['txt'];
		$undo = $this->json('files_undo_batch', ['batch_id' => $move['batch_id']]);
		self::assertSame(1, $undo['undone']);
		self::assertSame($id, $this->tree->node('/alice/files/src.php')->getId());
		self::assertSame('beta', $this->tree->rootFolder()->get('src.php/sub/b.txt')->getContent());
	}

	/** Another client creates the root after preflight: do not merge any entry into that destination.
	 * @return void
	 */
	public function testOccupiedRootAfterPreflightRefusesWithoutMerging(): void {
		$context = $this->context();
		$root = $this->tree->rootFolder();
		$source = $root->get('src.php');
		$list = (new FileTypePreflight($context))->inspect($source, '/copy', $context->policy()->snapshot(), true);
		$this->tree->addFolder('/alice/files/copy');
		$this->tree->addFile('/alice/files/copy/owner.txt', 'other client', 'text/plain');
		$before = $this->tree->nodes;
		try {
			(new ListedTreeCopy($context))->execute($root, 'alice', $source, '/copy', $list);
			self::fail('The root conflict must be a refusal, not an empty success receipt');
		} catch (ToolFailure) {
			self::assertSame($before, $this->tree->nodes);
			self::assertSame([], $this->tree->ops);
		}
	}

	/** A subfolder collision skips all its descendants while an independent sibling can still be copied.
	 * @return void
	 */
	public function testFailedSubfolderDoesNotMergeDescendantsIntoForeignFolder(): void {
		$context = $this->context();
		$root = $this->tree->rootFolder();
		$source = $root->get('src.php');
		$list = (new FileTypePreflight($context))->inspect($source, '/copy', $context->policy()->snapshot(), true);
		$injected = false;
		$this->tree->beforeGet = function (string $path) use (&$injected): void {
			if (!$injected && isset($this->tree->nodes['/alice/files/copy']) && $path === '/alice/files/src.php/sub') {
				$injected = true;
				$this->tree->addFolder('/alice/files/copy/sub');
				$this->tree->addFile('/alice/files/copy/sub/owner.txt', 'other client', 'text/plain');
			}
		};
		$out = (new ListedTreeCopy($context))->execute($root, 'alice', $source, '/copy', $list);
		self::assertTrue($injected);
		self::assertTrue($out['partial']);
		self::assertContains('/copy/sub', $out['not_copied']);
		self::assertContains('/copy/sub/b.txt', $out['not_copied']);
		self::assertArrayNotHasKey('/alice/files/copy/sub/b.txt', $this->tree->nodes);
		self::assertSame('other client', $root->get('copy/sub/owner.txt')->getContent());
		self::assertSame('alpha', $root->get('copy/a.txt')->getContent());
	}

	/** A post-write privacy failure must not claim that same entry as both created and conflicted.
	 * @return void
	 */
	public function testVisibilityFailureKeepsReceiptSetsDisjoint(): void {
		$old = $this->context();
		$visible = $this->createMock(\OCA\Mcp\Access\ResourceScope\FilesAccessGuard::class);
		$visible->method('isVisible')->willReturn(true);
		$visible->method('assertVisible')->willReturnCallback(static function (\OCP\Files\Node $node): void {
			if ($node->getPath() === '/alice/files/copy/sub/b.txt') {
				throw new ToolFailure(\OCA\Mcp\Platform\FileAccess\CommonMessages::notFound());
			}
		});
		$context = new CopyMoveContext($old->reorganization, $old->access, $old->report, $visible, $old->locks, CopyMovePolicyFixture::guard($this));
		$root = $this->tree->rootFolder();
		$source = $root->get('src.php');
		$list = (new FileTypePreflight($context))->inspect($source, '/copy', $context->policy()->snapshot(), true);
		$out = (new ListedTreeCopy($context))->execute($root, 'alice', $source, '/copy', $list);
		self::assertTrue($out['partial']);
		self::assertContains('/copy/sub/b.txt', $out['not_copied']);
		self::assertNotContains('/copy/sub/b.txt', $out['created']);
		self::assertSame([], array_intersect($out['created'], array_column($out['conflicts'], 'path')));
		self::assertSame([], array_intersect($out['created'], $out['not_copied']));
	}

	/** Quota, unavailable policy and a changed byte ceiling stop instead of retrying all remaining entries.
	 * @return void
	 */
	public function testGlobalFailuresStopAndReportEveryRemainingEntry(): void {
		foreach (['quota', 'mapped-quota', 'unavailable', 'bytes'] as $kind) {
			$context = $this->context();
			$root = $this->tree->rootFolder();
			$source = $root->get('src.php');
			$to = '/copy-' . $kind;
			$list = (new FileTypePreflight($context))->inspect($source, $to, $context->policy()->snapshot(), true);
			$firstIndex = array_key_first(array_filter($list['entries'], static fn (array $entry): bool => !$entry['folder']));
			$first = $list['entries'][$firstIndex];
			$attempts = [];
			$this->tree->beforeGet = static function (string $path) use ($first, $kind, &$attempts): void {
				$attempts[] = $path;
				if ($path === $first['source'] && $kind !== 'bytes') {
					throw match ($kind) {
						'quota' => new \OCP\Files\NotEnoughSpaceException(),
						'mapped-quota' => new ToolFailure(\OCA\Mcp\Platform\FileAccess\CommonMessages::insufficientQuota()),
						default => new \OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable(),
					};
				}
			};
			if ($kind === 'bytes') {
				$this->tree->nodes[$first['source']]['size'] = ReorganizationLimits::BYTES + 1;
			}
			$out = (new ListedTreeCopy($context))->execute($root, 'alice', $source, $to, $list);
			$this->tree->beforeGet = null;
			if ($kind === 'bytes') {
				unset($this->tree->nodes[$first['source']]['size']);
			}
			self::assertTrue($out['partial']);
			self::assertCount(1, $out['conflicts']);
			foreach (array_slice($list['entries'], $firstIndex) as $entry) {
				self::assertContains($entry['to'], $out['not_copied']);
				self::assertArrayNotHasKey('/alice/files' . $entry['to'], $this->tree->nodes);
			}
			foreach (array_slice($list['entries'], $firstIndex + 1) as $entry) {
				self::assertNotContains($entry['source'], $attempts, 'No later item may be resolved after a global failure');
			}
		}
	}

	/** Pending unvalidated children hit a generic memory bound; never reveal their hidden contribution.
	 * @return void
	 */
	public function testPendingChildrenNeverRevealHiddenNodeCount(): void {
		$this->tree->addFolder('/alice/files/wide');
		for ($i = 0; $i < 2010; $i++) {
			$this->tree->addFile('/alice/files/wide/' . $i . '.txt', 'x', 'text/plain');
		}
		$old = $this->context();
		$visible = $this->createMock(\OCA\Mcp\Access\ResourceScope\FilesAccessGuard::class);
		$visible->method('isVisible')->willReturnCallback(static fn (\OCP\Files\Node $node): bool => !str_ends_with($node->getPath(), '/2009.txt'));
		$context = new CopyMoveContext($old->reorganization, $old->access, $old->report, $visible, $old->locks, CopyMovePolicyFixture::guard($this));
		try {
			(new FileTypePreflight($context))->inspect($this->tree->node('/alice/files/wide'), '/target', $context->policy()->snapshot());
			self::fail('Pending nodes cannot disclose their count');
		} catch (ToolFailure $e) {
			self::assertSame(\OCA\Mcp\Platform\FileAccess\CommonMessages::notFound(), $e->getMessage());
			self::assertSame([], $this->tree->ops);
		}
	}

	/** Repeated projected names share bounded work and a neutral refusal independent of copy/move/undo.
	 * @return void
	 */
	public function testProjectedNodeCeilingUsesNeutralMessage(): void {
		$context = $this->context();
		$preflight = new FileTypePreflight($context);
		$snapshot = $context->policy()->snapshot();
		$source = $this->tree->node('/alice/files/src.php/a.txt');
		for ($i = 0; $i < ReorganizationLimits::NODES; $i++) {
			$preflight->inspect($source, '/target.txt', $snapshot);
		}
		try {
			$preflight->inspect($source, '/target.txt', $snapshot);
			self::fail('Projected work must remain bounded');
		} catch (ToolFailure $e) {
			self::assertSame(\OCA\Mcp\Modules\Files\FileTreeMessages::tooManyNodes(ReorganizationLimits::NODES), $e->getMessage());
			self::assertStringNotContainsString('copied', $e->getMessage());
		}
	}

	/** Aliases evaluate the type of a projected source without making the native planner accept chaining.
	 * @return void
	 */
	public function testProjectedAliasesCheckEveryIntermediateTypeIndependently(): void {
		$context = $this->context();
		$out = (new FileTypePreflight($context))->moves($this->tree->rootFolder(), [
			['from' => '/src.php/a.txt', 'to' => '/stage.php'],
			['from' => '/stage.php', 'to' => '/final.php'],
		], $context->policy()->snapshot());
		self::assertCount(2, $out);
		self::assertSame(['/stage.php', '/final.php'], array_column($out, 'to'));
		self::assertSame(array_fill(0, 2, \OCA\Mcp\Access\FileTypes\FileTypeMessages::denied()), array_column($out, 'reason'));
		self::assertSame([], $this->tree->ops);
	}

	/** Only D10/B4 tree copies extend the public receipt; file copies keep their contract in either mode.
	 * @return void
	 */
	public function testOnlyListedFolderCopyExtendsThePublicReceipt(): void {
		$keys = ['from', 'to', 'idBefore', 'idAfter', 'nodes', 'bytes', 'access'];
		$file = $this->json('files_copy', ['from' => '/src.php/a.txt', 'to' => '/active.txt']);
		self::assertSame($keys, array_keys($file));
		$folder = $this->json('files_copy', ['from' => '/src.php', 'to' => '/listed']);
		self::assertSame([...$keys, 'partial', 'created', 'conflicts', 'not_copied'], array_keys($folder));
		self::assertFalse($folder['partial']);
		CopyMovePolicyFixture::forTest($this)->value = FileWritePolicy::defaults();
		$file = $this->json('files_copy', ['from' => '/src.php/a.txt', 'to' => '/off.txt']);
		self::assertSame($keys, array_keys($file));
	}
}
