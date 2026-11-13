<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Mcp\Tools\Discovery\ToolAccess;
use OCA\Mcp\Modules\Files\Creation\{FileCreation, ProtectedFileDestination};
use OCA\Mcp\Modules\Mail\Integration\{MailSaveReader, MailGateway, MailMessageGateway};
use OCA\Mcp\Modules\Mail\Locating\MailMessageLocator;
use OCA\Mcp\Modules\Mail\MailAttachmentSaveSteps;
use OCA\Mcp\Modules\Mail\Writing\{MailSaveContext, MailSaveBudget, MailSavePreflight, SaveAttachmentHandler, SaveAttachmentPreview};
use OCA\Mcp\Platform\Backups\BackupLocation;
use OCA\Mcp\Platform\FileAccess\{ActorRoot, SharedWriteGuard};
use OCA\Mcp\Platform\Workflows\WorkflowStep;
use OCA\Mcp\Tests\Unit\Access\FileTypes\FileTypePolicyFixture;
use OCA\Mcp\Tests\Unit\Access\ResourceScope\FilesScopes;
use OCA\Mcp\Tests\Unit\Tools\Files\{FilesToolsTestCase, FilesCatalogWiring};
use OCA\Mcp\Tests\Unit\Tools\Mail\{FakeMailMessageApp, FakeMimePart, MailReplacementFixture, MailTestModule};
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Shared wiring of the mail_save_attachment port tests: the real plan, reader, authorization and Files creation, the
 * registry gate of the tool, a Files scope a test can narrow, and the effect of the tool itself to compare with.
 */
abstract class MailAttachmentSaveStepsTestCase extends FilesToolsTestCase {
	protected const BYTES = "%PDF-1.4\x00\xff";
	protected const TARGET = '/alice/files/Documentos/saved.pdf';
	protected FakeMailMessageApp $mail;
	protected MailAttachmentSaveSteps $steps;
	/** The effect of mail_save_attachment itself, over the same wiring, to compare its answer with the step's. */
	protected SaveAttachmentHandler $original;
	/** Whether the Mail app is enabled for the actor. */
	protected bool $appEnabled = true;
	/** @var list<string>|null selected Files scope folder ids, null for unrestricted */
	protected ?array $items = null;

	/** Builds the real plan, reader, authorization and Files creation, and the registry gate of mail_save_attachment. @return void */
	protected function setUp(): void {
		parent::setUp();
		$this->items = null;
		$this->mail = (new FakeMailMessageApp())->withAccount(1, 'alice', 'Private', 'alice@example.com');
		$this->mail->withFolder(1, ['id' => 1, 'name' => 'INBOX']);
		$structure = FakeMimePart::multipart('multipart/mixed', [new FakeMimePart('text/plain'), new FakeMimePart('application/pdf', 'original.pdf', 'attachment')]);
		$this->mail->withMessage(1, ['id' => 7, 'size' => 2000, 'structure' => $structure, 'attachmentContents' => ['2' => self::BYTES]]);
		$this->tree->addFolder('/alice/files/Outros');
		$this->tree->addFolder('/alice/files/Leitura', ['permissions' => \OCP\Constants::PERMISSION_READ]);
		$this->tree->ops = [];
		$this->sharePolicy->setGrant('alice', 'mail', 'save', true);
		$apps = $this->createMock(\OCP\App\IAppManager::class);
		$apps->method('getAppVersion')->willReturn('5.10.11');
		$apps->method('isEnabledForUser')->willReturnCallback(fn (string $app): bool => $app === 'mail' && $this->appEnabled);
		$users = $this->createMock(\OCP\IUserManager::class);
		$users->method('get')->willReturn($this->createMock(\OCP\IUser::class));
		$access = new ToolAccess($this->sharePolicy, $apps, $users, null, MailTestModule::capabilities($apps));
		$save = $this->context($apps);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(static fn (string $route, array $parameters): string => 'https://cloud.example/' . $route . '/' . $parameters['fileid']);
		$this->original = new SaveAttachmentHandler($save);
		$this->steps = new MailAttachmentSaveSteps($access, new SaveAttachmentPreview($save), $this->original, $urls, new NullLogger());
	}

	/**
	 * The save work of mail_save_attachment over a Files gate that reads {@see self::$items} as the Files scope.
	 *
	 * @param \OCP\App\IAppManager $apps installed Mail version
	 * @return MailSaveContext the authorization, reader and protected Files creation; replacement is never reachable
	 */
	protected function context(\OCP\App\IAppManager $apps): MailSaveContext {
		$root = $this->visibilityRoot();
		$plain = new VisibilityGuard($this->config->mock($this), $this->tagMapper, $root);
		$scope = FilesScopes::over($this, $plain, $root, fn (): ?array => $this->items);
		$visibility = new FilesAccessGuard($this->config->mock($this), $this->tagMapper, $root, null, null, null, $scope);
		$wiring = FilesCatalogWiring::of($this->module);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user);
		$access = $wiring->named('accessInfo');
		$creation = new FileCreation($access, new SharedWriteGuard($access), $wiring->named('checkout'), $this->filenames, $visibility,
			$this->creationLogger, $wiring->named('locks'), (new FileTypePolicyFixture())->guard($this),
			new BackupLocation($this->createMock(\OCA\Mcp\Platform\Backups\BackupSettingsDocument::class)));
		$files = new ProtectedFileDestination(new ActorRoot($root, $session), $creation, new SharedWriteGuard($access), $access, $visibility,
			$this->createMock(LoggerInterface::class));
		return new MailSaveContext($this->sharePolicy, $this->mail, $apps,
			new MailMessageLocator(new MailGateway($this->mail), new MailMessageGateway($this->mail)),
			new MailSaveReader($this->mail, new MailSavePreflight()), new MailSaveBudget($this->config->mock($this)), $files,
			new MailReplacementFixture());
	}

	/**
	 * Asserts that a refused step wrote nothing and never fetched the attachment content.
	 *
	 * @param WorkflowStep $step the step answered
	 * @return string the refusal, written for the user
	 */
	protected function failedWithoutWriting(WorkflowStep $step): string {
		self::assertSame(WorkflowStep::FAILED, $step->status);
		self::assertSame([], $step->ids);
		self::assertNotContains('imap:attachment:2', $this->mail->imapCommands());
		self::assertSame([], $this->tree->ops);
		return (string)$step->message;
	}

	/**
	 * Makes the first read-back of the new file, after Nextcloud created it and before its content, raise once.
	 *
	 * @param \Throwable $failure what the read-back raises
	 * @return void
	 */
	protected function failReadBack(\Throwable $failure): void {
		$this->tree->afterCreate = function () use ($failure): void {
			$this->tree->beforeGet = function (string $path) use ($failure): void {
				if ($path === self::TARGET) {
					$this->tree->beforeGet = null;
					throw $failure;
				}
			};
		};
	}

}
