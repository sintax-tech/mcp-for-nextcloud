<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Files\Backups\{BackupPlan, FileBackup};
use OCA\Mcp\Modules\Files\Creation\{FileCreation, ProtectedFileDestination};
use OCA\Mcp\Modules\Files\Presentation\CreationMessages;
use OCA\Mcp\Modules\Files\Editing\{ProtectedFileReplacement, ReplacementReceipt};
use OCA\Mcp\Modules\Files\Writing\{ContentTypeGate, ContentWritePlan};
use OCA\Mcp\Modules\Mail\Integration\{MailSaveReader, MailGateway, MailMessageGateway};
use OCA\Mcp\Modules\Mail\Locating\MailMessageLocator;
use OCA\Mcp\Modules\Mail\Presentation\MailAttachmentMessages;
use OCA\Mcp\Modules\Mail\Writing\{MailSaveContext, MailSaveBudget, MailSavePreflight, SaveAttachmentHandler, SaveAttachmentPreview};
use OCA\Mcp\Platform\Backups\{BackupCopies, BackupLocation};
use OCA\Mcp\Platform\FileAccess\{ActorRoot, CommonMessages, SharedWriteGuard};
use OCA\Mcp\Tests\Unit\Access\FileTypes\FileTypePolicyFixture;
use OCA\Mcp\Tests\Unit\Access\ResourceScope\FilesScopes;
use OCA\Mcp\Tests\Unit\Tools\Files\{FilesToolsTestCase, FilesCatalogWiring};
use OCA\Mcp\Tests\Unit\Tools\Mail\{FakeMailMessageApp, FakeMimePart};
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * mail_save_attachment behind the Files gates it does not own: hidden tag, Files scope, an encrypted message and a
 * replacement whose pre-write copy cannot be made. Each refusal writes nothing.
 */
final class MailSaveGuardsTest extends FilesToolsTestCase {
	private const HIDDEN_TAG = '999';
	private const TARGET = '/alice/files/Documentos/saved.pdf';
	private const ARGS = ['message_id' => 7, 'attachment_id' => '2', 'path' => '/Documentos/saved.pdf'];
	private FakeMailMessageApp $mail;
	private MailSaveContext $save;
	/** @var array<string, list<string>> system tags by file id */
	private array $tagged = [];
	/** @var list<string>|null selected Files scope folder ids, null for unrestricted */
	private ?array $items = null;

	/** Builds the real plan, reader, Files creation and replacement over a tag- and scope-aware Files gate. */
	protected function setUp(): void {
		parent::setUp();
		$this->mail = (new FakeMailMessageApp())->withAccount(1, 'alice', 'Private', 'alice@example.com');
		$this->mail->withFolder(1, ['id' => 1, 'name' => 'INBOX']);
		$this->message(FakeMimePart::multipart('multipart/mixed', [
			new FakeMimePart('text/plain'), new FakeMimePart('application/pdf', 'original.pdf', 'attachment'),
		]));
		$this->tree->addFolder('/alice/files/Outros');
		$this->tree->ops = [];
		$this->sharePolicy->setGrant('alice', 'mail', 'save', true);
		$this->wire();
	}

	/**
	 * Puts message 7 in the INBOX with the given MIME structure.
	 *
	 * @param FakeMimePart $structure Whole-message structure.
	 * @return void
	 */
	private function message(FakeMimePart $structure): void {
		$this->mail->withMessage(1, ['id' => 7, 'size' => 2000, 'structure' => $structure, 'attachmentContents' => ['2' => "%PDF-1.4\x00\xff"]]);
	}

