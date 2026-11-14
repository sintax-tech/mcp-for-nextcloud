<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Files\Presentation\EditingMessages;

/**
 * mail_save_attachment with replace: ETag, file changes during the download, backup folders, type policy,
 * confirm_shared and the revoked grant refuse without replacing the file.
 */
final class MailReplaceAttachmentGuardsTest extends MailReplaceAttachmentTestCase {
	public function testStaleEtagRefusesBeforeTheDownload(): void {
		try {
			$this->replaceFile(['etag' => 'stale']);
			self::fail('A different ETag must refuse');
		} catch (ToolFailure) {
		}
		self::assertNotContains('imap:attachment:2', $this->mail->imapCommands());
		self::assertSame('old content', $this->tree->nodes[self::TARGET]['content']);
		self::assertSame([], $this->copies());
	}

	public function testFileChangedDuringTheDownloadRefusesWithoutCopyOrWrite(): void {
		$prepared = $this->save->prepare(self::ARGS, 'alice', true);
		$this->tree->nodes[self::TARGET]['content'] = 'edited elsewhere';
		$this->tree->nodes[self::TARGET]['etag'] .= '*';
		try {
			$this->save->save($prepared, self::ARGS + ['confirm' => true], 'alice');
			self::fail('A changed file must refuse');
		} catch (ToolFailure) {
		}
		self::assertSame('edited elsewhere', $this->tree->nodes[self::TARGET]['content']);
		self::assertSame([], $this->copies());
	}

	public function testCurrentAndHistoricalBackupFoldersRefuse(): void {
		foreach (['MCP backups', '.mcp-backups'] as $folder) {
			$this->tree->addFile('/alice/files/' . $folder . '/old.pdf', 'copy');
			try {
				$this->replacementPlan(['path' => '/' . $folder . '/old.pdf']);
				self::fail('Backup folders are protected');
			} catch (ToolFailure $e) {
				self::assertSame(EditingMessages::backupPath(), $e->getMessage());
			}
		}
		self::assertSame([], $this->tree->ops);
	}

	public function testTypePolicyRefusesBeforeTheDownload(): void {
		$this->types->allow(['txt']);
		try {
			$this->replaceFile();
			self::fail('The type policy applies to the replaced name');
		} catch (ToolFailure) {
		}
		self::assertNotContains('imap:attachment:2', $this->mail->imapCommands());
		self::assertSame([], $this->tree->ops);
	}

	public function testTypePolicyChangedAfterTheCopyRefusesAtTheSinkAndKeepsTheCopy(): void {
		$this->tree->beforeWrite = function (string $path): void {
			if (str_contains($path, '/MCP backups/')) {
				$this->types->allow(['txt']);
			}
		};
		try {
			$this->replaceFile();
			self::fail('The type policy is read again right before the write');
		} catch (ToolFailure $e) {
			self::assertStringContainsString('/MCP backups/Documentos/saved.pdf.', $e->getMessage());
		}
		self::assertSame('old content', $this->tree->nodes[self::TARGET]['content']);
		self::assertNotContains('write ' . self::TARGET, $this->tree->ops);
		$backups = array_values(array_filter($this->copies(), static fn (string $p): bool => str_ends_with($p, '.bak')));
		self::assertCount(1, $backups);
		self::assertSame('old content', $this->tree->nodes[$backups[0]]['content']);
	}

	public function testSharedFileNeedsItsOwnConfirmationBeforeTheDownload(): void {
		$this->tree->addFolder('/alice/files/Documentos', ['scope' => 'shared']);
		$this->tree->addFile(self::TARGET, 'old content', 'application/pdf', ['scope' => 'shared']);
		self::assertTrue($this->replacementPlan()['requiresSharedConfirmation']);
		self::assertTrue($this->replaceFile()['requiresConfirmation']);
		self::assertNotContains('imap:attachment:2', $this->mail->imapCommands());
		self::assertSame([], $this->tree->ops);
		self::assertTrue($this->replaceFile(['confirm_shared' => true])['verified']);
	}

	public function testRevokedGrantRefusesAtExecution(): void {
		$this->replacementPlan();
		$this->sharePolicy->setGrant('alice', 'mail', 'save', false);
		try {
			$this->replaceFile();
			self::fail('The grant is revalidated');
		} catch (ToolFailure) {
		}
		self::assertSame('old content', $this->tree->nodes[self::TARGET]['content']);
		self::assertSame([], $this->tree->ops);
	}
}
