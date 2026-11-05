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
use OCA\Mcp\Tests\Unit\Tools\BrokenTextSamples;
use OCA\Mcp\Tests\Unit\Tools\Mail\FakeMailLinks;
use OCA\Mcp\Tests\Unit\Tools\Mail\FakeMailMessageApp;
use OCA\Mcp\Tests\Unit\Tools\Mail\FakeMimePart;
use OCA\Mcp\Modules\Mail\Creation\MailDraftTools;
use OCA\Mcp\Modules\Mail\Editing\MailFlagTools;
use OCA\Mcp\Modules\Mail\Reading\MailAttachmentTools;
use OCA\Mcp\Modules\Mail\Integration\MailGateway;
use OCA\Mcp\Modules\Mail\Reading\MailMessageTools;
use OCA\Mcp\Modules\Mail\MailModule;
use OCA\Mcp\Modules\Mail\Moving\MailMoveTools;
use OCA\Mcp\Modules\Mail\Sending\MailSendTools;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../stubs/OCA/Mail/Events/MessageFlaggedEvent.php';

/**
 * Effect contracts of Mail (RE-01): marking a message read and extracting a PDF attachment, through the endpoint
 * with real tools and parsers over a fake Mail app whose messages, flags and IMAP commands are observable.
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
		$this->gate->serve(fn (): array => [$this->module()], fn () => \OCA\Mcp\Tests\Unit\Tools\Mail\MailTestModule::capabilities($this->mailApps()));
	}

	/** @return MailModule the module of one request, every tool wired as the container autowires it */
	private function module(): MailModule {
		$config = $this->gate->config();
		$policy = $this->gate->policy();
		$apps = $this->mailApps();
		$links = FakeMailLinks::create($this);
		$events = $this->createMock(IEventDispatcher::class);
		$timezone = new UserTimezone($config);
		return \OCA\Mcp\Tests\Unit\Tools\Mail\MailTestModule::build($this->app, $timezone, $links, new PlanState($config), $policy, $apps, $this->createMock(ITempManager::class), $events);
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

	/** @return void real PDF extraction preserves a CESU-8 surrogate pair without changing the message or flags */
	public function testReadingAPdfAttachmentWithRealExtraction(): void {
		$pdf = BrokenTextSamples::pdf();
		$this->app->withMessage(self::INBOX, ['id' => self::MESSAGE, 'attachments' => true,
			'structure' => FakeMimePart::multipart('multipart/mixed', [new FakeMimePart('text/plain'),
				new FakeMimePart('application/octet-stream', 'emoji.bin', 'attachment')]),
			'attachmentContents' => ['2' => $pdf]]);
		$before = $this->app->messages;
		$args = ['message_id' => self::MESSAGE, 'attachment_id' => '2'];
		$out = $this->gate->call('mail_read_attachment', $args);
		$t = new EffectTranscript($this, 'mail.read-pdf-utf8');
		$t->record('read-pdf', $args, $out, $this->state() + ['sourceSha256' => hash('sha256', $pdf)]);
		$t->assertGolden();
		$this->assertSame($before, $this->app->messages, 'reading never changes the cached message, attachment or flags');
		$body = json_decode($out['body']->result->content[0]->text, true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(BrokenTextSamples::PDF_TEXT, $body['attachment']['text']);
		$this->assertSame('pdf', $body['attachment']['kind']);
		$this->assertSame(['imap:status', 'imap:fetch:envelope,size,structure', 'imap:status(selected)',
			'imap:attachment:2', 'imap:logout'], $this->app->imapCommands());
	}

	/** @return array{serverFlags: list<string>, imap: list<string>} the flags the server holds and every IMAP command */
	private function state(): array {
		return ['serverFlags' => $this->app->messages[self::MESSAGE]['serverFlags'], 'imap' => $this->app->imapCommands()];
	}
	private function mailApps(): IAppManager {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn(self::VERSION);
		return $apps;
	}

}
