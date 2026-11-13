<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Files\Backups\BackupMessages;
use OCA\Mcp\Modules\Files\Backups\BackupPlan;
use OCA\Mcp\Modules\Files\Backups\FileBackup;
use OCA\Mcp\Modules\Files\Creation\FileCreation;
use OCA\Mcp\Modules\Files\Creation\ProtectedFileDestination;
use OCA\Mcp\Modules\Files\Editing\ProtectedFileReplacement;
use OCA\Mcp\Modules\Files\Editing\ReplacementReceipt;
use OCA\Mcp\Modules\Files\Presentation\EditingMessages;
use OCA\Mcp\Modules\Files\Writing\ContentTypeGate;
use OCA\Mcp\Modules\Files\Writing\ContentWritePlan;
use OCA\Mcp\Modules\Mail\Integration\{MailSaveReader, MailGateway, MailMessageGateway};
use OCA\Mcp\Modules\Mail\Locating\MailMessageLocator;
use OCA\Mcp\Modules\Mail\Presentation\{MailSaveMessages, MailSavePlanRenderer};
use OCA\Mcp\Modules\Mail\Writing\{MailSaveContext, MailSaveBudget, MailSavePreflight, SaveAttachmentHandler, SaveAttachmentPreview};
use OCA\Mcp\Platform\Backups\{BackupCopies, BackupLocation};
use OCA\Mcp\Platform\FileAccess\{ActorRoot, CommonMessages, NodeAccessInfo, SharedWriteGuard};
use OCA\Mcp\Tests\Unit\Tools\Files\{FilesToolsTestCase, FilesCatalogWiring};
use OCA\Mcp\Tests\Unit\Tools\Mail\{FakeMailMessageApp, FakeMimePart};
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * mail_save_attachment with replace: the existing FileBackup copy (or the #17 suspension with native versioning),
 * ETag, locks, type policy, backup folders, confirm_shared and the read-back of the file and of its copy.
 */
final class MailReplaceAttachmentTest extends FilesToolsTestCase {
	private const TARGET = '/alice/files/Documentos/saved.pdf';
	private const ARGS = ['message_id' => 7, 'attachment_id' => '2', 'path' => '/Documentos/saved.pdf', 'replace' => true];
	private const BYTES = "\x00\xff\r\nnew PDF bytes\x00";
	private FakeMailMessageApp $mail;
	private MailSaveContext $save;
	private LoggerInterface $replaceLogger;
	private \OCA\Mcp\Tests\Unit\Access\FileTypes\FileTypePolicyFixture $types;

	/** Builds the real plan, reader, authorization, Files creation and Files replacement around native doubles. */
	protected function setUp(): void {
		parent::setUp();
		$this->mail = (new FakeMailMessageApp())->withAccount(1, 'alice', 'Private', 'alice@example.com');
		$this->mail->withFolder(1, ['id' => 1, 'name' => 'INBOX']);
		$part = new FakeMimePart('application/octet-stream', 'original.pdf', 'attachment');
		$structure = FakeMimePart::multipart('multipart/mixed', [new FakeMimePart('text/plain'), $part]);
		$this->mail->withMessage(1, ['id' => 7, 'size' => 2000, 'structure' => $structure, 'attachmentContents' => ['2' => self::BYTES]]);
		$this->tree->addFile(self::TARGET, 'old content', 'application/pdf');
		$this->types = new \OCA\Mcp\Tests\Unit\Access\FileTypes\FileTypePolicyFixture();
		$this->replaceLogger = $this->createMock(LoggerInterface::class);
		$this->sharePolicy->setGrant('alice', 'mail', 'save', true);
		$this->wire(true);
	}

	/**
	 * @param bool $copies Administrator preference for MCP pre-write copies.
	 * @return void
	 */
	private function wire(bool $copies): void {
		$this->config->app['mcp'][BackupLocation::KEY] = json_encode(['version' => 1, 'folder' => 'MCP backups', 'reserved' => ['MCP backups', '.mcp-backups'], 'copies' => $copies]);
		$location = new BackupLocation(\OCA\Mcp\Tests\Unit\Service\BackupDocumentFixture::fromConfig($this, $this->config->appConfig($this)));
		$policy = new BackupCopies($location, $this->apps, $this->users);
		$wiring = FilesCatalogWiring::of($this->module);
		$root = $this->visibilityRoot();
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user);
		$access = $wiring->named('accessInfo');
		$locks = $wiring->named('locks');
		$visibility = new FilesAccessGuard($this->config->mock($this), $this->tagMapper, $root);
		$fileTypes = $this->types->guard($this);
		$backup = new FileBackup($this->apps, $this->users, $this->time, $this->config->mock($this), $visibility, $locks, $location, $policy);
		$creation = new FileCreation($access, new SharedWriteGuard($access), $wiring->named('checkout'), $this->filenames,
			$visibility, $this->creationLogger, $locks, $fileTypes, $location);
		$files = new ProtectedFileDestination(new ActorRoot($root, $session), $creation, new SharedWriteGuard($access),
			$access, $visibility, $this->createMock(LoggerInterface::class));
		$replacement = new ProtectedFileReplacement(
			new ActorRoot($root, $session),
			$visibility,
			new ContentWritePlan(new SharedWriteGuard($access), $access, $locks, new BackupPlan($location, $policy)),
			new SharedWriteGuard($access),
			new ContentTypeGate($fileTypes),
			$backup,
			$locks,
			new ReplacementReceipt($visibility, $access),
			$this->replaceLogger,
		);
		$apps = $this->createMock(\OCP\App\IAppManager::class);
		$apps->method('getAppVersion')->willReturn('5.10.11');
		$this->save = new MailSaveContext($this->sharePolicy, $this->mail, $apps,
			new MailMessageLocator(new MailGateway($this->mail), new MailMessageGateway($this->mail)),
			new MailSaveReader($this->mail, new MailSavePreflight()), new MailSaveBudget($this->config->mock($this)), $files, $replacement);
	}

	/**
	 * @param array<string,mixed> $extra Arguments added to the confirmed call.
	 * @return array<string,mixed> Confirmed receipt.
	 */
	private function replaceFile(array $extra = []): array {
		$result = (new SaveAttachmentHandler($this->save))->handle($extra + self::ARGS + ['confirm' => true], 'alice');
		return json_decode($result['content'][0]['text'], true);
	}

	/**
	 * @param array<string,mixed> $extra Arguments added to the plan call.
	 * @return array<string,mixed> Metadata-only plan.
	 */
	private function replacementPlan(array $extra = []): array {
		return (new SaveAttachmentPreview($this->save))->plan($extra + self::ARGS, 'alice');
	}

	/** @return list<string> Paths under every backup folder. */
	private function copies(): array {
		return array_values(array_filter(array_keys($this->tree->nodes), static fn (string $p): bool => str_contains($p, '/MCP backups/')));
	}

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

	public function testSharedFileNeedsItsOwnConfirmationBeforeTheDownload(): void {
		$this->tree->addFolder('/alice/files/Documentos', ['scope' => 'shared']);
		$this->tree->addFile(self::TARGET, 'old content', 'application/pdf', ['scope' => 'shared']);
		self::assertTrue($this->replacementPlan()['requiresSharedConfirmation']);
		self::assertTrue($this->replaceFile()['requiresConfirmation']);
		self::assertNotContains('imap:attachment:2', $this->mail->imapCommands());
		self::assertSame([], $this->tree->ops);
		self::assertTrue($this->replaceFile(['confirm_shared' => true])['verified']);
	}

	public function testUnexpectedWriteFailureIsUncertainKeepsTheCopyAndDeletesNothing(): void {
		$this->tree->writeFailure = new \RuntimeException('private filename', 17);
		$this->replaceLogger->expects($this->once())->method('error')->with(
			'MCP attachment replace write failed: {exception_class} (code {exception_code})',
			['app' => 'mcp', 'exception_class' => \RuntimeException::class, 'exception_code' => 17],
		);
		$out = $this->replaceFile();
		self::assertFalse($out['verified']);
		self::assertSame('uncertain', $out['outcome']);
		self::assertSame(MailSaveMessages::replaceUncertain(), $out['message']);
		self::assertSame('old content', $this->tree->nodes['/alice/files' . $out['backup']]['content']);
		self::assertTrue($out['backupVerified']);
		self::assertSame(1, count(array_filter($this->tree->ops, static fn (string $op): bool => $op === 'write ' . self::TARGET)));
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
