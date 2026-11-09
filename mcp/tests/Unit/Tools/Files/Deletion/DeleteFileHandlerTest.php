<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Files\Deletion;

use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Platform\FileAccess\LockAwareWrite;
use OCA\Mcp\Modules\Files\Deletion\DeleteEligibility;
use OCA\Mcp\Modules\Files\Deletion\DeleteMessages;
use OCA\Mcp\Modules\Files\Deletion\DeleteOutcome;
use OCA\Mcp\Modules\Files\Deletion\DeleteFileHandler;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\TestCase;

/** Discriminates void veto, successful removal, changed source and partial failures; no trash claim from void. */
final class DeleteFileHandlerTest extends TestCase {
	/** @var FakeTree Native-shaped source view recording every mutation. */
	private FakeTree $tree;
	/** @var DeleteEligibility Fresh boundary collaborator. */
	private DeleteEligibility $eligibility;
	/** @var LockAwareWrite Existing lock helper executes the supplied native closure. */
	private LockAwareWrite $locks;

	/** @return void Prepare one disposable synthetic source and collaborators. */
	protected function setUp(): void {
		$this->tree = new FakeTree($this);
		$this->tree->addFile('/alice/files/a.txt', 'original', 'text/plain');
		$this->eligibility = $this->createMock(DeleteEligibility::class);
		$this->locks = $this->createMock(LockAwareWrite::class);
		$this->locks->method('run')->willReturnCallback(static fn ($work) => $work());
	}

	/** @return DeleteFileHandler Handler with two real resolutions through the synthetic view. */
	private function handler(): DeleteFileHandler {
		$this->eligibility->method('file')->willReturnCallback(fn () => $this->tree->node('/alice/files/a.txt'));
		return new DeleteFileHandler($this->eligibility, $this->locks, new \Psr\Log\NullLogger(), new \OCA\Mcp\Modules\Files\Integration\DeleteTrashVeto($this->createMock(\OCP\EventDispatcher\IEventDispatcher::class)));
	}

	/** @return void Preview reads identity/ETag and the risk, creating neither a copy nor a deletion. */
	public function testPlanHasRiskIdentityAndNoEffects(): void {
		$plan = $this->handler()->plan($this->tree->node('/alice/files'), 'alice', ['path' => '/a.txt']);
		self::assertSame('files_delete', $plan['action']);
		self::assertSame($this->tree->nodes['/alice/files/a.txt']['id'], $plan['id']);
		self::assertFalse($plan['recoveryGuaranteed']);
		self::assertStringContainsString(DeleteMessages::risk(), $plan['message']);
		self::assertSame([], $this->tree->ops);
	}

	/** @return void Absence after Node::delete is measured but never called confirmed trash/recovery. */
	public function testRemovalHasAnExplicitUnconfirmedDestination(): void {
		$result = $this->handler()->handle($this->tree->node('/alice/files'), 'alice', ['path' => '/a.txt']);
		$data = json_decode($result['content'][0]['text'], true);
		self::assertSame('removed_destination_unconfirmed', $data['status']);
		self::assertFalse($data['recoveryConfirmed']);
		self::assertArrayNotHasKey('trash', $data);
		self::assertSame(['delete /alice/files/a.txt'], $this->tree->ops);
	}

	/** @return void A native void veto still present at observation is an error, not a successful deletion. */
	public function testVoidVetoIsPreservedErrorWithoutFalsePermanentLossAlarm(): void {
		$original = $this->tree->node('/alice/files/a.txt');
		$file = $this->createMock(\OCP\Files\File::class);
		$file->method('getId')->willReturn($original->getId());
		$file->method('getEtag')->willReturn($original->getEtag());
		$file->method('getPath')->willReturn($original->getPath());
		$file->expects(self::once())->method('delete')->willReturn(null);
		$this->eligibility->method('file')->willReturn($file);
		$handler = new DeleteFileHandler($this->eligibility, $this->locks, new \Psr\Log\NullLogger(),
			new \OCA\Mcp\Modules\Files\Integration\DeleteTrashVeto($this->createMock(\OCP\EventDispatcher\IEventDispatcher::class)));
		$result = $handler->handle($this->tree->node('/alice/files'), 'alice', ['path' => '/a.txt']);
		self::assertSame([], $this->tree->ops);
		self::assertTrue($result['isError']);
		$data = json_decode($result['content'][0]['text'], true);
		self::assertSame('preserved', $data['status']);
		self::assertStringNotContainsString('permanent deletion', $data['message']);
	}

