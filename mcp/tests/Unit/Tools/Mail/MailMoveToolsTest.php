<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use OCA\Mail\Events\MessageDeletedEvent;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Tools\Mail\MailGateway;
use OCA\Mcp\Tools\Mail\MailMessages;
use OCA\Mcp\Tools\Mail\Move\MailMoveMessages;
use OCA\Mcp\Tools\Mail\Move\MailMovePlanRenderer;
use OCA\Mcp\Tools\Mail\Move\MailMoveTools;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../stubs/OCA/Mail/Events/MessageDeletedEvent.php';

/**
 * mail_move_message against a Mail app in memory: the plan reads only the cache, shows what the user may read and says
 * what the destination is (the Trash with the Mail app's retention, the Junk folder without a spam report); a confirmed
 * call needs the plan's state, resolves message and destination again and, on one client, checks the generation before
 * and after the read-write selection and the message on the server before the Mail app's own move, then tells the Mail
 * app the source message is gone. Another user's message or folder, another account, another generation, a changed
 * plan or Trash retention and a failing server never move a message, and a failure during the move is uncertain.
 */
final class MailMoveToolsTest extends TestCase {
	/** The tool. */
	private const TOOL = 'mail_move_message';

	/** The app in memory. */
	private FakeMailMessageApp $app;

	/** The grants of alice. */
	private GrantPolicy $policy;

	/** @var list<object> events dispatched */
	private array $events = [];

	/**
	 * Alice owns account 11 with INBOX (1), Archive (2), Trash (3), Junk (4) and a folder that only groups others (5),
	 * and account 12 with INBOX (6); bob owns account 22 with INBOX (9). Message 7 of alice is in her INBOX.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->app = (new FakeMailMessageApp())
			->withAccount(11, 'alice', 'Work', 'alice@example.com')
			->withAccount(12, 'alice', 'Home', 'alice@home.example')
			->withAccount(22, 'bob', 'Bob', 'bob@example.com');
		$this->app->accountSettings[11] = ['trash' => 3, 'junk' => 4, 'retention' => null];
		$this->app->deleteEvent = MessageDeletedEvent::class;
		$this->app->withFolder(11, ['id' => 1, 'name' => 'INBOX'])->withFolder(11, ['id' => 2, 'name' => 'Archive'])
			->withFolder(11, ['id' => 3, 'name' => 'Trash'])->withFolder(11, ['id' => 4, 'name' => 'Junk'])
			->withFolder(11, ['id' => 5, 'name' => 'Projects', 'selectable' => false])
			->withFolder(12, ['id' => 6, 'name' => 'INBOX'])->withFolder(22, ['id' => 9, 'name' => 'INBOX']);
		$this->app->withMessage(1, ['id' => 7, 'subject' => 'Budget review']);
		$this->policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
	}

	/**
	 * Without confirm the plan reads the cache only (no IMAP at all), names the message, the folder it leaves and the
	 * folder it goes to, and carries its state; paths and the account only with mail.read, the message only with
	 * mail.messages.
	 *
	 * @return void
	 */
	public function testThePlanReadsTheCacheAndShowsOnlyWhatAliceMayRead(): void {
		$plan = $this->tools()->preview(self::TOOL, ['message_id' => 7, 'mailbox_id' => 2], 'alice');
		$this->assertSame([], $this->app->imapCommands());
		$this->assertSame(['messageId' => 7, 'accountId' => 11, 'account' => 'alice@example.com'], $plan['target']);
		$this->assertSame(['mailboxId' => 1, 'path' => 'INBOX'], $plan['from']);
		$this->assertSame(['mailboxId' => 2, 'path' => 'Archive', 'kind' => 'folder'], $plan['to']);
		$this->assertTrue($plan['moves']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $plan[PlanState::ARGUMENT]);
		$this->assertArrayNotHasKey('notes', $plan);
		$this->assertArrayNotHasKey('notice', $plan, 'no sender text in the plan without mail.messages');

		$this->policy->setGrant('alice', 'mail', 'messages', true);
		$plan = $this->tools()->preview(self::TOOL, ['message_id' => 7, 'mailbox_id' => 2], 'alice');
		$this->assertSame('Budget review', $plan['target']['subject']);
		$this->assertSame(MailMessages::UNTRUSTED_NOTICE, $plan['notice']);

		$this->policy->setGrant('alice', 'mail', 'read', false);
		$this->policy->setGrant('alice', 'mail', 'messages', false);
		$plan = $this->tools()->preview(self::TOOL, ['message_id' => 7, 'mailbox_id' => 2], 'alice');
		$this->assertSame(['messageId' => 7, 'accountId' => 11], $plan['target']);
		$this->assertSame(['mailboxId' => 1], $plan['from']);
		$this->assertSame(['mailboxId' => 2, 'kind' => 'folder'], $plan['to']);
		$this->assertStringNotContainsString('Budget', (string)json_encode($plan));
		$this->assertSame([], $this->app->imapCommands());
	}

