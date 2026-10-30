<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Mail\Draft\MailDraftMessages;
use OCA\Mcp\Tools\Mail\Draft\MailDraftPlanRenderer;
use OCA\Mcp\Tools\Mail\Draft\MailDraftTools;
use OCA\Mcp\Tools\Mail\MailGateway;
use OCA\Mcp\Tools\Mail\MailMessages;
use OCA\Mcp\Tools\PlanChanged;
use OCA\Mcp\Tools\PlanState;
use OCA\Mcp\Tools\ToolFailure;
use OCP\App\IAppManager;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * mail_create_draft against a Mail app in memory: the plan contacts no server and shows the draft, the account and its
 * Drafts folder as far as the user may read them; a confirmed call needs the plan's state, resolves the account and its
 * Drafts folder again and saves through the Mail app's own draft save, with no previous draft, plain text and no
 * attachment, so nothing is sent. Another user's account, an account without Drafts, a changed plan, an invalid
 * recipient and a failing save never create a draft silently; a failure after the append is uncertain, and each confirmed
 * call creates one more draft.
 */
final class MailDraftToolsTest extends TestCase {
	/** The tool. */
	private const TOOL = 'mail_create_draft';

	/** The draft of most tests. */
	private const DRAFT = ['account_id' => 11, 'to' => ['bob@example.com'], 'cc' => ['carol@example.com'], 'bcc' => ['dave@example.com'],
		'subject' => 'Budget review', 'body' => "Hello Bob,\n\nthe numbers are attached in Files.\n"];

	/** The app in memory. */
	private FakeMailMessageApp $app;

	/** The grants of alice. */
	private GrantPolicy $policy;

	/** The installed Mail version the app manager reports. */
	private string $mailVersion = '5.12.5';

	/**
	 * Alice owns account 11 with INBOX (1) and Drafts (3), the folder its settings name, and account 12 without Drafts;
	 * bob owns account 22 with Drafts (9).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->app = (new FakeMailMessageApp())
			->withAccount(11, 'alice', 'Work', 'alice@example.com')
			->withAccount(12, 'alice', 'Home', 'alice@home.example')
			->withAccount(22, 'bob', 'Bob', 'bob@example.com');
		$this->app->accountSettings = [11 => ['drafts' => 3], 22 => ['drafts' => 9]];
		$this->app->withFolder(11, ['id' => 1, 'name' => 'INBOX'])->withFolder(11, ['id' => 3, 'name' => 'Drafts'])
			->withFolder(12, ['id' => 6, 'name' => 'INBOX'])->withFolder(22, ['id' => 9, 'name' => 'Drafts']);
		$this->policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
	}

	/**
	 * Without confirm the plan contacts no server and saves nothing; it shows the whole draft, the account and its Drafts
	 * folder by id, their address and path only with mail.read, and says nothing is sent and each confirmation creates
	 * one more draft.
	 *
	 * @return void
	 */
	public function testThePlanSavesNothingAndShowsTheDraft(): void {
		$plan = $this->tools()->preview(self::TOOL, self::DRAFT, 'alice');
		$this->assertSame([], $this->app->imapCommands());
		$this->assertSame([], $this->app->drafts);
		$this->assertSame(['accountId' => 11, 'draftsMailboxId' => 3, 'account' => 'alice@example.com', 'draftsPath' => 'Drafts'], $plan['target']);
		$this->assertSame(['to' => ['bob@example.com'], 'cc' => ['carol@example.com'], 'bcc' => ['dave@example.com'], 'subject' => 'Budget review',
			'body' => self::DRAFT['body']], $plan['draft']);
		$this->assertSame([MailDraftMessages::notSent(), MailDraftMessages::eachConfirmCreates()], $plan['notes']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $plan[PlanState::ARGUMENT]);

		$rendered = (string)(new MailDraftPlanRenderer())->render($plan);
		foreach (['- To: bob@example.com', '- Cc: carol@example.com', '- Bcc: dave@example.com', '**Budget review**', '> Hello Bob,', MailDraftMessages::notSent()] as $shown) {
			$this->assertStringContainsString($shown, $rendered);
		}
		// Recipients are inert inline text (PlanText::inline), never a code span.
		$this->assertStringNotContainsString('`', $rendered);

		$this->policy->setGrant('alice', 'mail', 'read', false);
		$this->assertSame(['accountId' => 11, 'draftsMailboxId' => 3], $this->tools()->preview(self::TOOL, self::DRAFT, 'alice')['target']);
		$this->assertSame([], $this->app->imapCommands());
	}

	/**
	 * The confirmed call saves exactly the planned draft through the Mail app's draft save: Drafts of that account,
	 * comma separated recipients the Mail app parses back into the same addresses, plain text, no attachment, no previous
	 * draft to replace; the answer says it was not sent.
	 *
	 * @return void
	 */
	public function testAConfirmedCallSavesThePlannedDraftWithoutSending(): void {
		$out = $this->confirm(self::DRAFT);
		$this->assertSame(['imap:append:Drafts'], $this->app->imapCommands());
		$this->assertContains('MailTransmission::saveDraft', $this->app->journal->getArrayCopy());
		$this->assertSame([['mailbox' => 'Drafts', 'to' => 'bob@example.com', 'cc' => 'carol@example.com', 'bcc' => 'dave@example.com',
			'subject' => 'Budget review', 'body' => self::DRAFT['body'], 'attachments' => [], 'html' => false, 'previous' => null, 'uid' => 500]], $this->app->drafts);
		$this->assertSame(['created' => true, 'accountId' => 11, 'draftsMailboxId' => 3, 'draftsUrl' => FakeMailLinks::BASE . '/box/3', 'uid' => 500, 'sent' => false], $out);
	}

