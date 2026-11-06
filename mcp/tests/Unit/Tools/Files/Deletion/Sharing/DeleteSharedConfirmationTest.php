<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Files\Deletion\Sharing;

use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Platform\FileAccess\LockAwareWrite;
use OCA\Mcp\Modules\Files\Deletion\DeleteEligibility;
use OCA\Mcp\Modules\Files\Deletion\DeleteTrashVeto;
use OCA\Mcp\Modules\Files\Deletion\Sharing\DeleteSharedConfirmation;
use OCA\Mcp\Modules\Files\Handler\DeleteFileHandler;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\TestCase;

/** Shared acknowledgement is separate from confirm and is revalidated before any native attempt. */
final class DeleteSharedConfirmationTest extends TestCase {
	/** @return void A missing shared flag requests confirmation without sink or recipient data. */
	public function testMissingSharedFlagHasNoEffectOrRecipientDisclosure(): void {
		$tree = new FakeTree($this); $tree->addFile('/alice/files/a.txt', 'keep', 'text/plain');
		$eligibility = $this->createMock(DeleteEligibility::class); $eligibility->method('file')->willReturn($tree->node('/alice/files/a.txt'));
		$eligibility->method('shared')->willReturn(true);
		$locks = $this->createMock(LockAwareWrite::class); $locks->expects(self::never())->method('run');
		$handler = new DeleteFileHandler($eligibility, $locks, new \Psr\Log\NullLogger(), new DeleteTrashVeto($this->createMock(\OCP\EventDispatcher\IEventDispatcher::class)));
		$plan = $handler->plan($tree->node('/alice/files'), 'alice', ['path' => '/a.txt']);
		self::assertTrue($plan['shared']); self::assertTrue($plan['requiresSharedConfirmation']);
		$result = $handler->handle($tree->node('/alice/files'), 'alice', ['path' => '/a.txt']);
		$data = json_decode($result['content'][0]['text'], true);
		self::assertTrue($data['requiresSharedConfirmation']); self::assertArrayNotHasKey('owner', $data); self::assertArrayNotHasKey('recipients', $data);
		self::assertSame([], $tree->ops);
	}

	/** @return void A share created during preparation without shared confirmation is a pre-sink refusal. */
	public function testNewSharedImpactAtFinalFrontierRefusesBeforeSink(): void {
		$tree = new FakeTree($this); $tree->addFile('/alice/files/a.txt', 'keep', 'text/plain');
		$eligibility = $this->createMock(DeleteEligibility::class); $eligibility->method('file')->willReturn($tree->node('/alice/files/a.txt'));
		$eligibility->method('shared')->willReturnOnConsecutiveCalls(false, true);
		$eligibility->expects(self::once())->method('invalidate');
		$locks = $this->createMock(LockAwareWrite::class); $locks->method('run')->willReturnCallback(static fn ($work) => $work());
		try {
			(new DeleteFileHandler($eligibility, $locks, new \Psr\Log\NullLogger(), new DeleteTrashVeto($this->createMock(\OCP\EventDispatcher\IEventDispatcher::class))))->handle($tree->node('/alice/files'), 'alice', ['path' => '/a.txt']);
			self::fail('Expected explicit shared confirmation');
		} catch (ToolFailure $error) { self::assertStringContainsString('confirm_shared', $error->getMessage()); }
		self::assertSame([], $tree->ops);
		self::assertNull(DeleteSharedConfirmation::request(true, true));
	}
}