	/**
	 * The confirmed call checks the generation, selects the source read-write, checks the generation of the selection and
	 * the message, moves it on that same client, tells the Mail app the source UID is gone; the server holds it in the
	 * destination under a new UID and no more in the source.
	 *
	 * @return void
	 */
	public function testAConfirmedCallMovesOnTheCheckedSelection(): void {
		$out = $this->confirm(['message_id' => 7, 'mailbox_id' => 2]);
		$this->assertSame(['imap:status', 'imap:select', 'imap:status(selected)', 'imap:fetch:envelope', 'imap:move:Archive', 'imap:logout'], $this->app->imapCommands());
		$this->assertSame([['id' => 7, 'mailbox' => 'Archive', 'uid' => 1000]], $this->app->moved);
		$this->assertFalse($this->app->messages[7]['onServer']);
		$this->assertSame(['messageId' => 7, 'fromMailboxId' => 1, 'toMailboxId' => 2, 'toUrl' => FakeMailLinks::BASE . '/box/2', 'moved' => true, 'newUid' => 1000], $out);
		$this->assertCount(1, $this->events);
		$this->assertInstanceOf(MessageDeletedEvent::class, $this->events[0]);
		$this->assertSame([11, 1, 7], [$this->events[0]->account->getId(), $this->events[0]->mailbox->getId(), $this->events[0]->uid]);
	}

	/**
	 * A repeated confirmed call never moves twice: the server no longer holds the message at its UID, so it is not found
	 * and nothing is moved.
	 *
	 * @return void
	 */
	public function testARepeatedConfirmedCallDoesNotMoveAgain(): void {
		$state = $this->tools()->preview(self::TOOL, ['message_id' => 7, 'mailbox_id' => 2], 'alice')[PlanState::ARGUMENT];
		$arguments = ['message_id' => 7, 'mailbox_id' => 2, 'confirm' => true, PlanState::ARGUMENT => $state];
		$this->tools()->call(self::TOOL, $arguments, 'alice');
		$this->app->journal->exchangeArray([]);
		try {
			$this->tools()->call(self::TOOL, $arguments, 'alice');
			$this->fail('not found expected');
		} catch (ToolFailure $e) {
			$this->assertSame(MailMessages::messageNotFound(), $e->getMessage());
		}
		$this->assertNotContains('imap:move:Archive', $this->app->imapCommands());
		$this->assertCount(1, $this->app->moved);
		$this->assertCount(1, $this->events);
	}

	/**
	 * The message's own folder as destination is said in the plan, and the confirmation moves nothing and contacts no
	 * server.
	 *
	 * @return void
	 */
	public function testTheOwnFolderMovesNothing(): void {
		$plan = $this->tools()->preview(self::TOOL, ['message_id' => 7, 'mailbox_id' => 1], 'alice');
		$this->assertFalse($plan['moves']);
		$this->assertSame('same', $plan['to']['kind']);
		$this->assertSame([MailMoveMessages::alreadyThere()], $plan['notes']);
		$out = $this->confirm(['message_id' => 7, 'mailbox_id' => 1]);
		$this->assertFalse($out['moved']);
		$this->assertSame([MailMoveMessages::alreadyThere()], $out['notes']);
		$this->assertSame([], $this->app->imapCommands());
		$this->assertSame([], $this->events);
	}