	/** @return void A thrown native mutation after removal yields unknown, with no retry or compensating deletion. */
	public function testFailureAfterDeletionIsUncertain(): void {
		$this->tree->afterDelete = static function (): void { throw new \RuntimeException('private native details'); };
		$result = $this->handler()->handle($this->tree->node('/alice/files'), 'alice', ['path' => '/a.txt']);
		self::assertTrue($result['isError']);
		self::assertSame('uncertain', json_decode($result['content'][0]['text'], true)['status']);
		self::assertStringNotContainsString('private native details', $result['content'][0]['text']);
		self::assertCount(1, $this->tree->ops);
	}

	/** @return void Revalidation refusal after prepare performs no deletion, including direct confirmations. */
	public function testSecondBoundaryCanRefuseWithoutSink(): void {
		$file = $this->tree->node('/alice/files/a.txt');
		$count = 0;
		$this->eligibility->method('file')->willReturnCallback(static function () use (&$count, $file) {
			if (++$count === 2) { throw new ToolFailure('changed'); }
			return $file;
		});
		try {
			(new DeleteFileHandler($this->eligibility, $this->locks, new \Psr\Log\NullLogger(), new \OCA\Mcp\Modules\Files\Integration\DeleteTrashVeto($this->createMock(\OCP\EventDispatcher\IEventDispatcher::class))))->handle($this->tree->node('/alice/files'), 'alice', ['path' => '/a.txt']);
			self::fail('Expected revalidation refusal');
		} catch (ToolFailure) {
			self::assertSame([], $this->tree->ops);
		}
	}

	/** @return void Replaced identity at the path is uncertain and must never leak the new node identity. */
	public function testReplacementIdentityIsNotReportedAsPreservedOrRemoved(): void {
		$result = DeleteOutcome::observe($this->tree->node('/alice/files'), '/a.txt', 99999, 'stale', false);
		self::assertTrue($result['isError']);
		self::assertSame('uncertain', json_decode($result['content'][0]['text'], true)['status']);
	}
	/** @return void A logger failure after a partial native effect must not hide the measured uncertain receipt. */
	public function testLoggerFailureCannotHidePartialEffect(): void {
		$this->tree->afterDelete = static function (): void { throw new \RuntimeException('native failure'); };
		$this->eligibility->method('file')->willReturnCallback(fn () => $this->tree->node('/alice/files/a.txt'));
		$logger = $this->createMock(\Psr\Log\LoggerInterface::class);
		$logger->method('warning')->willThrowException(new \RuntimeException('logger failure'));
		$handler = new DeleteFileHandler($this->eligibility, $this->locks, $logger,
			new \OCA\Mcp\Modules\Files\Integration\DeleteTrashVeto($this->createMock(\OCP\EventDispatcher\IEventDispatcher::class)));
		$result = $handler->handle($this->tree->node('/alice/files'), 'alice', ['path' => '/a.txt']);
		self::assertTrue($result['isError']);
		self::assertSame('uncertain', json_decode($result['content'][0]['text'], true)['status']);
		self::assertCount(1, $this->tree->ops);
	}

	/** @return void A post-attempt cleanup failure still measures the source and never retries the native sink. */
	public function testInvalidationFailureStillObservesPartialEffectAndDiagnoses(): void {
		$this->eligibility->method('file')->willReturnCallback(fn () => $this->tree->node('/alice/files/a.txt'));
		$this->eligibility->method('invalidate')->willThrowException(new \RuntimeException('private cleanup detail'));
		$logger = $this->createMock(\Psr\Log\LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with('MCP file deletion cache invalidation failed: {exception_class} (code {exception_code})',
			['app' => 'mcp', 'exception_class' => \RuntimeException::class, 'exception_code' => 0]);
		$handler = new DeleteFileHandler($this->eligibility, $this->locks, $logger,
			new \OCA\Mcp\Modules\Files\Integration\DeleteTrashVeto($this->createMock(\OCP\EventDispatcher\IEventDispatcher::class)));
		$result = $handler->handle($this->tree->node('/alice/files'), 'alice', ['path' => '/a.txt']);
		self::assertTrue($result['isError']);
		self::assertSame('uncertain', json_decode($result['content'][0]['text'], true)['status']);
		self::assertSame(['delete /alice/files/a.txt'], $this->tree->ops);
		self::assertStringNotContainsString('private cleanup detail', $result['content'][0]['text']);
	}

}
