<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use InvalidArgumentException;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Mcp\Tools\Discovery\ToolAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Files\Creation\{FileCreation, ProtectedFileDestination};
use OCA\Mcp\Modules\Mail\Integration\{MailSaveReader, MailGateway, MailMessageGateway};
use OCA\Mcp\Modules\Mail\Locating\MailMessageLocator;
use OCA\Mcp\Modules\Mail\MailAttachmentSaveSteps;
use OCA\Mcp\Modules\Mail\Presentation\MailSaveMessages;
use OCA\Mcp\Modules\Mail\Writing\{MailSaveContext, MailSaveBudget, MailSavePreflight, SaveAttachmentHandler, SaveAttachmentPreview};
use OCA\Mcp\Platform\Backups\BackupLocation;
use OCA\Mcp\Platform\FileAccess\{ActorRoot, SharedWriteGuard};
use OCA\Mcp\Platform\MailCards\MailCardStep;
use OCA\Mcp\Tests\Unit\Access\FileTypes\FileTypePolicyFixture;
use OCA\Mcp\Tests\Unit\Access\ResourceScope\FilesScopes;
use OCA\Mcp\Tests\Unit\Tools\Files\{FilesToolsTestCase, FilesCatalogWiring};
use OCA\Mcp\Tests\Unit\Tools\Mail\{FakeMailMessageApp, FakeMimePart, MailReplacementFixture, MailTestModule};
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The attachment step of a mail card goes through the very plan and effect of mail_save_attachment, behind the
 * registry's own ToolAccess, and only ever creates a new file: an occupied name is refused and keeps its bytes.
 */
final class MailAttachmentSaveStepsTest extends FilesToolsTestCase {
	private const BYTES = "%PDF-1.4\x00\xff";
	private const TARGET = '/alice/files/Documentos/saved.pdf';
	private FakeMailMessageApp $mail;
	private MailAttachmentSaveSteps $steps;
	/** Whether the Mail app is enabled for the actor. */
	private bool $appEnabled = true;
	/** @var list<string>|null selected Files scope folder ids, null for unrestricted */
	private ?array $items = null;

	/** Builds the real plan, reader, authorization and Files creation, and the registry gate of mail_save_attachment. @return void */
	protected function setUp(): void {
		parent::setUp();
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
		$this->steps = new MailAttachmentSaveSteps($access, new SaveAttachmentPreview($save), new SaveAttachmentHandler($save), $urls, new NullLogger());
	}