	/**
	 * Wires MailSaveContext over one FilesAccessGuard that reads {@see self::$tagged} and {@see self::$items}.
	 *
	 * @return void
	 */
	private function wire(): void {
		$tags = $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class);
		$tags->method('getTagIdsForObjects')->willReturnCallback(function (array $ids): array {
			$result = [];
			foreach ($ids as $id) {
				$result[(string)$id] = $this->tagged[(string)$id] ?? [];
			}
			return $result;
		});
		$this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode([self::HIDDEN_TAG]);
		$this->config->app['mcp'][BackupLocation::KEY] = json_encode(['version' => 1, 'folder' => 'MCP backups', 'reserved' => ['MCP backups', '.mcp-backups'], 'copies' => true]);
		$root = $this->visibilityRoot();
		$plain = new VisibilityGuard($this->config->mock($this), $tags, $root);
		$scope = FilesScopes::over($this, $plain, $root, fn (): ?array => $this->items);
		$visibility = new FilesAccessGuard($this->config->mock($this), $tags, $root, null, null, null, $scope);
		$location = new BackupLocation(\OCA\Mcp\Tests\Unit\Service\BackupDocumentFixture::fromConfig($this, $this->config->appConfig($this)));
		$policy = new BackupCopies($location, $this->apps, $this->users);
		$wiring = FilesCatalogWiring::of($this->module);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user);
		$access = $wiring->named('accessInfo');
		$locks = $wiring->named('locks');
		$types = (new FileTypePolicyFixture())->guard($this);
		$creation = new FileCreation($access, new SharedWriteGuard($access), $wiring->named('checkout'), $this->filenames,
			$visibility, $this->creationLogger, $locks, $types, $location);
		$files = new ProtectedFileDestination(new ActorRoot($root, $session), $creation, new SharedWriteGuard($access),
			$access, $visibility, $this->createMock(LoggerInterface::class));
		$replacement = new ProtectedFileReplacement(new ActorRoot($root, $session), $visibility,
			new ContentWritePlan(new SharedWriteGuard($access), $access, $locks, new BackupPlan($location, $policy)),
			new SharedWriteGuard($access), new ContentTypeGate($types),
			new FileBackup($this->apps, $this->users, $this->time, $this->config->mock($this), $visibility, $locks, $location, $policy),
			$locks, new ReplacementReceipt($visibility, $access), $this->createMock(LoggerInterface::class));
		$apps = $this->createMock(\OCP\App\IAppManager::class);
		$apps->method('getAppVersion')->willReturn('5.10.11');
		$this->save = new MailSaveContext($this->sharePolicy, $this->mail, $apps,
			new MailMessageLocator(new MailGateway($this->mail), new MailMessageGateway($this->mail)),
			new MailSaveReader($this->mail, new MailSavePreflight()), new MailSaveBudget($this->config->mock($this)), $files, $replacement);
	}

	/**
	 * Asserts that both the plan and the confirmed call refuse, without IMAP content and without any Files operation.
	 *
	 * @param array<string,mixed> $args Tool arguments.
	 * @return string The confirmed call's refusal.
	 */
	private function refusesWithoutWriting(array $args): string {
		try {
			(new SaveAttachmentPreview($this->save))->plan($args, 'alice');
			self::fail('The plan must refuse');
		} catch (ToolFailure) {
		}
		try {
			(new SaveAttachmentHandler($this->save))->handle($args + ['confirm' => true], 'alice');
			self::fail('The confirmed call must refuse');
		} catch (ToolFailure $e) {
			$message = $e->getMessage();
		}
		self::assertNotContains('imap:attachment:2', $this->mail->imapCommands());
		self::assertSame([], $this->tree->ops);
		return $message;
	}

	/**
	 * Tags a node with the administrator's hidden tag.
	 *
	 * @param string $path Node path under alice's files.
	 * @return void
	 */
	private function hide(string $path): void {
		$this->tagged[(string)$this->tree->nodes['/alice/files' . $path]['id']] = [self::HIDDEN_TAG];
	}

	/** Control: the same wiring saves when nothing is hidden or out of scope. @return void */
	public function testTheGuardedWiringSavesWhenNothingIsHidden(): void {
		$out = json_decode((new SaveAttachmentHandler($this->save))->handle(self::ARGS + ['confirm' => true], 'alice')['content'][0]['text'], true);
		self::assertTrue($out['verified']);
		self::assertSame("%PDF-1.4\x00\xff", $this->tree->nodes[self::TARGET]['content']);
	}

	/** A destination folder behind the hidden tag refuses before any content fetch. @return void */
	public function testHiddenDestinationFolderRefuses(): void {
		$this->hide('/Documentos');
		self::assertSame(CreationMessages::createFolderMissing(), $this->refusesWithoutWriting(self::ARGS));
	}

	/** A file behind the hidden tag cannot be replaced and keeps its bytes. @return void */
	public function testHiddenFileCannotBeReplaced(): void {
		$this->tree->addFile(self::TARGET, 'old content', 'application/pdf');
		$this->tree->ops = [];
		$this->hide('/Documentos/saved.pdf');
		self::assertSame(CommonMessages::notFound(), $this->refusesWithoutWriting(self::ARGS + ['replace' => true]));
		self::assertSame('old content', $this->tree->nodes[self::TARGET]['content']);
	}

	/** A destination outside the user's Files scope refuses; the selected folder still accepts. @return void */
	public function testDestinationOutsideTheFilesScopeRefuses(): void {
		$this->items = [(string)$this->tree->nodes['/alice/files/Outros']['id']];
		self::assertSame(CreationMessages::createFolderMissing(), $this->refusesWithoutWriting(self::ARGS));
		$out = json_decode((new SaveAttachmentHandler($this->save))->handle(['path' => '/Outros/saved.pdf', 'confirm' => true] + self::ARGS, 'alice')['content'][0]['text'], true);
		self::assertTrue($out['verified']);
	}

	/** PGP/MIME and S/MIME messages are refused from metadata alone. @return void */
	public function testEncryptedMessageRefuses(): void {
		foreach (['multipart/encrypted', 'application/pkcs7-mime'] as $type) {
			$this->message(FakeMimePart::multipart($type, [new FakeMimePart('application/pgp-encrypted'), new FakeMimePart('application/octet-stream', 'msg.asc', 'attachment')]));
			self::assertSame(MailAttachmentMessages::encrypted(), $this->refusesWithoutWriting(self::ARGS));
		}
	}

	/** With copies enabled, a copy that cannot be written stops the replacement before the file changes. @return void */
	public function testReplaceWithoutACopyAndWithoutSuspensionDoesNotWrite(): void {
		$this->tree->addFile(self::TARGET, 'old content', 'application/pdf');
		$this->tree->backupWriteFailure = new \OCP\Files\NotEnoughSpaceException('backup');
		try {
			(new SaveAttachmentHandler($this->save))->handle(self::ARGS + ['replace' => true, 'confirm' => true], 'alice');
			self::fail('A replacement without its copy must refuse');
		} catch (ToolFailure $e) {
			self::assertSame(CommonMessages::backupFailed(), $e->getMessage());
		}
		self::assertSame('old content', $this->tree->nodes[self::TARGET]['content']);
		self::assertNotContains('write ' . self::TARGET, $this->tree->ops);
	}
}
