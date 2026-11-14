<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Tests\Unit\Tools\Mail\FakeMimePart;
use OCA\Mcp\Modules\Mail\Writing\{MailSaveBudget, SaveAttachmentHandler, SaveAttachmentPreview};
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** Safe native Mail sequence through the protected Files adapter, with byte/permission/race controls. */
final class MailSaveAttachmentTest extends MailSaveAttachmentTestCase {
	public function testPreviewFetchesMetadataOnlyAndWritesNothing(): void {
		$plan = (new SaveAttachmentPreview($this->save))->plan(self::ARGS, 'alice');
		self::assertSame([], $this->tree->ops);
		self::assertSame(['imap:status', 'imap:fetch:envelope,size,structure', 'imap:status(selected)', 'imap:logout'], $this->mail->imapCommands());
		self::assertSame('/Documentos/saved.pdf', $plan['path']); self::assertGreaterThan(1048576, $plan['effectiveMaxBytes']);
		self::assertArrayNotHasKey('subject', $plan); self::assertArrayNotHasKey('from', $plan);
	}
	public function testBinaryBytesHashAndReadBackReceipt(): void {
		$out = $this->saveFile();
		self::assertTrue($out['verified']); self::assertSame('saved', $out['outcome']);
		self::assertSame("\x00\xff\r\nPDF bytes\x00", $this->tree->nodes['/alice/files/Documentos/saved.pdf']['content']);
		self::assertSame(hash('sha256', "\x00\xff\r\nPDF bytes\x00"), $out['sha256']);
		foreach (['path','size','etag','fileId','effectiveMaxBytes'] as $key) { self::assertArrayHasKey($key, $out); }
		self::assertSame(['imap:status', 'imap:fetch:envelope,size,structure', 'imap:status(selected)', 'imap:attachment:2', 'imap:status(selected)', 'imap:logout'], $this->mail->imapCommands());
	}
	public function testSaveLargerThanTheTextReaderCeiling(): void {
		$bytes = str_repeat('x', 2 * 1048576); $this->part->bytes = strlen($bytes); $this->source($bytes, 4 * 1048576);
		self::assertTrue($this->saveFile()['verified']);
		self::assertSame(1048576, \OCA\Mcp\Modules\Mail\Integration\MailAttachmentReader::MAX_ATTACHMENT_BYTES);
	}
	public function testOccupiedNameRefusesBeforeImap(): void {
		$this->tree->addFile('/alice/files/Documentos/saved.pdf', 'existing');
		try { $this->saveFile(); self::fail(); } catch (ToolFailure) {}
		self::assertSame([], $this->mail->imapCommands()); self::assertSame([], $this->tree->ops);
	}
	public function testRealSizeAboveBudgetCreatesNothing(): void {
		$this->config->app['mcp']['checkout_max_bytes'] = '200'; $this->source(str_repeat('x', 201));
		try { $this->saveFile(); self::fail(); } catch (ToolFailure $e) { self::assertStringContainsString('200', $e->getMessage()); }
		self::assertSame([], $this->tree->ops);
	}
	public function testDeclaredOversizeNeverFetchesContent(): void {
		$this->part->bytes = 200; $this->config->app['mcp']['checkout_max_bytes'] = '100';
		try { $this->saveFile(); self::fail(); } catch (ToolFailure) {}
		self::assertNotContains('imap:attachment:2', $this->mail->imapCommands()); self::assertSame([], $this->tree->ops);
	}
	public function testChangedUidValidityNeverFetchesContent(): void {
		$this->mail->uidValidityOnOpen['INBOX'] = '8';
		try { $this->saveFile(); self::fail(); } catch (ToolFailure) {}
		self::assertNotContains('imap:attachment:2', $this->mail->imapCommands()); self::assertSame([], $this->tree->ops);
	}
	public function testRevokedGrantRefusesAtExecution(): void {
		(new SaveAttachmentPreview($this->save))->plan(self::ARGS, 'alice');
		$this->sharePolicy->setGrant('alice', 'mail', 'save', false);
		$this->expectException(ToolFailure::class); $this->saveFile();
	}
	public function testDisabledMailRefusesDirectExecution(): void {
		$this->mail->enabled = false; $this->expectException(ToolFailure::class); $this->saveFile();
	}
	public function testStrictConfirmation(): void {
		foreach ([false, 'true', 1, null] as $confirm) {
			try { (new SaveAttachmentHandler($this->save))->handle(self::ARGS + ['confirm' => $confirm], 'alice'); self::fail(); } catch (ToolFailure) {}
		}
		self::assertSame([], $this->tree->ops); self::assertSame([], $this->mail->imapCommands());
	}
	public function testPartialWriteIsUncertainAndNeverDeleted(): void {
		$this->tree->writeFailure = new \RuntimeException('Unexpected native sink failure'); $out = $this->saveFile();
		self::assertFalse($out['verified']); self::assertSame('uncertain', $out['outcome']);
		self::assertArrayHasKey('/alice/files/Documentos/saved.pdf', $this->tree->nodes);
		self::assertStringNotContainsString('delete', implode(' ', $this->tree->ops));
	}
	public function testWrongReadBackBytesAreUncertain(): void {
		$this->tree->afterWrite = function ($path): void { $this->tree->nodes[$path]['content'] = str_repeat('z', 14); };
		self::assertFalse($this->saveFile()['verified']);
	}
	public function testFinalNameAloneDecidesTheTypePolicy(): void {
		$this->types->allow(['pdf']);
		$this->source("arbitrary binary \xff");
		self::assertTrue($this->saveFile()['verified']);
	}
	public function testChangedTypePolicyRefusesBeforeFetchingContent(): void {
		(new SaveAttachmentPreview($this->save))->plan(self::ARGS, 'alice');
		$this->types->allow(['txt']);
		try { $this->saveFile(); self::fail(); } catch (ToolFailure) {}
		self::assertSame([], $this->tree->ops);
		self::assertNotContains('imap:attachment:2', $this->mail->imapCommands());
	}
	public function testReadOnlyAndBackupDestinationsRefuse(): void {
		$this->tree->nodes['/alice/files/Documentos']['permissions'] = \OCP\Constants::PERMISSION_READ;
		try { $this->saveFile(); self::fail(); } catch (ToolFailure) {}
		try { (new SaveAttachmentPreview($this->save))->plan(array_replace(self::ARGS, ['path' => '/MCP backups/blocked.pdf']), 'alice'); self::fail(); } catch (ToolFailure) {}
		self::assertSame([], $this->tree->ops);
	}
	public function testSharedDestinationNeedsItsOwnConfirmation(): void {
		$this->tree->addFolder('/alice/files/Documentos', ['scope' => 'shared']);
		$plan = (new SaveAttachmentPreview($this->save))->plan(self::ARGS, 'alice');
		self::assertTrue($plan['requiresSharedConfirmation']);
		$out = $this->saveFile(); self::assertTrue($out['requiresConfirmation']); self::assertSame([], $this->tree->ops);
		self::assertNotContains('imap:attachment:2', $this->mail->imapCommands());
		$out = json_decode((new SaveAttachmentHandler($this->save))->handle(self::ARGS + ['confirm' => true, 'confirm_shared' => true], 'alice')['content'][0]['text'], true);
		self::assertTrue($out['verified']);
	}
	public function testFolderIdentityChangeAfterDownloadRefuses(): void {
		$prepared = $this->save->prepare(self::ARGS, 'alice', true);
		$this->tree->nodes['/alice/files/Documentos']['id']++;
		try { $this->save->save($prepared, self::ARGS, 'alice'); self::fail(); } catch (ToolFailure) {}
		self::assertSame([], $this->tree->ops);
	}
	public function testInvalidIdsAndForeignMessagesNeverFetchContent(): void {
		foreach (['0', '../2', '2.0'] as $id) {
			try { (new SaveAttachmentPreview($this->save))->plan(array_replace(self::ARGS, ['attachment_id' => $id]), 'alice'); self::fail(); } catch (ToolFailure) {}
		}
		$this->mail->withAccount(2, 'bob', 'Other', 'bob@example.com')->withFolder(2, ['id' => 2, 'name' => 'Other']);
		$this->mail->withMessage(2, ['id' => 8]);
		foreach ([8, 999999] as $id) {
			try { (new SaveAttachmentPreview($this->save))->plan(array_replace(self::ARGS, ['message_id' => $id]), 'alice'); self::fail(); } catch (ToolFailure $e) { $errors[] = $e->getMessage(); }
		}
		self::assertSame($errors[0], $errors[1]); self::assertSame([], $this->mail->imapCommands());
	}
	public function testPreflightMessageAndStructureBudgets(): void {
		$this->source('x', 129 * 1048576);
		try { $this->saveFile(); self::fail(); } catch (ToolFailure) {}
		self::assertNotContains('imap:attachment:2', $this->mail->imapCommands());
		$structure = FakeMimePart::multipart('multipart/mixed', array_fill(0, 513, new FakeMimePart('text/plain')));
		$this->mail->withMessage(1, ['id' => 7, 'structure' => $structure]);
		try { $this->saveFile(); self::fail(); } catch (ToolFailure) {}
		self::assertSame([], $this->tree->ops);
	}
	/** A clean race refuses with the replace explanation and preserves the other creator's bytes. */
	public function testNameTakenDuringCreationKeepsItsActionableRefusal(): void {
		$this->tree->beforeCreate = function (string $path): void {
			$this->tree->addFile($path, 'someone else');
		};
		try {
			$this->saveFile();
			self::fail('The collision must refuse');
		} catch (ToolFailure $e) {
			self::assertSame(\OCA\Mcp\Modules\Mail\Presentation\MailSaveMessages::occupied(), $e->getMessage());
		}
		self::assertSame('someone else', $this->tree->nodes['/alice/files/Documentos/saved.pdf']['content']);
		self::assertNotContains('write /alice/files/Documentos/saved.pdf', $this->tree->ops);
	}
	/** A native creation can fail after a hook changed storage: retain the Mail uncertainty receipt. */
	public function testUnexpectedNativeCreationFailureRemainsUncertain(): void {
		$this->tree->beforeCreate = static fn () => throw new \RuntimeException('private filename');
		$out = $this->saveFile();
		self::assertSame('uncertain', $out['outcome']);
		self::assertFalse($out['verified']);
		self::assertArrayNotHasKey('/alice/files/Documentos/saved.pdf', $this->tree->nodes);
	}