	/**
	 * The Trash is allowed with its warning: without a retention, that the Mail app deletes nothing on its own but
	 * emptying it or the server can; with one, the days of the Mail app; never a promised duration.
	 *
	 * @return void
	 */
	public function testTheTrashIsMovedToWithItsRetentionWarning(): void {
		$plan = $this->tools()->preview(self::TOOL, ['message_id' => 7, 'mailbox_id' => 3], 'alice');
		$this->assertSame(['mailboxId' => 3, 'path' => 'Trash', 'kind' => 'trash', 'retentionDays' => null], $plan['to']);
		$this->assertSame([MailMoveMessages::trashNote(null)], $plan['notes']);
		$this->assertStringContainsString('no automatic deletion', $plan['notes'][0]);
		$this->assertStringContainsString('policies of the mail server', $plan['notes'][0]);

		$this->app->accountSettings[11]['retention'] = 30;
		$plan = $this->tools()->preview(self::TOOL, ['message_id' => 7, 'mailbox_id' => 3], 'alice');
		$this->assertSame(30, $plan['to']['retentionDays']);
		$this->assertStringContainsString('longer than 30 days', $plan['notes'][0]);
		$this->assertStringContainsString('sooner', $plan['notes'][0]);
		$this->assertStringContainsString($plan['notes'][0], (string)(new MailMovePlanRenderer())->render($plan));

		$this->app->accountSettings[11]['retention'] = 0;
		$this->assertNull($this->tools()->preview(self::TOOL, ['message_id' => 7, 'mailbox_id' => 3], 'alice')['to']['retentionDays']);

		$out = $this->confirm(['message_id' => 7, 'mailbox_id' => 3]);
		$this->assertTrue($out['moved']);
		$this->assertContains('imap:move:Trash', $this->app->imapCommands());
		$this->assertSame([], array_values(array_filter($this->app->imapCommands(), static fn (string $c): bool => str_contains($c, 'expunge') || str_contains($c, 'store'))));
	}

	/**
	 * The warning is part of the plan state: a folder that became the Trash, or a retention that changed, after the plan
	 * gives the new plan with its warning, and nothing is contacted.
	 *
	 * @return void
	 */
	public function testAChangedTrashWarningIsNeverConfirmedSilently(): void {
		$state = $this->tools()->preview(self::TOOL, ['message_id' => 7, 'mailbox_id' => 2], 'alice')[PlanState::ARGUMENT];
		$this->app->accountSettings[11]['trash'] = 2;
		try {
			$this->tools()->call(self::TOOL, ['message_id' => 7, 'mailbox_id' => 2, 'confirm' => true, PlanState::ARGUMENT => $state], 'alice');
			$this->fail('new plan expected');
		} catch (PlanChanged $e) {
			$this->assertSame('trash', $e->plan['to']['kind']);
			$this->assertSame(MailMoveMessages::planChanged(), $e->plan['warnings'][0]['message']);
		}

		$state = $this->tools()->preview(self::TOOL, ['message_id' => 7, 'mailbox_id' => 2], 'alice')[PlanState::ARGUMENT];
		$this->app->accountSettings[11]['retention'] = 7;
		try {
			$this->tools()->call(self::TOOL, ['message_id' => 7, 'mailbox_id' => 2, 'confirm' => true, PlanState::ARGUMENT => $state], 'alice');
			$this->fail('new plan expected');
		} catch (PlanChanged $e) {
			$this->assertSame([MailMoveMessages::trashNote(7)], $e->plan['notes']);
		}
		$this->assertSame([], $this->app->imapCommands());
		$this->assertSame([], $this->app->moved);
	}

	/**
	 * The Junk folder is a plain move with a note: it neither marks the message as junk nor reports it.
	 *
	 * @return void
	 */
	public function testTheJunkFolderIsAPlainMoveWithANote(): void {
		$plan = $this->tools()->preview(self::TOOL, ['message_id' => 7, 'mailbox_id' => 4], 'alice');
		$this->assertSame('junk', $plan['to']['kind']);
		$this->assertSame([MailMoveMessages::junkNote()], $plan['notes']);
		$this->confirm(['message_id' => 7, 'mailbox_id' => 4]);
		$this->assertSame(['imap:status', 'imap:select', 'imap:status(selected)', 'imap:fetch:envelope', 'imap:move:Junk', 'imap:logout'], $this->app->imapCommands());
		$this->assertSame([], $this->app->messages[7]['serverFlags']);
	}

