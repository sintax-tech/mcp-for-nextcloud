<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Platform\Time\UserTimezone;
use OCA\Mcp\Tests\Unit\Contract\Oracle\CriticalFlow;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\FlowRunner;
use OCA\Mcp\Tests\Unit\Tools\Mail\FakeMailLinks;
use OCA\Mcp\Tests\Unit\Tools\Mail\FakeMailMessageApp;
use OCA\Mcp\Tools\Mail\Draft\MailDraftTools;
use OCA\Mcp\Tools\Mail\Flag\MailFlagTools;
use OCA\Mcp\Tools\Mail\MailAttachmentTools;
use OCA\Mcp\Tools\Mail\MailGateway;
use OCA\Mcp\Tools\Mail\MailMessageTools;
use OCA\Mcp\Tools\Mail\MailModule;
use OCA\Mcp\Tools\Mail\Move\MailMoveTools;
use OCA\Mcp\Tools\Mail\Send\MailSendTools;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../stubs/OCA/Mail/Events/MessageDeletedEvent.php';

/**
 * Effect contract of the three Mail writes that leave the mailbox of alice (RE-01): sending a message through the
 * Outbox of the Mail app, saving a draft in the Drafts folder and moving a message to another folder, each frozen
 * through the endpoint with the real Mail tools over a fake Mail app whose Outbox records what was handed over (To,
 * Cc and the Bcc copy), whose IMAP server keeps the drafts appended and the messages moved, and whose account settings
 * name the Sent and Drafts folders. The refusals each tool owns are frozen in `effects/mail.writes-refusals.json`.
 */
final class MailWritesEffectsContractTest extends TestCase {
	/** Mail account of alice. */
	private const ACCOUNT = 1;
	/** Second account of alice, configured without Sent and Drafts folders. */
	private const BARE_ACCOUNT = 2;
	/** Cached INBOX of the account. */
	private const INBOX = 5;
	/** Sent folder of the account settings. */
	private const SENT = 6;
	/** Drafts folder of the account settings. */
	private const DRAFTS = 7;
	/** Folder the move flow moves to. */
	private const ARCHIVE = 8;
	/** Version of Mail installed until a refusal asks for an older one. */
	private const VERSION = '5.12.5';
	/** The message of the send and draft flows: one recipient of each kind. */
	private const MAIL = ['account_id' => self::ACCOUNT, 'to' => ['bob@example.com'], 'cc' => ['carol@example.com'],
		'bcc' => ['auditoria@example.com'], 'subject' => 'Proposta revisada', 'body' => "Olá Bob,\nsegue a proposta revisada."];

	private FakeMailMessageApp $app;
	private EffectsGate $gate;
	/** Version of Mail the app manager reports. */
	private string $version = self::VERSION;

	/** @return void builds the Mail app with its folders and messages, and the endpoint */
	protected function setUp(): void {
		$this->app = (new FakeMailMessageApp())->withAccount(self::ACCOUNT, EffectsGate::USER, 'Work', 'alice@example.com')
			->withAccount(self::BARE_ACCOUNT, EffectsGate::USER, 'Pessoal', 'alice@pessoal.example.com')
			->withFolder(self::ACCOUNT, ['id' => self::INBOX, 'name' => 'INBOX'])
			->withFolder(self::ACCOUNT, ['id' => self::SENT, 'name' => 'Sent'])
			->withFolder(self::ACCOUNT, ['id' => self::DRAFTS, 'name' => 'Drafts'])
			->withFolder(self::ACCOUNT, ['id' => self::ARCHIVE, 'name' => 'Archive'])
			->withFolder(self::ACCOUNT, ['id' => 9, 'name' => 'Enviados'])
			->withFolder(self::ACCOUNT, ['id' => 10, 'name' => 'Rascunhos'])
			->withFolder(self::ACCOUNT, ['id' => 11, 'name' => 'Clientes', 'selectable' => false])
			->withFolder(self::BARE_ACCOUNT, ['id' => 20, 'name' => 'INBOX'])
			->withMessage(self::INBOX, ['id' => 50, 'subject' => 'Orçamento'])
			->withMessage(self::INBOX, ['id' => 51, 'subject' => 'Contrato'])
			->withMessage(self::INBOX, ['id' => 52, 'subject' => 'Reunião']);
		$this->app->accountSettings[self::ACCOUNT] = ['sent' => self::SENT, 'drafts' => self::DRAFTS];
		$this->app->deleteEvent = \OCA\Mail\Events\MessageDeletedEvent::class;
		$this->gate = new EffectsGate($this);
		$this->gate->data()->user[EffectsGate::USER]['core']['timezone'] = 'UTC';
		$this->gate->serve(fn (): array => [$this->module()]);
	}

