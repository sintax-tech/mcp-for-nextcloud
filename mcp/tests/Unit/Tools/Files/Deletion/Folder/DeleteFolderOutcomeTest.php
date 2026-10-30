<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Files\Deletion\Folder;

use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tools\Files\Deletion\Folder\DeleteFolderOutcome;
use PHPUnit\Framework\TestCase;

/** Distinguishes root survival from measured preservation of every inspected descendant. */
final class DeleteFolderOutcomeTest extends TestCase {
	/** @return void A void native veto is preserved only when the complete observation still matches. */
	public function testIntactAndPartialTreesHaveDifferentReceipts(): void {
		$tree = new FakeTree($this);
		$tree->addFolder('/alice/files/folder');
		$source = $tree->node('/alice/files/folder');
		$before = ['fingerprint' => 'known'];
		foreach (['known' => 'preserved', 'partial' => 'uncertain'] as $fingerprint => $expected) {
			$result = DeleteFolderOutcome::observe($tree->node('/alice/files'), '/folder', (int)$source->getId(), $source->getEtag(), false,
				$before, static fn (): array => ['fingerprint' => $fingerprint]);
			self::assertTrue($result['isError']);
			self::assertSame($expected, json_decode($result['content'][0]['text'], true)['status']);
		}
		self::assertSame([], $tree->ops);
	}

	/** @return void An absent source after a thrown attempt is uncertain rather than a recovery promise. */
	public function testAbsentSourceSeparatesRemovalFromFailedAttempt(): void {
		$tree = new FakeTree($this);
		foreach ([false, true] as $failed) {
			$result = DeleteFolderOutcome::observe($tree->node('/alice/files'), '/missing', 42, 'known', $failed, [], static fn (): array => []);
			$data = json_decode($result['content'][0]['text'], true);
			self::assertSame($failed ? 'uncertain' : 'removed_destination_unconfirmed', $data['status']);
			self::assertFalse($data['recoveryConfirmed']);
		}
	}
}