	/**
	 * Several recipients of a field are joined so the Mail app parses them back one by one; an empty draft (no
	 * recipient, subject or text) is still a draft.
	 *
	 * @return void
	 */
	public function testRecipientsKeepTheirOrderAndAnEmptyDraftIsADraft(): void {
		$this->confirm(['account_id' => 11, 'to' => ['bob@example.com', ' erin@example.org ']]);
		$this->confirm(['account_id' => 11]);
		$this->assertSame('bob@example.com, erin@example.org', $this->app->drafts[0]['to']);
		$this->assertSame(['', '', '', ''], [$this->app->drafts[1]['to'], $this->app->drafts[1]['cc'], $this->app->drafts[1]['subject'], $this->app->drafts[1]['body']]);
	}

	/**
	 * Each confirmed call creates one more draft: confirming the same plan twice gives two drafts. There is no receipt.
	 *
	 * @return void
	 */
	public function testConfirmingTheSamePlanTwiceCreatesTwoDrafts(): void {
		$state = $this->tools()->preview(self::TOOL, self::DRAFT, 'alice')[PlanState::ARGUMENT];
		$arguments = self::DRAFT + ['confirm' => true, PlanState::ARGUMENT => $state];
		$this->tools()->call(self::TOOL, $arguments, 'alice');
		$this->tools()->call(self::TOOL, $arguments, 'alice');
		$this->assertCount(2, $this->app->drafts);
	}

	/**
	 * Anything but one plain address per recipient is refused before any plan, so the Mail app's parser cannot turn one
	 * entry into several; a subject with a line break and a text with NUL too.
	 *
	 * @return void
	 */
	public function testOnlyPlainAddressesAndSafeTextArePlanned(): void {
		foreach (['Bob <bob@example.com>', 'bob@example.com, eve@example.com', 'bob@example.com;eve@example.com', 'undisclosed:;', 'not an address', '"x"@example.com'] as $bad) {
			try {
				$this->tools()->preview(self::TOOL, ['account_id' => 11, 'cc' => [$bad]], 'alice');
				$this->fail("$bad accepted");
			} catch (ArgumentValidationException $e) {
				$this->assertSame(['field' => 'cc', 'rule' => MailDraftMessages::invalidAddress('cc')], $e->details());
			}
		}
		foreach ([['subject' => "Hi\r\nBcc: eve@example.com"], ['body' => "a\0b"]] as $bad) {
			try {
				$this->tools()->preview(self::TOOL, ['account_id' => 11] + $bad, 'alice');
				$this->fail('accepted: ' . json_encode($bad));
			} catch (ArgumentValidationException) {
				$this->addToAssertionCount(1);
			}
		}
		$this->assertSame([], $this->app->drafts);
	}

	/**
	 * Another user's account and a missing one answer the same; an own account without a Drafts folder, or naming a
	 * folder of another account, is refused with a fixed message; none creates a draft, a folder or contacts a server.
	 *
	 * @return void
	 */
	public function testForeignAccountsAndAccountsWithoutDraftsAreRefused(): void {
		$this->assertSame(MailMessages::accountNotFound(), $this->planFailure(['account_id' => 22]));
		$this->assertSame(MailMessages::accountNotFound(), $this->planFailure(['account_id' => 98765]));
		$this->assertSame(MailDraftMessages::noDraftsFolder(), $this->planFailure(['account_id' => 12]));
		$this->app->accountSettings[12] = ['drafts' => 3];
		$this->assertSame(MailDraftMessages::noDraftsFolder(), $this->planFailure(['account_id' => 12]), 'a Drafts folder of another account');
		$this->app->accountSettings[12] = ['drafts' => 9];
		$this->assertSame(MailDraftMessages::noDraftsFolder(), $this->planFailure(['account_id' => 12]), 'a folder of another user');
		$this->assertSame(MailMessages::accountNotFound(), $this->failure(['account_id' => 22, PlanState::ARGUMENT => str_repeat('a', 64)]));
		$this->assertSame([], $this->app->imapCommands());
		$this->assertSame([], $this->app->drafts);
	}

