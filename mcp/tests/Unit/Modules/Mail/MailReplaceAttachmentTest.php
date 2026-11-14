<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Files\Backups\BackupMessages;
use OCA\Mcp\Modules\Files\Presentation\EditingMessages;
use OCA\Mcp\Modules\Mail\Presentation\{MailSaveMessages, MailSavePlanRenderer};
use OCA\Mcp\Modules\Mail\Writing\SaveAttachmentHandler;

/**
 * mail_save_attachment with replace: the plan, the verified replacement and its copy, the occupied-name
 * refusal and the #17 suspension with or without native versioning.
 */
final class MailReplaceAttachmentTest extends MailReplaceAttachmentTestCase {
	public function testPlanShowsTheFileAndTheCopyAndWritesNothing(): void {
		$file = $this->tree->nodes[self::TARGET];
		$plan = $this->replacementPlan();
		self::assertTrue($plan['replace']);
		self::assertSame(['path' => '/Documentos/saved.pdf', 'fileId' => $file['id'], 'size' => 11, 'etag' => $file['etag']], $plan['replaces']);
		self::assertSame('/MCP backups', $plan['backup']);
		self::assertTrue($plan['recoverable']);
		self::assertSame(EditingMessages::planBackupConsequence(), $plan['backupNote']);
		self::assertSame(MailSaveMessages::replaceConsequence(), $plan['consequence']);
		self::assertSame([], $this->tree->ops);
		self::assertNotContains('imap:attachment:2', $this->mail->imapCommands());
		$text = (new MailSavePlanRenderer())->render($plan);
		self::assertStringContainsString(MailSaveMessages::replaces('11', $file['etag']), $text);
		self::assertStringContainsString(MailSaveMessages::copyFolder('/MCP backups'), $text);
	}

	public function testReplaceKeepsAVerifiedCopyAndReadsEverythingBack(): void {
		$before = $this->tree->nodes[self::TARGET];
		$out = $this->replaceFile();
		self::assertSame(self::BYTES, $this->tree->nodes[self::TARGET]['content']);
		self::assertTrue($out['verified']);
		self::assertSame('replaced', $out['outcome']);
		self::assertSame(MailSaveMessages::replaced(), $out['message']);
		self::assertSame($before['id'], $out['fileId']);
		self::assertSame(strlen(self::BYTES), $out['size']);
		self::assertSame(hash('sha256', self::BYTES), $out['sha256']);
		self::assertSame(['fileId' => $before['id'], 'size' => 11, 'etag' => $before['etag']], $out['replaced']);
		self::assertStringStartsWith('/MCP backups/Documentos/saved.pdf.', $out['backup']);
		self::assertSame('old content', $this->tree->nodes['/alice/files' . $out['backup']]['content']);
		self::assertTrue($out['backupVerified']);
		self::assertSame(11, $out['backupSize']);
		self::assertSame(BackupMessages::originalKept($out['backup']), $out['backupNote']);
		self::assertStringNotContainsString('delete', implode(' ', $this->tree->ops));
	}

	public function testOccupiedNameWithoutReplaceExplainsReplaceBeforeImap(): void {
		try {
			(new SaveAttachmentHandler($this->save))->handle(array_diff_key(self::ARGS, ['replace' => true]) + ['confirm' => true], 'alice');
			self::fail('An occupied name must refuse without replace');
		} catch (ToolFailure $e) {
			self::assertSame(MailSaveMessages::occupied(), $e->getMessage());
		}
		foreach ([false, 'true', 1] as $replace) {
			try {
				$this->replacementPlan(['replace' => $replace]);
				self::fail('Only replace === true replaces');
			} catch (ToolFailure $e) {
				self::assertSame(MailSaveMessages::occupied(), $e->getMessage());
			}
		}
		self::assertSame([], $this->mail->imapCommands());
		self::assertSame([], $this->tree->ops);
	}

	public function testReplaceOfAMissingOrHiddenFileOrFolderRefusesBeforeImap(): void {
		foreach (['/Documentos/absent.pdf', '/Documentos'] as $path) {
			try {
				$this->replacementPlan(['path' => $path]);
				self::fail('Only an existing file is replaced');
			} catch (ToolFailure) {
			}
		}
		self::assertSame([], $this->mail->imapCommands());
		self::assertSame([], $this->tree->ops);
	}

	public function testSuspendedCopiesWithNativeVersioningReplaceAndSayNoCopyWasMade(): void {
		$this->wire(false);
		$plan = $this->replacementPlan();
		self::assertSame('', $plan['backup']);
		self::assertFalse($plan['recoverable']);
		self::assertStringContainsString('MCP pre-write copies are suspended', $plan['backupNote']);
		self::assertStringContainsString($plan['backupNote'], (new MailSavePlanRenderer())->render($plan));
		$out = $this->replaceFile();
		self::assertTrue($out['verified']);
		self::assertSame('', $out['backup']);
		self::assertSame(BackupMessages::originalKept(''), $out['backupNote']);
		self::assertSame([], $this->copies());
		self::assertSame(self::BYTES, $this->tree->nodes[self::TARGET]['content']);
	}

	public function testSuspensionWithoutNativeVersioningRefusesWithoutWriting(): void {
		$this->wire(false);
		$this->enabled = [];
		try {
			$this->replaceFile();
			self::fail('Replacement requires native versioning');
		} catch (ToolFailure $e) {
			self::assertSame(EditingMessages::versioningOff(), $e->getMessage());
		}
		self::assertSame('old content', $this->tree->nodes[self::TARGET]['content']);
		self::assertSame([], $this->copies());
	}
}
