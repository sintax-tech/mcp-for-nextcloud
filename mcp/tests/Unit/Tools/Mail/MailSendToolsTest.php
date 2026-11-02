<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Modules\Mail\Integration\MailGateway;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;
use OCA\Mcp\Modules\Mail\Presentation\MailSendMessages;
use OCA\Mcp\Modules\Mail\Presentation\MailSendPlanRenderer;
use OCA\Mcp\Modules\Mail\Sending\MailSendTools;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\App\IAppManager;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * mail_send_message against a Mail app in memory: the plan writes nothing to the Outbox and shows the message, the
 * account and its Sent folder as far as the user may read them; a confirmed call needs the plan's state, resolves the
 * account, its Sent folder and its sender again, saves one outgoing message without a send time to the Outbox and sends
 * it once through the Mail app's send chain, then reads the recorded status without claiming more than it says. Another
 * user's account, an account without Sent, a changed plan or sender and an invalid message never reach the Outbox.
 */
final class MailSendToolsTest extends TestCase {
	/** The tool. */
	private const TOOL = 'mail_send_message';

	/** The message of most tests. */
	private const MAIL = ['account_id' => 11, 'to' => ['bob@example.com'], 'cc' => ['carol@example.com'], 'bcc' => ['dave@example.com'],
		'subject' => 'Budget review', 'body' => "Hello Bob,\n\nthe numbers are in Files.\n"];

	/** The app in memory. */
	private FakeMailMessageApp $app;

	/** The grants of alice. */
	private GrantPolicy $policy;

	/** The installed Mail version the app manager reports. */
	private string $mailVersion = '5.12.5';

	/**
	 * Alice owns account 11 with INBOX (1) and Sent (4), the folder its settings name, and account 12 without Sent; bob
	 * owns account 22 with Sent (9).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->app = (new FakeMailMessageApp())
			->withAccount(11, 'alice', 'Work', 'alice@example.com')
			->withAccount(12, 'alice', 'Home', 'alice@home.example')
			->withAccount(22, 'bob', 'Bob', 'bob@example.com');
		$this->app->accountSettings = [11 => ['sent' => 4], 22 => ['sent' => 9]];
		$this->app->withFolder(11, ['id' => 1, 'name' => 'INBOX'])->withFolder(11, ['id' => 4, 'name' => 'Sent'])
			->withFolder(12, ['id' => 6, 'name' => 'INBOX'])->withFolder(22, ['id' => 9, 'name' => 'Sent']);
		$this->policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
	}

	/**
	 * Without confirm nothing reaches the Outbox; the plan shows the whole message, the sender and the Sent folder with
	 * mail.read, says it may leave the organization, cannot be undone, that "sent" is acceptance and that each
	 * confirmation sends again; without mail.read the sender address and the folder path are left out and a note says
	 * the account's own address is used.
	 *
	 * @return void
	 */
	public function testThePlanSendsNothingAndShowsTheMessage(): void {
		$plan = $this->tools()->preview(self::TOOL, self::MAIL, 'alice');
		$this->assertNotContains('outbox:save', $this->app->journal->getArrayCopy());
		$this->assertSame([], $this->app->outbox);
		$this->assertSame(['accountId' => 11, 'sentMailboxId' => 4, 'from' => 'alice@example.com', 'fromName' => 'Work', 'sentPath' => 'Sent'], $plan['target']);
		$this->assertSame(['to' => ['bob@example.com'], 'cc' => ['carol@example.com'], 'bcc' => ['dave@example.com'], 'subject' => 'Budget review',
			'body' => self::MAIL['body']], $plan['mail']);
		$this->assertSame([MailSendMessages::mayLeave(), MailSendMessages::acceptedNotDelivered(), MailSendMessages::eachConfirmSends()], $plan['notes']);
		$this->assertStringContainsString('may go to recipients outside', $plan['notes'][0]);

		$rendered = (string)(new MailSendPlanRenderer())->render($plan);
		foreach (['**' . MailSendMessages::mayLeave(), 'Send from alice@example.com', '- To: bob@example.com', '- Cc: carol@example.com', '- Bcc: dave@example.com', '**Budget review**', '> Hello Bob,'] as $shown) {
			$this->assertStringContainsString($shown, $rendered);
		}
		// Sender and recipients are inert inline text (PlanText::inline), never a code span.
		$this->assertStringNotContainsString('`', $rendered);

		$this->policy->setGrant('alice', 'mail', 'read', false);
		$plan = $this->tools()->preview(self::TOOL, self::MAIL, 'alice');
		$this->assertSame(['accountId' => 11, 'sentMailboxId' => 4], $plan['target']);
		$this->assertContains(MailSendMessages::fromOwnAddress(), $plan['notes']);
		$this->assertStringNotContainsString('alice@example.com', (string)json_encode($plan));
	}