	/**
	 * Scope storage becoming unavailable at the post-create gate comes after the file exists: the receipt is uncertain
	 * and names the empty file left, never a 503 that hides it.
	 *
	 * @return void
	 */
	public function testPolicyUnavailableAtCreationVisibilityNamesTheFileLeft(): void {
		$this->tree->afterCreate = function (): void {
			$this->creationPolicyFailure = new PolicyUnavailable();
		};
		$out = $this->saveFile();
		self::assertSame(['uncertain', false], [$out['outcome'], $out['verified']]);
		self::assertSame(\OCA\Mcp\Modules\Files\Presentation\CreationMessages::createLeftBehind(null, '/Documentos/saved.pdf'), $out['message']);
		self::assertSame($this->tree->nodes['/alice/files/Documentos/saved.pdf']['id'], $out['fileId']);
		self::assertSame('', $this->tree->nodes['/alice/files/Documentos/saved.pdf']['content']);
		self::assertNotContains('write /alice/files/Documentos/saved.pdf', $this->tree->ops);
	}

	/**
	 * Unexpected failure while resolving the created node is uncertain, with safe diagnostics from the creation, which
	 * now owns the whole window after the file exists.
	 *
	 * @return void
	 */
	public function testUnexpectedGetAfterCreationReturnsUncertainAndLogsClassAndCode(): void {
		$this->tree->afterCreate = function (): void {
			$this->tree->beforeGet = static fn () => throw new \RuntimeException('private filename', 17);
		};
		$this->creationLogger->expects($this->once())->method('error')->with(
			'MCP new file write failed: {exception_class} (code {exception_code})',
			['app' => 'mcp', 'exception_class' => \RuntimeException::class, 'exception_code' => 17],
		);
		$this->destinationLogger->expects($this->never())->method('error');
		$out = $this->saveFile();
		self::assertSame('uncertain', $out['outcome']);
		self::assertFalse($out['verified']);
		self::assertSame('', $this->tree->nodes['/alice/files/Documentos/saved.pdf']['content']);
		self::assertNotContains('write /alice/files/Documentos/saved.pdf', $this->tree->ops);
	}

	/** The prepared limit already reserves the decoded allocation; the sink must not count it twice. */
	public function testSinkUsesTheBudgetCapturedBeforeTheFetch(): void {
		$prepared = $this->save->prepare(self::ARGS, 'alice', true);
		$this->config->app['mcp']['checkout_max_bytes'] = '1';
		$out = $this->save->save($prepared, self::ARGS, 'alice');
		self::assertTrue($out['verified']);
		self::assertSame($prepared['limit'], $out['effectiveMaxBytes']);
	}
	public function testBudgetCoversEveryMeasuredPeak(): void {
		$measurements = [[262144,4194304],[2097152,9191424],[2097152,11157504],[8388608,21987328],[8388608,29851648]];
		foreach ($measurements as [$bytes,$delta]) {
			self::assertLessThanOrEqual(MailSaveBudget::RESERVE + $bytes * MailSaveBudget::MEMORY_COPIES, $delta);
		}
		self::assertSame(16*1048576, MailSaveBudget::calculate(50*1048576, '-1', 900*1048576));
		self::assertSame(0, MailSaveBudget::calculate(50*1048576, '32M', 20*1048576));
		self::assertSame(123, MailSaveBudget::calculate(123, '128M', 8*1048576));
	}
}