	/**
	 * Destinations the Mail app's move refuses are refused before any IMAP: another account of alice, a folder that only
	 * groups others; bob's folder and a missing one answer the same.
	 *
	 * @return void
	 */
	public function testRefusedDestinationsContactNothing(): void {
		$this->assertSame(MailMoveMessages::otherAccount(), $this->planFailure(6));
		$this->assertSame(MailMoveMessages::notSelectable(), $this->planFailure(5));
		$this->assertSame(MailMoveMessages::folderNotFound(), $this->planFailure(9));
		$this->assertSame(MailMoveMessages::folderNotFound(), $this->planFailure(98765));
		$this->assertSame(MailMoveMessages::otherAccount(), $this->failure(['message_id' => 7, 'mailbox_id' => 6, PlanState::ARGUMENT => str_repeat('a', 64)]));
		$this->assertSame([], $this->app->imapCommands());
	}

	/**
	 * Another user's message and a missing one answer the same, without any IMAP command, in plan and confirm alike.
	 *
	 * @return void
	 */
	public function testForeignAndMissingMessagesAreIndistinguishable(): void {
		$this->app->withMessage(9, ['id' => 900]);
		foreach ([900, 12345] as $id) {
			try {
				$this->tools()->preview(self::TOOL, ['message_id' => $id, 'mailbox_id' => 2], 'alice');
				$this->fail('not found expected');
			} catch (ToolFailure $e) {
				$this->assertSame(MailMessages::messageNotFound(), $e->getMessage());
			}
			$this->assertSame(MailMessages::messageNotFound(), $this->failure(['message_id' => $id, 'mailbox_id' => 2, PlanState::ARGUMENT => str_repeat('a', 64)]));
		}
		$this->assertSame([], $this->app->imapCommands());
		$this->assertSame([], $this->app->moved);
	}

	/**
	 * Without plan_state, or with the state of another plan, nothing is contacted: the first is an argument error, the
	 * second the new plan with its warning.
	 *
	 * @return void
	 */
	public function testAConfirmedCallNeedsTheStateOfItsPlan(): void {
		try {
			$this->tools()->call(self::TOOL, ['message_id' => 7, 'mailbox_id' => 2, 'confirm' => true], 'alice');
			$this->fail('plan_state required');
		} catch (ArgumentValidationException) {
			$this->assertSame([], $this->app->imapCommands());
		}
		$other = $this->tools()->preview(self::TOOL, ['message_id' => 7, 'mailbox_id' => 4], 'alice')[PlanState::ARGUMENT];
		try {
			$this->tools()->call(self::TOOL, ['message_id' => 7, 'mailbox_id' => 2, 'confirm' => true, PlanState::ARGUMENT => $other], 'alice');
			$this->fail('new plan expected');
		} catch (PlanChanged $e) {
			$this->assertSame(2, $e->plan['to']['mailboxId']);
			$this->assertSame([], $this->app->imapCommands());
		}
	}

	/**
	 * When the Mail app resynchronizes the source to another UID generation between the plan and the confirmation, the
	 * old plan state no longer matches: the answer is the new plan and nothing is contacted.
	 *
	 * @return void
	 */
	public function testAPlanOfAnotherGenerationIsNotConfirmed(): void {
		$state = $this->tools()->preview(self::TOOL, ['message_id' => 7, 'mailbox_id' => 2], 'alice')[PlanState::ARGUMENT];
		$token = FakeMailMessageApp::syncToken('9');
		$this->app->folders[1] = ['newToken' => $token, 'changedToken' => $token, 'vanishedToken' => $token] + $this->app->folders[1];
		$this->app->uidValidity['INBOX'] = '9';
		try {
			$this->tools()->call(self::TOOL, ['message_id' => 7, 'mailbox_id' => 2, 'confirm' => true, PlanState::ARGUMENT => $state], 'alice');
			$this->fail('new plan expected');
		} catch (PlanChanged) {
			$this->assertSame([], $this->app->imapCommands());
			$this->assertSame([], $this->app->moved);
		}
	}

