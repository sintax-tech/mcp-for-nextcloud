<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Files\Backups\BackupMessages;
use OCA\Mcp\Modules\Mail\Presentation\MailSaveMessages;
use OCA\Mcp\Platform\FileAccess\CommonMessages;

/**
 * mail_save_attachment with replace: native write failures, lock and quota refusals and the read-back of the
 * file and of its copy; nothing is ever deleted.
 */
final class MailReplaceAttachmentFailuresTest extends MailReplaceAttachmentTestCase {
	public function testUnexpectedWriteFailureIsUncertainKeepsTheCopyAndDeletesNothing(): void {
		$this->tree->writeFailure = new \RuntimeException('private filename', 17);
		$this->replaceLogger->expects($this->once())->method('error')->with(
			'MCP attachment replace write failed: {exception_class} (code {exception_code})',
			['app' => 'mcp', 'exception_class' => \RuntimeException::class, 'exception_code' => 17],
		);
		$out = $this->replaceFile();
		self::assertFalse($out['verified']);
		self::assertSame('uncertain', $out['outcome']);
		self::assertSame(MailSaveMessages::replaceUncertain($out['backup']), $out['message']);
		self::assertStringContainsString('backup named in this receipt', $out['message']);
		self::assertSame('old content', $this->tree->nodes['/alice/files' . $out['backup']]['content']);
		self::assertTrue($out['backupVerified']);
		self::assertSame(1, count(array_filter($this->tree->ops, static fn (string $op): bool => $op === 'write ' . self::TARGET)));
		self::assertStringNotContainsString('delete', implode(' ', $this->tree->ops));
	}

	public function testUncertainSuspendedReplacementNeverClaimsANamedCopy(): void {
		$this->wire(false);
		$this->tree->writeFailure = new \RuntimeException('private filename', 17);
		$out = $this->replaceFile();
		self::assertSame('uncertain', $out['outcome']);
		self::assertSame('', $out['backup']);
		self::assertSame(BackupMessages::originalKept(''), $out['backupNote']);
		self::assertSame(MailSaveMessages::replaceUncertain(''), $out['message']);
		self::assertStringNotContainsString('backup named in this receipt', $out['message']);
		self::assertStringContainsString('native versions', $out['message']);
		self::assertSame([], $this->copies());
		self::assertStringNotContainsString('delete', implode(' ', $this->tree->ops));
	}

	public function testLockAndQuotaRefusalsAfterTheCopyNameIt(): void {
		foreach ([
			[new \OCP\Lock\LockedException(self::TARGET), CommonMessages::locked()],
			[new \OCP\Files\NotEnoughSpaceException(self::TARGET), CommonMessages::insufficientQuota()],
		] as [$failure, $expected]) {
			$this->tree->writeFailure = $failure;
			try {
				$this->replaceFile();
				self::fail('The native refusal must propagate');
			} catch (ToolFailure $e) {
				self::assertStringStartsWith($expected . ' ', $e->getMessage());
				self::assertStringContainsString('/MCP backups/Documentos/saved.pdf.', $e->getMessage());
			}
		}
		self::assertSame('old content', $this->tree->nodes[self::TARGET]['content']);
	}

	public function testWrongReadBackIsUncertain(): void {
		$this->tree->afterWrite = function (string $path): void {
			if ($path === self::TARGET) {
				$this->tree->nodes[$path]['content'] = str_repeat('z', strlen(self::BYTES));
			}
		};
		$out = $this->replaceFile();
		self::assertFalse($out['verified']);
		self::assertSame('uncertain', $out['outcome']);
	}

	public function testMissingCopyMakesTheReceiptUncertain(): void {
		$this->tree->afterWrite = function (string $path): void {
			if ($path === self::TARGET) {
				foreach ($this->copies() as $copy) {
					$this->tree->nodes[$copy]['content'] = '';
				}
			}
		};
		$out = $this->replaceFile();
		self::assertFalse($out['backupVerified']);
		self::assertFalse($out['verified']);
		self::assertSame('uncertain', $out['outcome']);
	}
}