	/**
	 * The confirmed call saves exactly the planned message once, outgoing, without a send time, with its recipients by
	 * field and no attachment, and sends it once; an accepted, copied message is "sent" with its Message-ID and no Outbox
	 * id, since the Mail app removed it from the Outbox.
	 *
	 * @return void
	 */
	public function testAConfirmedCallSavesAndSendsThePlannedMessageOnce(): void {
		$out = $this->confirm(self::MAIL);
		$this->assertSame(['outbox:save', 'outbox:send'], array_values(array_filter($this->app->journal->getArrayCopy(), static fn (string $c): bool => str_starts_with($c, 'outbox:'))));
		$this->assertSame([['id' => 700, 'type' => 0, 'accountId' => 11, 'subject' => 'Budget review', 'body' => self::MAIL['body'], 'sendAt' => null,
			'to' => [['email' => 'bob@example.com', 'label' => 'bob@example.com']], 'cc' => [['email' => 'carol@example.com', 'label' => 'carol@example.com']],
			'bcc' => [['email' => 'dave@example.com', 'label' => 'dave@example.com']], 'attachments' => []]], $this->app->outbox);
		$this->assertSame(['accountId' => 11, 'outcome' => 'sent', 'sent' => true, 'copiedToSent' => true, 'message' => MailSendMessages::sent(),
			'messageIdHeader' => '<sent-700@example.com>'], $out);
	}

	/**
	 * Each recorded status says only what the Mail app knows: accepted but not copied keeps the Outbox id; a definite
	 * SMTP failure is refused; no Sent folder is not sent; an error, a raw status, no status, an unknown status, and
	 * an accepted status without a built message are all uncertain.
	 *
	 * @return void
	 */
	public function testEachRecordedStatusSaysOnlyWhatTheMailAppKnows(): void {
		$cases = [
			[['status' => 11, 'built' => true], 'sentNotCopied', true, false],
			[['status' => 10, 'built' => false], 'refused', false, false],
			[['status' => 1, 'built' => false], 'notSent', false, false],
			[['status' => 13, 'built' => true], 'uncertain', null, null],
			[['status' => 0, 'built' => false], 'uncertain', null, null],
			[['status' => null, 'built' => false], 'uncertain', null, null],
			[['status' => 8, 'built' => true], 'uncertain', null, null],
			[['status' => 12, 'built' => false], 'uncertain', null, null],
			[['status' => 11, 'built' => false], 'uncertain', null, null],
		];
		foreach ($cases as [$outcome, $expected, $sent, $copied]) {
			$this->app->sendOutcome = $outcome;
			$out = $this->confirm(self::MAIL);
			$label = json_encode($outcome);
			$this->assertSame([$expected, $sent, $copied], [$out['outcome'], $out['sent'], $out['copiedToSent']], $label);
			$this->assertSame(end($this->app->outbox)['id'], $out['outboxId'], "$label: the message stays in the Outbox");
			// Only an accepted, built message has a Message-ID worth telling.
			$this->assertSame($sent === true, isset($out['messageIdHeader']), $label);
		}
		$this->assertSame(MailSendMessages::uncertain(), $out['message']);
	}

	/**
	 * A failing save comes before any send: not sent, and no send is asked; a failing send may come after the SMTP server
	 * accepted the message: uncertain. Neither shows the exception's text.
	 *
	 * @return void
	 */
	public function testAFailingSaveIsNotSentAndAFailingSendIsUncertain(): void {
		$this->app->sendOutcome = 'save';
		$this->assertSame(MailSendMessages::notSent(), $this->failure(self::MAIL));
		$this->assertNotContains('outbox:send', $this->app->journal->getArrayCopy());

		$this->app->sendOutcome = 'send';
		$failure = $this->failure(self::MAIL);
		$this->assertSame(MailSendMessages::uncertain(), $failure);
		$this->assertStringNotContainsString('secret', $failure);
		$this->assertSame(1, count(array_filter($this->app->journal->getArrayCopy(), static fn (string $c): bool => $c === 'outbox:send')), 'never sent again');
	}