	/**
	 * A recycled UID never moves another message: a cache of another generation is refused before the selection, a
	 * folder recreated before the selection after it, a different Message-ID after the FETCH; none reaches the move.
	 *
	 * @return void
	 */
	public function testAnotherGenerationNeverMovesAMessage(): void {
		$this->app->withFolder(11, ['id' => 20, 'name' => 'Regenerated']);
		$this->app->uidValidity['Regenerated'] = '8';
		$this->app->withMessage(20, ['id' => 21]);
		$this->assertSame(MailMessages::messageChanged(), $this->failure(['message_id' => 21, 'mailbox_id' => 2]));
		$this->assertSame(['imap:status', 'imap:logout'], $this->app->imapCommands());

		$this->app->journal->exchangeArray([]);
		$this->app->withFolder(11, ['id' => 30, 'name' => 'Racing']);
		$this->app->uidValidityOnOpen['Racing'] = '8';
		$this->app->withMessage(30, ['id' => 31]);
		$this->assertSame(MailMessages::messageChanged(), $this->failure(['message_id' => 31, 'mailbox_id' => 2]));
		$this->assertSame(['imap:status', 'imap:select', 'imap:status(selected)', 'imap:logout'], $this->app->imapCommands());

		$this->app->journal->exchangeArray([]);
		$this->app->withMessage(1, ['id' => 6, 'serverMessageId' => '<other@example.com>']);
		$this->assertSame(MailMessages::messageChanged(), $this->failure(['message_id' => 6, 'mailbox_id' => 2]));
		$this->assertSame(['imap:status', 'imap:select', 'imap:status(selected)', 'imap:fetch:envelope', 'imap:logout'], $this->app->imapCommands());
		$this->assertSame([], $this->app->moved);
		$this->assertSame([], $this->events);
	}

	/**
	 * A failure before the move says nothing was moved; a failure during it says the outcome is uncertain and not to
	 * repeat; neither shows the server's text nor tells the Mail app anything, and both log out.
	 *
	 * @return void
	 */
	public function testServerFailuresAreNotMovedOrUncertain(): void {
		$this->app->selectFails = true;
		$failure = $this->failure(['message_id' => 7, 'mailbox_id' => 2]);
		$this->assertSame(MailMoveMessages::notMoved(), $failure);
		$this->assertStringNotContainsString('secret', $failure);
		$this->assertSame(['imap:status', 'imap:select', 'imap:logout'], $this->app->imapCommands());

		$this->app->selectFails = false;
		$this->app->moveFails = true;
		$this->app->journal->exchangeArray([]);
		$failure = $this->failure(['message_id' => 7, 'mailbox_id' => 2]);
		$this->assertSame(MailMoveMessages::uncertain(), $failure);
		$this->assertStringNotContainsString('secret', $failure);
		$this->assertSame(['imap:move:Archive', 'imap:logout'], array_slice($this->app->imapCommands(), -2));
		$this->assertSame([], $this->events);
	}

	/**
	 * The move reaches the Mail app's mapper only on a server where it touches no other message: with MOVE, or with
	 * UIDPLUS alone (UID COPY then UID EXPUNGE of that UID, delegated to the same mapper); with neither, or with
	 * capabilities that cannot be read, it is refused before any move with a fixed message.
	 *
	 * @return void
	 */
	public function testOnlyAServerWithMoveOrUidplusIsAskedToMove(): void {
		$this->app->capabilities = ['MOVE'];
		$this->assertTrue($this->confirm(['message_id' => 7, 'mailbox_id' => 2])['moved']);

		$this->app->withMessage(1, ['id' => 8]);
		$this->app->capabilities = ['UIDPLUS'];
		$this->app->journal->exchangeArray([]);
		$this->assertTrue($this->confirm(['message_id' => 8, 'mailbox_id' => 2])['moved']);
		$this->assertContains('imap:move:Archive', $this->app->imapCommands(), 'delegated to the Mail app\'s mapper on the checked selection');
		$this->assertContains('ImapMessageMapper::move', $this->app->journal->getArrayCopy());

		foreach ([['capabilities' => [], 'fails' => false], ['capabilities' => ['IMAP4REV1', 'IDLE'], 'fails' => false], ['capabilities' => ['MOVE'], 'fails' => true]] as $case) {
			$this->app->withMessage(1, ['id' => 9]);
			$this->app->capabilities = $case['capabilities'];
			$this->app->capabilityFails = $case['fails'];
			$this->app->journal->exchangeArray([]);
			$failure = $this->failure(['message_id' => 9, 'mailbox_id' => 2]);
			$this->assertSame(MailMoveMessages::serverCannotMove(), $failure);
			$this->assertStringNotContainsString('secret', $failure);
			$this->assertSame(['imap:status', 'imap:select', 'imap:status(selected)', 'imap:fetch:envelope', 'imap:logout'], $this->app->imapCommands(), 'no move asked');
			$this->assertNotContains('ImapMessageMapper::move', $this->app->journal->getArrayCopy());
		}
		$this->assertCount(2, $this->app->moved);
		$this->assertCount(2, $this->events);
	}

