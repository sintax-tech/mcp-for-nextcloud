<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Tests\Unit\Tools\Files\{FilesToolsTestCase, FilesCatalogWiring};
use OCA\Mcp\Tests\Unit\Tools\Mail\{FakeMailMessageApp, FakeMimePart};
use OCA\Mcp\Modules\Mail\Writing\{MailSaveContext, MailSaveBudget, MailSavePreflight, SaveAttachmentHandler};
use OCA\Mcp\Modules\Mail\Integration\{MailSaveReader, MailGateway, MailMessageGateway};
use OCA\Mcp\Modules\Mail\Locating\MailMessageLocator;
use OCA\Mcp\Modules\Files\Creation\ProtectedFileDestination;
use OCA\Mcp\Platform\FileAccess\{ActorRoot, SharedWriteGuard};
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use Psr\Log\LoggerInterface;
use OCP\IUserSession;

/**
 * Shared wiring of the mail_save_attachment save tests: the real plan, reader, authorization and Files creation around
 * native API doubles, with a creation visibility gate a test can make unavailable.
 */
abstract class MailSaveAttachmentTestCase extends FilesToolsTestCase {
	protected FakeMailMessageApp $mail;
	protected MailSaveContext $save;
	protected FakeMimePart $part;
	protected LoggerInterface $destinationLogger;
	protected ?PolicyUnavailable $creationPolicyFailure = null;
	protected \OCA\Mcp\Tests\Unit\Access\FileTypes\FileTypePolicyFixture $types;
	protected const ARGS = ['message_id' => 7, 'attachment_id' => '2', 'path' => '/Documentos/saved.pdf'];

	/** Builds real plan, reader, authorization and Files creation around native API doubles. */
	protected function setUp(): void {
		parent::setUp();
		$this->mail = (new FakeMailMessageApp())->withAccount(1, 'alice', 'Private', 'alice@example.com');
		$this->mail->withFolder(1, ['id' => 1, 'name' => 'INBOX']);
		$this->part = new FakeMimePart('application/octet-stream', 'original.pdf', 'attachment');
		$this->source("\x00\xff\r\nPDF bytes\x00");
		$wiring = FilesCatalogWiring::of($this->module);
		$root = $this->visibilityRoot();
		$session = $this->createMock(IUserSession::class); $session->method('getUser')->willReturn($this->user);
		$access = $wiring->named('accessInfo');
		$this->types = new \OCA\Mcp\Tests\Unit\Access\FileTypes\FileTypePolicyFixture();
		$visibility = new FilesAccessGuard($this->config->mock($this), $this->tagMapper, $root);
		$creationVisibility = $this->getMockBuilder(FilesAccessGuard::class)
			->setConstructorArgs([$this->config->mock($this), $this->tagMapper, $root])
			->onlyMethods(['assertVisible'])->getMock();
		$creationVisibility->method('assertVisible')->willReturnCallback(function ($node) use ($visibility): void {
			if ($this->creationPolicyFailure !== null) {
				throw $this->creationPolicyFailure;
			}
			$visibility->assertVisible($node);
		});
		$this->destinationLogger = $this->createMock(LoggerInterface::class);
		$creation = new \OCA\Mcp\Modules\Files\Creation\FileCreation($access, new SharedWriteGuard($access), $wiring->named('checkout'), $this->filenames,
			$creationVisibility, $this->creationLogger, $wiring->named('locks'), $this->types->guard($this),
			new \OCA\Mcp\Platform\Backups\BackupLocation($this->createMock(\OCA\Mcp\Platform\Backups\BackupSettingsDocument::class)));
		$files = new ProtectedFileDestination(new ActorRoot($root, $session), $creation, new SharedWriteGuard($access),
			$access, new FilesAccessGuard($this->config->mock($this), $this->tagMapper, $root), $this->destinationLogger);
		$this->sharePolicy->setGrant('alice', 'mail', 'save', true);
		$apps = $this->createMock(\OCP\App\IAppManager::class); $apps->method('getAppVersion')->willReturn('5.10.11');
		$this->save = new MailSaveContext($this->sharePolicy, $this->mail, $apps,
			new MailMessageLocator(new MailGateway($this->mail), new MailMessageGateway($this->mail)),
			new MailSaveReader($this->mail, new MailSavePreflight()), new MailSaveBudget($this->config->mock($this)), $files,
			new \OCA\Mcp\Tests\Unit\Tools\Mail\MailReplacementFixture());
	}
	/** @param string $bytes Decoded source bytes. @param int $size Declared whole-message size. */
	protected function source(string $bytes, int $size = 2000): void {
		$this->mail->withMessage(1, ['id' => 7, 'size' => $size, 'structure' => FakeMimePart::multipart('multipart/mixed', [new FakeMimePart('text/plain'), $this->part]), 'attachmentContents' => ['2' => $bytes]]);
	}
	/** @return array<string,mixed> Confirmed receipt. */
	protected function saveFile(): array { return json_decode((new SaveAttachmentHandler($this->save))->handle(self::ARGS + ['confirm' => true], 'alice')['content'][0]['text'], true); }
}
