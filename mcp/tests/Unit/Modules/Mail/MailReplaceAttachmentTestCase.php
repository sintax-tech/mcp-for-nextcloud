<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Modules\Files\Backups\BackupPlan;
use OCA\Mcp\Modules\Files\Backups\FileBackup;
use OCA\Mcp\Modules\Files\Creation\FileCreation;
use OCA\Mcp\Modules\Files\Creation\ProtectedFileDestination;
use OCA\Mcp\Modules\Files\Editing\ProtectedFileReplacement;
use OCA\Mcp\Modules\Files\Editing\ReplacementReceipt;
use OCA\Mcp\Modules\Files\Writing\ContentTypeGate;
use OCA\Mcp\Modules\Files\Writing\ContentWritePlan;
use OCA\Mcp\Modules\Mail\Integration\{MailSaveReader, MailGateway, MailMessageGateway};
use OCA\Mcp\Modules\Mail\Locating\MailMessageLocator;
use OCA\Mcp\Modules\Mail\Writing\{MailSaveContext, MailSaveBudget, MailSavePreflight, SaveAttachmentHandler, SaveAttachmentPreview};
use OCA\Mcp\Platform\Backups\{BackupCopies, BackupLocation};
use OCA\Mcp\Platform\FileAccess\{ActorRoot, SharedWriteGuard};
use OCA\Mcp\Tests\Unit\Tools\Files\{FilesToolsTestCase, FilesCatalogWiring};
use OCA\Mcp\Tests\Unit\Tools\Mail\{FakeMailMessageApp, FakeMimePart};
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Shared wiring of the mail_save_attachment replace tests: the real plan, reader, authorization, Files creation and
 * Files replacement (existing FileBackup copy or the #17 suspension with native versioning) around native doubles.
 */
abstract class MailReplaceAttachmentTestCase extends FilesToolsTestCase {
	protected const TARGET = '/alice/files/Documentos/saved.pdf';
	protected const ARGS = ['message_id' => 7, 'attachment_id' => '2', 'path' => '/Documentos/saved.pdf', 'replace' => true];
	protected const BYTES = "\x00\xff\r\nnew PDF bytes\x00";
	protected FakeMailMessageApp $mail;
	protected MailSaveContext $save;
	protected LoggerInterface $replaceLogger;
	protected \OCA\Mcp\Tests\Unit\Access\FileTypes\FileTypePolicyFixture $types;

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
	protected function wire(bool $copies): void {
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
	protected function replaceFile(array $extra = []): array {
		$result = (new SaveAttachmentHandler($this->save))->handle($extra + self::ARGS + ['confirm' => true], 'alice');
		return json_decode($result['content'][0]['text'], true);
	}

	/**
	 * @param array<string,mixed> $extra Arguments added to the plan call.
	 * @return array<string,mixed> Metadata-only plan.
	 */
	protected function replacementPlan(array $extra = []): array {
		return (new SaveAttachmentPreview($this->save))->plan($extra + self::ARGS, 'alice');
	}

	/** @return list<string> Paths under every backup folder. */
	protected function copies(): array {
		return array_values(array_filter(array_keys($this->tree->nodes), static fn (string $p): bool => str_contains($p, '/MCP backups/')));
	}
}