	/** @return MailModule the module of one request, every tool wired as the container autowires it */
	private function module(): MailModule {
		$config = $this->gate->config();
		$policy = $this->gate->policy();
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturnCallback(fn (): string => $this->version);
		$links = FakeMailLinks::create($this);
		$events = $this->createMock(IEventDispatcher::class);
		$timezone = new UserTimezone($config);
		return new MailModule(new MailGateway($this->app),
			new MailMessageTools($this->app, new MailGateway($this->app), $timezone, $links),
			new MailAttachmentTools($this->app, new MailGateway($this->app), $apps, $this->createMock(ITempManager::class)), $links,
			new MailFlagTools($this->app, new MailGateway($this->app), $timezone, $links, new PlanState($config), $policy, $events),
			new MailMoveTools($this->app, new MailGateway($this->app), $timezone, $links, new PlanState($config), $policy, $events),
			new MailDraftTools($this->app, new MailGateway($this->app), $links, new PlanState($config), $policy, $apps),
			new MailSendTools($this->app, new MailGateway($this->app), new PlanState($config), $policy, $apps));
	}

	/** @return void mail_send_message with To, Cc and Bcc: plan, strict confirm, PlanChanged, the Outbox send, every refusal */
	public function testSendingAMessage(): void {
		$flow = (new CriticalFlow('send-message', 'mail_send_message', 'mail', 'send', self::MAIL,
			fn (): array => ['outbox' => $this->app->outbox, 'calls' => $this->writes()]))
			// The user chose another Sent folder in Mail after the plan: the copy would not land where the plan said.
			->changing(function (): void {
				$this->app->accountSettings[self::ACCOUNT]['sent'] = 9;
			})
			->withoutVisibility('Mail messages live in the Mail app, not in Files: no system tag or excluded folder can hide an '
				. 'account or its Sent folder from MCP');
		(new FlowRunner($this, $this->gate, 'mail'))->run($flow);

		// One message handed to the Outbox and sent, with every recipient, the Bcc one included, in its own field.
		$this->assertSame(['outbox:save', 'outbox:send'], $this->writes());
		$this->assertCount(1, $this->app->outbox);
		$sent = $this->app->outbox[0];
		$this->assertSame([self::ACCOUNT, 'Proposta revisada', "Olá Bob,\nsegue a proposta revisada."], [$sent['accountId'], $sent['subject'], $sent['body']]);
		$this->assertSame([['email' => 'bob@example.com', 'label' => 'bob@example.com']], $sent['to']);
		$this->assertSame([['email' => 'carol@example.com', 'label' => 'carol@example.com']], $sent['cc']);
		$this->assertSame([['email' => 'auditoria@example.com', 'label' => 'auditoria@example.com']], $sent['bcc']);
	}

	/** @return void mail_create_draft with Bcc: plan, strict confirm, PlanChanged, the draft appended, every refusal */
	public function testSavingADraft(): void {
		$flow = (new CriticalFlow('create-draft', 'mail_create_draft', 'mail', 'draft', self::MAIL,
			fn (): array => ['drafts' => $this->app->drafts, 'calls' => $this->writes()]))
			// The user chose another Drafts folder in Mail after the plan: the draft would land elsewhere.
			->changing(function (): void {
				$this->app->accountSettings[self::ACCOUNT]['drafts'] = 10;
			})
			->withoutVisibility('Mail drafts live in the Mail app, not in Files: no system tag or excluded folder can hide an '
				. 'account or its Drafts folder from MCP');
		(new FlowRunner($this, $this->gate, 'mail'))->run($flow);

		// One draft appended to the Drafts folder the settings name now, the Bcc kept, nothing sent.
		$this->assertSame(['imap:append:Rascunhos'], $this->writes());
		$this->assertCount(1, $this->app->drafts);
		$draft = $this->app->drafts[0];
		$this->assertSame(['Rascunhos', 'bob@example.com', 'carol@example.com', 'auditoria@example.com', 'Proposta revisada'],
			[$draft['mailbox'], $draft['to'], $draft['cc'], $draft['bcc'], $draft['subject']]);
		$this->assertSame([], $this->app->outbox);
	}

