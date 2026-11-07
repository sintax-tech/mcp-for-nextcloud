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
use OCA\Mcp\Modules\Files\Integration\DeleteTrashVeto;
use OCA\Mcp\Modules\Files\Deletion\DeleteFileHandler;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Folder;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;

/** Any pre-sink error is propagated unchanged while listeners and observation state are released. */
final class DeleteBeforeAttemptTest extends TestCase {
	/** @return void Conflict, lock and unexpected failures cannot become attempted/uncertain receipts. */
	public function testErrorsBeforeSinkKeepTheirIdentityAndAlwaysReleaseListeners(): void {
		foreach ([new ToolFailure('conflict'), new LockedException('/synthetic'), new \RuntimeException('private fixture')] as $failure) {
			$tree = new FakeTree($this);
			$tree->addFolder('/alice/files/folder');
			$source = $this->createMock(Folder::class);
			$source->method('getPath')->willReturn('/alice/files/folder');
			$source->method('getId')->willReturn(42);
			$source->method('getEtag')->willReturn('before');
			$source->expects(self::never())->method('delete');
			$eligibility = $this->createMock(DeleteEligibility::class);
			$eligibility->method('file')->willReturn($source);
			$eligibility->expects(self::once())->method('tree')->willReturn(['fingerprint' => 'before']);
			$eligibility->expects(self::once())->method('verifyTree')->willReturnCallback(static function () use ($failure, $tree): void {
				$tree->nodes['/alice/files/folder']['etag'] = 'changed';
				throw $failure;
			});
			$eligibility->expects(self::once())->method('invalidate');
			$locks = $this->createMock(LockAwareWrite::class);
			$locks->method('run')->willReturnCallback(static fn ($work) => $work());
			$listeners = [];
			$events = $this->createMock(IEventDispatcher::class);
			$events->expects(self::exactly(2))->method('addListener')->willReturnCallback(static function ($name, $listener) use (&$listeners): void { $listeners[$name] = $listener; });
			$events->expects(self::exactly(2))->method('removeListener')->willReturnCallback(static function ($name, $listener) use (&$listeners): void {
				self::assertSame($listeners[$name], $listener);
				unset($listeners[$name]);
			});
			try {
				(new DeleteFileHandler($eligibility, $locks, new \Psr\Log\NullLogger(), new DeleteTrashVeto($events)))->handle($tree->node('/alice/files'), 'alice', ['path' => '/folder']);
				self::fail('Expected the original pre-sink failure');
			} catch (\Throwable $caught) {
				self::assertSame($failure, $caught);
			}
			self::assertSame([], $listeners);
			self::assertSame([], $tree->ops);
		}
	}
}