	/**
	 * Characterizes the limit of an uncertain answer: a copy that reached the destination before the failure (the
	 * original kept) is reported as uncertain, without the Mail event, and the source stays in the server and the cache,
	 * so nothing here stops a repeated confirmation from copying again; the answer says not to repeat.
	 *
	 * @return void
	 */
	public function testACopyThatKeptTheOriginalIsUncertainAndLeavesTheSource(): void {
		$this->app->copyThenFails = true;
		$this->assertSame(MailMoveMessages::uncertain(), $this->failure(['message_id' => 7, 'mailbox_id' => 2]));
		$this->assertSame([['id' => 7, 'mailbox' => 'Archive']], $this->app->copied);
		$this->assertTrue($this->app->messages[7]['onServer'] ?? true, 'the original is still on the server');
		$this->assertSame([], $this->events, 'the Mail app is not told: its cache keeps the source');
		$plan = $this->tools()->preview(self::TOOL, ['message_id' => 7, 'mailbox_id' => 2], 'alice');
		$this->assertTrue($plan['moves'], 'the cached source still plans a move: nothing here stops a second copy');
	}

	/**
	 * When a listener of the Mail event fails, the move stands and the answer says the Mail app catches up later.
	 *
	 * @return void
	 */
	public function testAFailingListenerKeepsTheMoveAndSaysSo(): void {
		$out = $this->confirm(['message_id' => 7, 'mailbox_id' => 2], true);
		$this->assertTrue($out['moved']);
		$this->assertSame([MailMoveMessages::cacheBehind()], $out['notes']);
	}

	/**
	 * The confirmed answer, after a plan for the same arguments.
	 *
	 * @param array<string, mixed> $arguments arguments without confirm
	 * @param bool $listenerFails whether the event dispatcher throws
	 * @return array<string, mixed> the decoded answer
	 */
	private function confirm(array $arguments, bool $listenerFails = false): array {
		$state = $this->tools()->preview(self::TOOL, $arguments, 'alice')[PlanState::ARGUMENT];
		$result = $this->tools($listenerFails)->call(self::TOOL, $arguments + ['confirm' => true, PlanState::ARGUMENT => $state], 'alice');
		return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * The message of a failing plan of message 7 to a folder.
	 *
	 * @param int $mailboxId destination folder id
	 * @return string the failure message
	 */
	private function planFailure(int $mailboxId): string {
		try {
			$this->tools()->preview(self::TOOL, ['message_id' => 7, 'mailbox_id' => $mailboxId], 'alice');
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
		$this->fail('mail_move_message did not fail');
	}

	/**
	 * The move tool over the app in memory.
	 *
	 * @param bool $listenerFails whether the event dispatcher throws
	 * @return MailMoveTools the tool
	 */
	private function tools(bool $listenerFails = false): MailMoveTools {
		$config = $this->createStub(IConfig::class);
		$config->method('getUserValue')->willReturn('UTC');
		$config->method('getSystemValueString')->willReturn('instance-secret');
		$events = $this->createStub(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(function (object $event) use ($listenerFails): void {
			if ($listenerFails) {
				throw new \RuntimeException('listener');
			}
			$this->events[] = $event;
		});
		return new MailMoveTools($this->app, new MailGateway($this->app), new UserTimezone($config), FakeMailLinks::create($this),
			new PlanState($config), $this->policy, $events);
	}
}