	/**
	 * The save work of mail_save_attachment over a Files gate that reads {@see self::$items} as the Files scope.
	 *
	 * @param \OCP\App\IAppManager $apps installed Mail version
	 * @return MailSaveContext the authorization, reader and protected Files creation; replacement is never reachable
	 */
	private function context(\OCP\App\IAppManager $apps): MailSaveContext {
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
	 * @param MailCardStep $step the step answered
	 * @return string the refusal, written for the user
	 */
	private function failedWithoutWriting(MailCardStep $step): string {
		self::assertSame(MailCardStep::FAILED, $step->status);
		self::assertSame([], $step->ids);
		self::assertNotContains('imap:attachment:2', $this->mail->imapCommands());
		self::assertSame([], $this->tree->ops);
		return (string)$step->message;
	}

	/** The plan is the metadata-only plan of mail_save_attachment for a new file. @return void */
	public function testThePlanIsTheOneOfMailSaveAttachmentAndWritesNothing(): void {
		$plan = $this->steps->planSave('alice', 7, '2', '/Documentos/saved.pdf', false);

		self::assertSame('mail_save_attachment', $plan['action']);
		self::assertFalse($plan['replace']);
		self::assertSame('/Documentos/saved.pdf', $plan['path']);
		self::assertSame([], $this->tree->ops);
		self::assertNotContains('imap:attachment:2', $this->mail->imapCommands());
	}

	/** The confirmed step saves the bytes once and answers the read-back file with its private link. @return void */
	public function testTheConfirmedStepSavesANewFileAndReadsItBack(): void {
		$step = $this->steps->save('alice', 7, '2', '/Documentos/saved.pdf', false);

		self::assertSame(MailCardStep::APPLIED, $step->status, (string)$step->message);
		self::assertSame(self::BYTES, $this->tree->nodes[self::TARGET]['content']);
		self::assertSame(['path' => '/Documentos/saved.pdf', 'fileId' => $this->tree->nodes[self::TARGET]['id']], $step->ids);
		self::assertSame('https://cloud.example/files.View.showFile/' . $step->ids['fileId'], $step->result['url']);
		self::assertSame(hash('sha256', self::BYTES), $step->result['sha256']);
		self::assertSame(MailSaveMessages::saved(), $step->result['message']);
	}

	/** An occupied name is refused before the download and the existing file keeps its bytes: there is no replacement. @return void */
	public function testAnOccupiedNameIsRefusedAndNeverOverwritten(): void {
		$this->tree->addFile(self::TARGET, 'old content', 'application/pdf');
		$this->tree->ops = [];

		self::assertSame(MailSaveMessages::occupied(), $this->failedWithoutWriting($this->steps->save('alice', 7, '2', '/Documentos/saved.pdf', false)));
		self::assertSame('old content', $this->tree->nodes[self::TARGET]['content']);
		$this->expectException(ToolFailure::class);
		$this->steps->planSave('alice', 7, '2', '/Documentos/saved.pdf', false);
	}

	/** @return array<string, array{\Closure(self): void}> the registry gates of mail_save_attachment */
	public static function refusedByTheRegistry(): array {
		return [
			'mail.save not granted' => [static function (self $test): void { $test->sharePolicy->setGrant('alice', 'mail', 'save', false); }],
			'Mail app off for the actor' => [static function (self $test): void { $test->appEnabled = false; }],
		];
	}

	/**
	 * Without the grant or the app, neither the plan nor the step reaches Mail or Files.
	 *
	 * @param \Closure(self): void $deny the gate to close
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('refusedByTheRegistry')]
	public function testTheRegistryGateRefusesBothThePlanAndTheStep(\Closure $deny): void {
		$deny($this);

		self::assertStringContainsString('mail_save_attachment', $this->failedWithoutWriting($this->steps->save('alice', 7, '2', '/Documentos/saved.pdf', false)));
		self::assertSame([], $this->mail->imapCommands());
		$this->expectException(ToolFailure::class);
		$this->steps->planSave('alice', 7, '2', '/Documentos/saved.pdf', false);
	}

	/** A destination outside the actor's Files scope is refused; the selected folder still accepts. @return void */
	public function testADestinationOutsideTheFilesScopeIsRefused(): void {
		$this->items = [(string)$this->tree->nodes['/alice/files/Outros']['id']];

		$this->failedWithoutWriting($this->steps->save('alice', 7, '2', '/Documentos/saved.pdf', false));
		self::assertSame(MailCardStep::APPLIED, $this->steps->save('alice', 7, '2', '/Outros/saved.pdf', false)->status);
	}

	/** A folder the native ACL does not let the actor add to is refused with nothing written. @return void */
	public function testAFolderWithoutCreatePermissionIsRefusedByItsAcl(): void {
		$this->failedWithoutWriting($this->steps->save('alice', 7, '2', '/Leitura/saved.pdf', false));
		self::assertArrayNotHasKey('/alice/files/Leitura/saved.pdf', $this->tree->nodes);
	}

	/** The arguments are checked against the published schema of mail_save_attachment before anything. @return void */
	public function testArgumentsAreCheckedAgainstThePublishedSchemaBeforeAnything(): void {
		$this->expectException(InvalidArgumentException::class);
		try {
			$this->steps->save('alice', 0, '2', '/Documentos/saved.pdf', false);
		} finally {
			self::assertSame([], $this->mail->imapCommands());
			self::assertSame([], $this->tree->ops);
		}
	}

	/** A failure of the write itself is uncertain, keeps whatever was written and is never retried. @return void */
	public function testAFailureOfTheWriteItselfIsUncertainAndNothingIsDeleted(): void {
		$this->tree->writeFailure = new \RuntimeException('native sink failure');

		$step = $this->steps->save('alice', 7, '2', '/Documentos/saved.pdf', false);

		self::assertSame(MailCardStep::UNCERTAIN, $step->status);
		self::assertSame('/Documentos/saved.pdf', $step->ids['path']);
		self::assertStringNotContainsString('delete', implode(' ', $this->tree->ops));
		self::assertSame(1, count(array_filter($this->mail->imapCommands(), static fn (string $c): bool => $c === 'imap:attachment:2')));
	}
}