	/**
	 * Without plan_state, or with the state of another draft, or after the Drafts folder changed, nothing is saved: the
	 * first is an argument error, the others the new plan with its warning.
	 *
	 * @return void
	 */
	public function testAConfirmedCallNeedsTheStateOfItsPlan(): void {
		try {
			$this->tools()->call(self::TOOL, self::DRAFT + ['confirm' => true], 'alice');
			$this->fail('plan_state required');
		} catch (ArgumentValidationException) {
			$this->assertSame([], $this->app->drafts);
		}
		$other = $this->tools()->preview(self::TOOL, ['body' => 'Other text'] + self::DRAFT, 'alice')[PlanState::ARGUMENT];
		try {
			$this->tools()->call(self::TOOL, self::DRAFT + ['confirm' => true, PlanState::ARGUMENT => $other], 'alice');
			$this->fail('new plan expected');
		} catch (PlanChanged $e) {
			$this->assertSame(MailDraftMessages::planChanged(), $e->plan['warnings'][0]['message']);
			$this->assertSame(self::DRAFT['body'], $e->plan['draft']['body']);
		}
		$state = $this->tools()->preview(self::TOOL, self::DRAFT, 'alice')[PlanState::ARGUMENT];
		$this->app->withFolder(11, ['id' => 4, 'name' => 'Entwürfe']);
		$this->app->accountSettings[11] = ['drafts' => 4];
		try {
			$this->tools()->call(self::TOOL, self::DRAFT + ['confirm' => true, PlanState::ARGUMENT => $state], 'alice');
			$this->fail('new plan expected');
		} catch (PlanChanged $e) {
			$this->assertSame(4, $e->plan['target']['draftsMailboxId']);
		}
		$this->assertSame([], $this->app->drafts);
		$this->assertSame([], $this->app->imapCommands());
	}

	/**
	 * The Mail app's refusal before any write says no draft was created; a failure after the append says the draft may
	 * exist and not to repeat, and indeed the server holds it; neither shows the server's text.
	 *
	 * @return void
	 */
	public function testARefusalCreatesNothingAndALateFailureIsUncertain(): void {
		$this->app->draftFails = 'refused';
		$this->assertSame(MailDraftMessages::notCreated(), $this->failure(self::DRAFT));
		$this->assertSame([], $this->app->drafts);

		$this->app->draftFails = 'after';
		$failure = $this->failure(self::DRAFT);
		$this->assertSame(MailDraftMessages::uncertain(), $failure);
		$this->assertStringNotContainsString('secret', $failure);
		$this->assertCount(1, $this->app->drafts, 'the draft exists although the answer is uncertain');
	}

	/**
	 * Bcc is refused, before the plan and again before the save, where the installed Mail saves drafts without it
	 * (up to 5.9.x) or its version cannot be read: no plan, no call to the Mail app's save. A draft without Bcc is saved
	 * there, and from 5.10.11 on Bcc reaches the save.
	 *
	 * @return void
	 */
	public function testBccIsRefusedWhereTheMailAppWouldDropIt(): void {
		$state = $this->tools()->preview(self::TOOL, self::DRAFT, 'alice')[PlanState::ARGUMENT];
		foreach (['5.0.0', '5.9.0', '5.10.0', '', 'unknown'] as $version) {
			$this->mailVersion = $version;
			$this->assertSame(MailDraftMessages::bccNotKept('5.10.11'), $this->planFailure(self::DRAFT), "plan, Mail '$version'");
			$this->assertSame(MailDraftMessages::bccNotKept('5.10.11'), $this->failure(self::DRAFT + [PlanState::ARGUMENT => $state]), "confirm, Mail '$version'");
		}
		$this->assertNotContains('MailTransmission::saveDraft', $this->app->journal->getArrayCopy());
		$this->assertSame([], $this->app->drafts);

		$this->mailVersion = '5.9.0';
		$withoutBcc = ['bcc' => []] + self::DRAFT;
		$this->assertTrue($this->confirm($withoutBcc)['created']);
		$this->assertSame('', $this->app->drafts[0]['bcc']);

		$this->mailVersion = '5.10.11';
		$this->confirm(self::DRAFT);
		$this->assertSame('dave@example.com', $this->app->drafts[1]['bcc']);
	}

	/**
	 * Only a positive integer is a known UID: the 0 the Mail app gives when it could not read the UID is reported as
	 * unknown, and the draft still counts as created, saved once.
	 *
	 * @return void
	 */
	public function testAUidThatIsNotPositiveIsUnknown(): void {
		foreach ([0, -3] as $answer) {
			$this->app->draftUidAnswer = $answer;
			$out = $this->confirm(self::DRAFT);
			$this->assertSame([true, null], [$out['created'], $out['uid']], "UID $answer");
		}
		$this->assertCount(2, $this->app->drafts, 'one save per confirmation, never repeated for a missing UID');
		$this->app->draftUidAnswer = 7;
		$this->assertSame(7, $this->confirm(self::DRAFT)['uid']);
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
		$this->fail('mail_create_draft did not fail');
	}

	/**
	 * The draft tool over the app in memory.
	 *
	 * @return MailDraftTools the tool
	 */
	private function tools(): MailDraftTools {
		$config = $this->createStub(IConfig::class);
		$config->method('getSystemValueString')->willReturn('instance-secret');
		$apps = $this->createStub(IAppManager::class);
		$apps->method('getAppVersion')->willReturnCallback(fn (): string => $this->mailVersion);
		return new MailDraftTools($this->app, new MailGateway($this->app), FakeMailLinks::create($this), new PlanState($config), $this->policy, $apps);
	}
}