	/**
	 * Each confirmed call sends again: confirming the same plan twice saves and sends two messages. There is no receipt
	 * and no deduplication of its own.
	 *
	 * @return void
	 */
	public function testConfirmingTheSamePlanTwiceSendsTwice(): void {
		$state = $this->tools()->preview(self::TOOL, self::MAIL, 'alice')[PlanState::ARGUMENT];
		$arguments = self::MAIL + ['confirm' => true, PlanState::ARGUMENT => $state];
		$this->tools()->call(self::TOOL, $arguments, 'alice');
		$this->tools()->call(self::TOOL, $arguments, 'alice');
		$this->assertCount(2, $this->app->outbox);
	}

	/**
	 * Another user's account and a missing one answer the same; an own account without a Sent folder, or naming a folder
	 * of another account or user, is refused; nothing reaches the Outbox.
	 *
	 * @return void
	 */
	public function testForeignAccountsAndAccountsWithoutSentAreRefused(): void {
		$this->assertSame(MailMessages::accountNotFound(), $this->planFailure(['account_id' => 22] + self::MAIL));
		$this->assertSame(MailMessages::accountNotFound(), $this->planFailure(['account_id' => 98765] + self::MAIL));
		$this->assertSame(MailSendMessages::noSentFolder(), $this->planFailure(['account_id' => 12] + self::MAIL));
		$this->app->accountSettings[12] = ['sent' => 4];
		$this->assertSame(MailSendMessages::noSentFolder(), $this->planFailure(['account_id' => 12] + self::MAIL), 'a Sent folder of another account');
		$this->app->accountSettings[12] = ['sent' => 9];
		$this->assertSame(MailSendMessages::noSentFolder(), $this->planFailure(['account_id' => 12] + self::MAIL), 'a folder of another user');
		$this->assertSame(MailMessages::accountNotFound(), $this->failure(['account_id' => 22, PlanState::ARGUMENT => str_repeat('a', 64)] + self::MAIL));
		$this->assertSame([], $this->app->outbox);
	}

	/**
	 * A message needs one recipient; recipients, subject and text follow the draft's rules: nothing reaches the Outbox.
	 *
	 * @return void
	 */
	public function testAnInvalidMessageIsNotPlanned(): void {
		foreach ([['account_id' => 11, 'subject' => 'No one'], ['to' => ['Bob <bob@example.com>']] + self::MAIL, ['subject' => "Hi\r\nBcc: eve@example.com"] + self::MAIL] as $bad) {
			try {
				$this->tools()->preview(self::TOOL, $bad, 'alice');
				$this->fail('accepted: ' . json_encode($bad));
			} catch (ArgumentValidationException) {
				$this->addToAssertionCount(1);
			}
		}
		try {
			$this->tools()->preview(self::TOOL, ['account_id' => 11, 'subject' => 'No one'], 'alice');
		} catch (ArgumentValidationException $e) {
			$this->assertSame(['field' => 'to', 'rule' => MailSendMessages::noRecipient()], $e->details());
		}
		$this->assertSame([], $this->app->outbox);
	}

	/**
	 * Without plan_state, with the state of another message, after the account's address changed or after its Sent
	 * folder changed, nothing is sent: the first is an argument error, the others the new plan with its warning.
	 *
	 * @return void
	 */
	public function testAConfirmedCallNeedsTheStateOfItsPlan(): void {
		try {
			$this->tools()->call(self::TOOL, self::MAIL + ['confirm' => true], 'alice');
			$this->fail('plan_state required');
		} catch (ArgumentValidationException) {
			$this->assertSame([], $this->app->outbox);
		}
		$other = $this->tools()->preview(self::TOOL, ['bcc' => []] + self::MAIL, 'alice')[PlanState::ARGUMENT];
		$this->assertPlanChanged($other, 'another recipient list');

		$state = $this->tools()->preview(self::TOOL, self::MAIL, 'alice')[PlanState::ARGUMENT];
		$this->app->accountSettings[11]['address'] = 'alice.new@example.com';
		$this->assertPlanChanged($state, 'another sending address');

		$this->app->accountSettings[11] = ['sent' => 4];
		$state = $this->tools()->preview(self::TOOL, self::MAIL, 'alice')[PlanState::ARGUMENT];
		$this->app->withFolder(11, ['id' => 5, 'name' => 'Gesendet']);
		$this->app->accountSettings[11] = ['sent' => 5];
		$this->assertPlanChanged($state, 'another Sent folder');
		$this->assertSame([], $this->app->outbox);
	}

