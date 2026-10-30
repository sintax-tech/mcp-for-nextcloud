<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Service\UserTimezone;
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
use OCA\Mcp\Tools\PlanState;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../stubs/OCA/Mail/Events/MessageFlaggedEvent.php';

/**
 * Effect contract of the Mail module (RE-01): marking one message read, frozen through the endpoint with a fake Mail
 * app whose IMAP server flags and recorded IMAP commands are the observable effect.
 */
final class MailEffectsContractTest extends TestCase {
	/** Mail account of alice. */
	private const ACCOUNT = 1;
	/** Cached INBOX of the account. */
	private const INBOX = 5;
	/** Message every flow flags. */
	private const MESSAGE = 50;
	/** Installed Mail version. */
	private const VERSION = '5.12.5';

	private FakeMailMessageApp $app;
	private EffectsGate $gate;

	/** @return void builds the Mail app and the endpoint */
	protected function setUp(): void {
		$this->app = (new FakeMailMessageApp())->withAccount(self::ACCOUNT, EffectsGate::USER, 'Work', 'alice@example.com')
			->withFolder(self::ACCOUNT, ['id' => self::INBOX, 'name' => 'INBOX'])
			->withMessage(self::INBOX, ['id' => self::MESSAGE, 'subject' => 'Orçamento']);
		$this->app->flagEvent = \OCA\Mail\Events\MessageFlaggedEvent::class;
		$this->gate = new EffectsGate($this);
		$this->gate->data()->user[EffectsGate::USER]['core']['timezone'] = 'UTC';
		$this->gate->serve(fn (): array => [$this->module()]);
	}

	/** @return MailModule the module of one request, every tool wired as the container autowires it */
	private function module(): MailModule {
		$config = $this->gate->config();
		$policy = $this->gate->policy();
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn(self::VERSION);
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

	/** @return void mail_set_flags marking a message read: plan, strict confirm, PlanChanged, effect and every refusal */
	public function testMarkingAMessageRead(): void {
		$flow = (new CriticalFlow('set-flags', 'mail_set_flags', 'mail', 'flags',
			['message_id' => self::MESSAGE, 'seen' => true],
			fn (): array => $this->state()))
			// The server recreated the folder and Mail resynchronized it under the new UIDVALIDITY: the cached UID the
			// plan showed may now name another message.
			->changing(function (): void {
				$this->app->uidValidity['INBOX'] = '8';
				$token = FakeMailMessageApp::syncToken('8');
				$this->app->folders[self::INBOX] = ['newToken' => $token, 'changedToken' => $token, 'vanishedToken' => $token]
					+ $this->app->folders[self::INBOX];
			})
			->withoutVisibility('Mail messages live in the Mail app, not in Files: no system tag or excluded folder can hide a message from MCP');
		(new FlowRunner($this, $this->gate, 'mail'))->run($flow);
	}

	/** @return array{serverFlags: list<string>, imap: list<string>} the flags the server holds and every IMAP command */
	private function state(): array {
		return ['serverFlags' => $this->app->messages[self::MESSAGE]['serverFlags'], 'imap' => $this->app->imapCommands()];
	}
}
