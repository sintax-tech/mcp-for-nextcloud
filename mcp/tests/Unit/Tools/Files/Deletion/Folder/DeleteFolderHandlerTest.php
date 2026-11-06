<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Files\Deletion\Folder;

use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Platform\FileAccess\LockAwareWrite;
use OCA\Mcp\Modules\Files\Deletion\DeleteEligibility;
use OCA\Mcp\Modules\Files\Deletion\DeleteTrashVeto;
use OCA\Mcp\Modules\Files\Handler\DeleteFileHandler;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use PHPUnit\Framework\TestCase;

/** The existing handler observes the whole tree and never retries a native folder call. */
final class DeleteFolderHandlerTest extends TestCase {
	/** @return void A real void sink reached through the handler is preserved only after a matching tree observation. */
	public function testVoidVetoReachesSinkOnceAndObservesTree(): void {
		$this->exercise(false, false, 'preserved');
	}

	/** @return void A surviving root with a different child observation is uncertain. */
	public function testPartialTreeNeverBecomesPreservedRoot(): void {
		$this->exercise(true, false, 'uncertain');
	}

	/** @return void Concurrent changes found by the final complete check prevent the native sink. */
	public function testFinalRevalidationRefusesBeforeSink(): void {
		$this->expectException(ToolFailure::class);
		$this->expectExceptionMessage('conflict');
		$this->exercise(true, true, 'not-attempted');
	}

	/**
	 * @param bool $partial Whether post-attempt child metadata differs.
	 * @param bool $refuse Whether final fresh revalidation refuses before native deletion.
	 * @param string $expected Expected measured outcome.
	 * @return void Exercise one confirmed call without any real filesystem mutation.
	 */
	private function exercise(bool $partial, bool $refuse, string $expected): void {
		$tree = new FakeTree($this);
		$tree->addFolder('/alice/files/folder');
		$original = $tree->node('/alice/files/folder');
		$source = $this->createMock(Folder::class);
		$source->method('getId')->willReturn($original->getId());
		$source->method('getPath')->willReturn($original->getPath());
		$source->method('getEtag')->willReturn($original->getEtag());
		$source->expects($refuse ? self::never() : self::once())->method('delete')->willReturn(null);
		$eligibility = $this->createMock(DeleteEligibility::class);
		$eligibility->method('file')->willReturn($source);
		$eligibility->expects(self::once())->method('invalidate');
		$count = 0;
		$eligibility->method('tree')->willReturnCallback(static function () use (&$count, $partial): array {
			return ['fingerprint' => (++$count > 1 && $partial) ? 'changed' : 'before'];
		});
		$eligibility->expects(self::once())->method('verifyTree')->willReturnCallback(function () use ($refuse, $tree, $partial): void {
			if ($refuse) {
				if ($partial) { $tree->nodes['/alice/files/folder']['etag'] = 'changed-by-another-client'; }
				throw new ToolFailure('conflict');
			}
		});
		$locks = $this->createMock(LockAwareWrite::class);
		$locks->method('assertWritable')->willReturnCallback(static function () use (&$count): void {
			self::assertGreaterThan(0, $count, 'Complete bounded inspection must precede recursive lock admission');
		});
		$locks->method('run')->willReturnCallback(static fn ($work) => $work());
		$handler = new DeleteFileHandler($eligibility, $locks, new \Psr\Log\NullLogger(), new DeleteTrashVeto($this->createMock(\OCP\EventDispatcher\IEventDispatcher::class)));
		$result = $handler->handle($tree->node('/alice/files'), 'alice', ['path' => '/folder']);
		self::assertTrue($result['isError']);
		self::assertSame($expected, json_decode($result['content'][0]['text'], true)['status']);
		self::assertSame([], $tree->ops);
	}
}