	/** @return void mail_move_message to Archive: plan, strict confirm, PlanChanged, the IMAP MOVE, every refusal */
	public function testMovingAMessage(): void {
		// The next message still on the server's INBOX, so each refusal is asked with a move that would succeed now.
		$flow = (new CriticalFlow('move-message', 'mail_move_message', 'mail', 'move',
			fn (): array => ['message_id' => $this->onServer(), 'mailbox_id' => self::ARCHIVE],
			fn (): array => ['moved' => $this->app->moved, 'copied' => $this->app->copied, 'imap' => $this->app->imapCommands()]))
			// The destination folder was renamed in Mail after the plan: the folder the user approved is not this one.
			->changing(function (): void {
				$this->app->folders[self::ARCHIVE]['name'] = 'Archive 2026';
			})
			->withoutVisibility('Mail messages live in the Mail app, not in Files: no system tag or excluded folder can hide a '
				. 'message or a folder from MCP');
		(new FlowRunner($this, $this->gate, 'mail'))->run($flow);

		// Message 50 left INBOX on the server and landed in the destination, under its current name; nothing was copied.
		$this->assertSame([['id' => 50, 'mailbox' => 'Archive 2026', 'uid' => 1000]], $this->app->moved);
		$this->assertSame([], $this->app->copied);
		$this->assertFalse($this->app->messages[50]['onServer']);
		$this->assertContains('imap:move:Archive 2026', $this->app->imapCommands());
	}

	/** @return void the refusals each write owns, asked with confirm: true, write nothing */
	public function testTheRefusalsOfEachWriteWriteNothing(): void {
		$refusals = new RefusalTranscript($this, $this->gate, fn (): array => ['outbox' => $this->app->outbox,
			'drafts' => $this->app->drafts, 'moved' => $this->app->moved, 'calls' => $this->writes()]);
		$send = self::MAIL + ['confirm' => true, PlanState::ARGUMENT => 'any'];
		$refusals->refuse('send-without-plan-state', 'mail_send_message', ['confirm' => true] + self::MAIL,
			'required with confirm: true; send the plan_state of the plan');
		$refusals->refuse('send-without-recipient', 'mail_send_message', ['to' => [], 'cc' => [], 'bcc' => []] + $send,
			'A message needs at least one recipient in To, Cc or Bcc.');
		$refusals->refuse('send-from-an-account-without-sent-folder', 'mail_send_message', ['account_id' => self::BARE_ACCOUNT] + $send,
			'This mail account has no Sent folder in the Mail app');
		$refusals->refuse('send-from-another-users-account', 'mail_send_message', ['account_id' => 99] + $send, 'Mail account not found.');
		$refusals->refuse('draft-without-drafts-folder', 'mail_create_draft', ['account_id' => self::BARE_ACCOUNT] + $send,
			'This mail account has no Drafts folder in the Mail app');
		$this->version = '5.10.0';
		$refusals->refuse('draft-with-bcc-on-a-mail-that-drops-it', 'mail_create_draft', $send,
			'The Mail app installed here would save this draft without its Bcc recipients (that needs Mail 5.10.11 or later)');
		$this->version = self::VERSION;
		$move = ['message_id' => 50, 'confirm' => true, PlanState::ARGUMENT => 'any'];
		$refusals->refuse('move-to-a-folder-of-another-account', 'mail_move_message', ['mailbox_id' => 20] + $move,
			'A message can only be moved to a folder of its own mail account, as in the Mail app.');
		$refusals->refuse('move-to-a-folder-that-holds-no-messages', 'mail_move_message', ['mailbox_id' => 11] + $move,
			'This mail folder only groups other folders and holds no messages.');
		$refusals->refuse('move-to-a-missing-folder', 'mail_move_message', ['mailbox_id' => 404] + $move, 'Mail folder not found.');
		$refusals->assertGolden('mail.writes-refusals');
	}

	/** @return list<string> the writes the Mail app recorded: Outbox saves and sends, IMAP appends, moves and stores */
	private function writes(): array {
		return array_values(array_filter($this->app->journal->getArrayCopy(), static fn (string $call): bool => str_starts_with($call, 'outbox:')
			|| str_starts_with($call, 'imap:append') || str_starts_with($call, 'imap:move') || str_starts_with($call, 'imap:store')));
	}

	/** @return int the first message of INBOX the server still holds */
	private function onServer(): int {
		foreach ($this->app->messages as $id => $row) {
			if ($row['mailboxId'] === self::INBOX && ($row['onServer'] ?? true)) {
				return $id;
			}
		}
		throw new \LogicException('INBOX is empty on the server');
	}
}