	/**
	 * With Bcc on a Mail older than 5.10.11 the plan notes that the copy in Sent will not show the Bcc recipients, who
	 * still receive the message; with an unknown version it notes that it may not; with a current Mail or without Bcc
	 * there is no such note. Nothing is refused and no plan reaches the Outbox. The note is part of the plan state, so a
	 * Mail replaced since the plan gives the new plan.
	 *
	 * @return void
	 */
	public function testThePlanNotesWhereTheCopyInSentDropsBcc(): void {
		$bccNotes = static fn (array $plan): array => array_values(array_filter($plan['notes'], static fn (string $n): bool => str_contains($n, 'Bcc')));
		$this->mailVersion = '5.9.0';
		$old = $this->tools()->preview(self::TOOL, self::MAIL, 'alice');
		$this->assertSame([MailSendMessages::bccCopyLost('5.10.11')], $bccNotes($old));
		$this->assertStringContainsString('receive the message normally', $bccNotes($old)[0]);
		$this->assertSame([], $bccNotes($this->tools()->preview(self::TOOL, ['bcc' => []] + self::MAIL, 'alice')), 'no Bcc, no note');

		foreach (['', 'unknown', '6'] as $unreadable) {
			$this->mailVersion = $unreadable;
			$this->assertSame([MailSendMessages::bccCopyMaybeLost()], $bccNotes($this->tools()->preview(self::TOOL, self::MAIL, 'alice')), "'$unreadable'");
		}
		foreach (['5.10.11', '5.12.5'] as $current) {
			$this->mailVersion = $current;
			$this->assertSame([], $bccNotes($this->tools()->preview(self::TOOL, self::MAIL, 'alice')), $current);
		}
		$this->assertSame([], array_values(array_filter($this->app->journal->getArrayCopy(), static fn (string $c): bool => str_starts_with($c, 'outbox:'))), 'no save, no send');

		$this->mailVersion = '5.12.5';
		$state = $this->tools()->preview(self::TOOL, self::MAIL, 'alice')[PlanState::ARGUMENT];
		$this->mailVersion = '5.9.0';
		$this->assertPlanChanged($state, 'a Mail replaced by one whose copy drops Bcc');
		$this->assertSame([], $this->app->outbox);
	}

	/**
	 * Asserts a confirmed call with this state gets the new plan with its warning.
	 *
	 * @param string $state plan state of an earlier plan
	 * @param string $what what changed
	 * @return void
	 */
	private function assertPlanChanged(string $state, string $what): void {
		try {
			$this->tools()->call(self::TOOL, self::MAIL + ['confirm' => true, PlanState::ARGUMENT => $state], 'alice');
			$this->fail("new plan expected: $what");
		} catch (PlanChanged $e) {
			$this->assertSame(MailSendMessages::planChanged(), $e->plan['warnings'][0]['message'], $what);
		}
	}

	/**
	 * The confirmed answer, after a plan for the same arguments.
	 *
	 * @param array<string, mixed> $arguments arguments without confirm
	 * @return array<string, mixed> the decoded answer
	 */
	private function confirm(array $arguments): array {
		$state = $this->tools()->preview(self::TOOL, $arguments, 'alice')[PlanState::ARGUMENT];
		$result = $this->tools()->call(self::TOOL, $arguments + ['confirm' => true, PlanState::ARGUMENT => $state], 'alice');
		return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * The message of a failing plan.
	 *
	 * @param array<string, mixed> $arguments arguments without confirm
	 * @return string the failure message
	 */
	private function planFailure(array $arguments): string {
		try {
			$this->tools()->preview(self::TOOL, $arguments, 'alice');
		} catch (ToolFailure $e) {
			return $e->getMessage();
		}
		$this->fail('the plan did not fail');
	}

	/**
	 * The message of a failing confirmed call, after a plan for the same arguments when none is given.
	 *
	 * @param array<string, mixed> $arguments arguments without confirm
	 * @return string the failure message
	 */
	private function failure(array $arguments): string {
		try {
			$state = $arguments[PlanState::ARGUMENT] ?? $this->tools()->preview(self::TOOL, $arguments, 'alice')[PlanState::ARGUMENT];
			$this->tools()->call(self::TOOL, ['confirm' => true, PlanState::ARGUMENT => $state] + $arguments, 'alice');
		} catch (ToolFailure $e) {
			return $e->getMessage();
		}
		$this->fail('mail_send_message did not fail');
	}

	/**
	 * The send tool over the app in memory.
	 *
	 * @return MailSendTools the tool
	 */
	private function tools(): MailSendTools {
		$config = $this->createStub(IConfig::class);
		$config->method('getSystemValueString')->willReturn('instance-secret');
		$apps = $this->createStub(IAppManager::class);
		$apps->method('getAppVersion')->willReturnCallback(fn (): string => $this->mailVersion);
		return new MailSendTools($this->app, new MailGateway($this->app), new PlanState($config), $this->policy, $apps);
	}
}
