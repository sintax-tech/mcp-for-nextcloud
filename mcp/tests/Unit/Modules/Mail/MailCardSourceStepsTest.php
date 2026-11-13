<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use InvalidArgumentException;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Tools\Discovery\ToolAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Mail\Integration\MailGateway;
use OCA\Mcp\Modules\Mail\MailCardSourceSteps;
use OCA\Mcp\Modules\Mail\Presentation\MailAttachmentMessages;
use OCA\Mcp\Modules\Mail\Reading\MailMessageReading;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use OCA\Mcp\Tests\Unit\Tools\Mail\{FakeMailLinks, FakeMailMessageApp, MailTestModule};
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * The source of a mail card is read by the very work of mail_read_message, behind the registry's own ToolAccess, with
 * BODY.PEEK and without any flag change, and keeps only what a card plan shows.
 */
final class MailCardSourceStepsTest extends TestCase {
	private FakeMailMessageApp $mail;
	private GrantPolicy $policy;
	private MailCardSourceSteps $source;
	/** Whether the Mail app is enabled for the actor. */
	private bool $appEnabled = true;

	/** Builds the read work of mail_read_message over one INBOX message with one listed attachment. @return void */
	protected function setUp(): void {
		$this->mail = (new FakeMailMessageApp())->withAccount(1, 'alice', 'Private', 'alice@example.com');
		$this->mail->withFolder(1, ['id' => 1, 'name' => 'INBOX']);
		$this->mail->withMessage(1, ['id' => 7, 'subject' => 'Pedido 42', 'body' => str_repeat('Corpo ', 50), 'threadRootId' => 'root-7',
			'to' => [['Alice', 'alice@example.com'], ['Bruno', 'bruno@example.com']],
			'attachmentList' => [['id' => '2', 'fileName' => 'pedido.pdf', 'mime' => 'application/pdf', 'size' => 590]]]);
		$this->policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
		$this->policy->setGrant('alice', 'mail', 'messages', true);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('5.10.11');
		$apps->method('isEnabledForUser')->willReturnCallback(fn (string $app): bool => $app === 'mail' && $this->appEnabled);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$config = $this->createStub(IConfig::class);
		$config->method('getUserValue')->willReturn('UTC');
		$reading = new MailMessageReading($this->mail, new MailGateway($this->mail), new UserTimezone($config), FakeMailLinks::create($this));
		$this->source = new MailCardSourceSteps(new ToolAccess($this->policy, $apps, $users, null, MailTestModule::capabilities($apps)), $reading);
	}

	/** The sender, subject, link, bounded excerpt and attachment metadata, without recipients or flag changes. @return void */
	public function testItReadsWhatACardPlanShowsWithPeekAndNoFlagChange(): void {
		$read = $this->source->read('alice', 7, '2', 20);

		self::assertTrue($read['untrustedMailData']);
		self::assertSame(7, $read['message']['id']);
		self::assertSame('Pedido 42', $read['message']['subject']);
		self::assertSame('sender@example.com', $read['message']['from']['addresses'][0]['email']);
		self::assertSame(FakeMailLinks::BASE . '/box/1/thread/7', $read['message']['url']);
		self::assertSame(20, mb_strlen($read['message']['body']['text']));
		self::assertTrue($read['message']['body']['truncated']);
		self::assertSame(['id' => '2', 'name' => 'pedido.pdf', 'mimeType' => 'application/pdf', 'size' => 590], $read['attachment']);
		self::assertSame(['id', 'subject', 'from', 'date', 'url', 'body'], array_keys($read['message']));
		self::assertContains('imap:body', $this->mail->imapCommands());
		self::assertSame([], array_values(array_filter($this->mail->imapCommands(), static fn (string $c): bool => str_starts_with($c, 'imap:store'))));
	}

	/** An attachment mail_read_message does not list is refused, as is a malformed attachment id before IMAP. @return void */
	public function testOnlyAListedAttachmentCanBeNamed(): void {
		try {
			$this->source->read('alice', 7, '3', 20);
			self::fail('An unlisted attachment must be refused');
		} catch (ToolFailure $e) {
			self::assertSame(MailAttachmentMessages::attachmentNotFound(), $e->getMessage());
		}
		$commands = count($this->mail->imapCommands());
		$this->expectException(ToolFailure::class);
		try {
			$this->source->read('alice', 7, '../2', 20);
		} finally {
			self::assertCount($commands, $this->mail->imapCommands());
		}
	}

	/** @return array<string, array{\Closure(self): void}> the registry gates of mail_read_message */
	public static function refusedByTheRegistry(): array {
		return [
			'mail.messages not granted' => [static function (self $test): void { $test->policy->setGrant('alice', 'mail', 'messages', false); }],
			'Mail app off for the actor' => [static function (self $test): void { $test->appEnabled = false; }],
		];
	}

	/**
	 * Without the grant or the app the read never reaches Mail.
	 *
	 * @param \Closure(self): void $deny the gate to close
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('refusedByTheRegistry')]
	public function testTheRegistryGateRefusesTheRead(\Closure $deny): void {
		$deny($this);

		try {
			$this->source->read('alice', 7, '2', 20);
			self::fail('The read must be refused');
		} catch (ToolFailure $e) {
			self::assertStringContainsString('mail_read_message', $e->getMessage());
		}
		self::assertSame([], $this->mail->imapCommands());
	}

	/** A message of an account the actor does not own is refused like mail_read_message refuses it. @return void */
	public function testAMessageOfSomebodyElseIsRefused(): void {
		$this->mail->withAccount(2, 'bob', 'Bob', 'bob@example.com');
		$this->mail->withFolder(2, ['id' => 9, 'name' => 'INBOX']);
		$this->mail->withMessage(9, ['id' => 11, 'attachmentList' => [['id' => '2', 'fileName' => 'x.pdf', 'mime' => 'application/pdf', 'size' => 1]]]);

		$this->expectException(ToolFailure::class);
		try {
			$this->source->read('alice', 11, '2', 20);
		} finally {
			self::assertNotContains('imap:body', $this->mail->imapCommands());
		}
	}

	/** The excerpt length is bounded by the published max_chars of mail_read_message. @return void */
	public function testTheExcerptIsBoundedByThePublishedSchema(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->source->read('alice', 7, '2', 0);
	}
}
