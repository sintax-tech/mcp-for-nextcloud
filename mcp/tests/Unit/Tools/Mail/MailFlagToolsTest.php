<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use OCA\Mail\Events\MessageFlaggedEvent;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\UserTimezone;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Mail\Flag\MailFlagMessages;
use OCA\Mcp\Tools\Mail\Flag\MailFlagTools;
use OCA\Mcp\Tools\Mail\MailGateway;
use OCA\Mcp\Tools\Mail\MailMessages;
use OCA\Mcp\Tools\PlanChanged;
use OCA\Mcp\Tools\PlanState;
use OCA\Mcp\Tools\ToolFailure;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../stubs/OCA/Mail/Events/MessageFlaggedEvent.php';

/**
 * mail_set_flags against a Mail app in memory: the plan reads only the cache and shows what the user may read; a
 * confirmed call needs the plan's state, re-resolves the message and, on one client, checks the generation before and
 * after the read-write selection and the message on the server before it stores only the asked flags that differ, then
 * tells the Mail app of each change. Another user's message, another generation, a changed plan and a failing server
 * never change a flag.
 */
final class MailFlagToolsTest extends TestCase {
	/** The app in memory. */
	private FakeMailMessageApp $app;

	/** The grants of alice. */
	private GrantPolicy $policy;

	/** @var list<object> events dispatched */
	private array $events = [];

	/**
	 * Alice owns account 11 with INBOX (1); bob owns account 22 with INBOX (9). Message 7 of alice is unread and not
	 * flagged.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->app = (new FakeMailMessageApp())
			->withAccount(11, 'alice', 'Work', 'alice@example.com')
			->withAccount(22, 'bob', 'Bob', 'bob@example.com');
		$this->app->flagEvent = MessageFlaggedEvent::class;
		$this->app->withFolder(11, ['id' => 1, 'name' => 'INBOX'])->withFolder(22, ['id' => 9, 'name' => 'INBOX']);
		$this->app->withMessage(1, ['id' => 7, 'subject' => 'Budget review']);
		$this->policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
	}

	/**
	 * Without confirm the plan reads the cache only (no IMAP at all), names the message and the change, and carries its
	 * state; what identifies the message is shown as far as alice may read it.
	 *
	 * @return void
	 */
	public function testThePlanReadsTheCacheAndShowsOnlyWhatAliceMayRead(): void {
		$plan = $this->tools()->preview('mail_set_flags', ['message_id' => 7, 'seen' => true, 'flagged' => false], 'alice');
		$this->assertSame([], $this->app->imapCommands());
		$this->assertSame(['messageId' => 7, 'accountId' => 11, 'mailboxId' => 1, 'account' => 'alice@example.com', 'folder' => 'INBOX'], $plan['target']);
		$this->assertSame([['flag' => 'seen', 'from' => MailFlagMessages::state('seen', false), 'to' => MailFlagMessages::state('seen', true)]], $plan['changes']);
		$this->assertSame([['flag' => 'flagged', 'state' => MailFlagMessages::state('flagged', false)]], $plan['unchanged']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $plan[PlanState::ARGUMENT]);
		$this->assertArrayNotHasKey('notice', $plan, 'no sender text in the plan without mail.messages');

		$this->policy->setGrant('alice', 'mail', 'messages', true);
		$plan = $this->tools()->preview('mail_set_flags', ['message_id' => 7, 'seen' => true], 'alice');
		$this->assertSame('Budget review', $plan['target']['subject']);
		$this->assertSame(MailMessages::UNTRUSTED_NOTICE, $plan['notice']);

		$this->policy->setGrant('alice', 'mail', 'read', false);
		$this->policy->setGrant('alice', 'mail', 'messages', false);
		$this->assertSame(['messageId' => 7, 'accountId' => 11, 'mailboxId' => 1], $this->tools()->preview('mail_set_flags', ['message_id' => 7, 'seen' => true], 'alice')['target']);
	}

	/**
	 * The confirmed call checks the generation, selects read-write, checks the generation of the selection and the
	 * message, stores only the flag that differs on that same client, tells the Mail app, and the server holds it.
	 *
	 * @return void
	 */
	public function testAConfirmedCallStoresOnlyTheAskedChangeOnTheCheckedSelection(): void {
		$out = $this->confirm(['message_id' => 7, 'seen' => true, 'flagged' => false]);
		$this->assertSame(['imap:status', 'imap:select', 'imap:status(selected)', 'imap:fetch:envelope,flags', 'imap:store:+\\seen', 'imap:logout'], $this->app->imapCommands());
		$this->assertSame(['\\seen'], $this->app->messages[7]['serverFlags']);
		$this->assertSame(['messageId' => 7, 'changed' => [['flag' => 'seen', 'to' => MailFlagMessages::state('seen', true)]], 'flags' => ['seen' => true, 'flagged' => false]], $out);
		$this->assertCount(1, $this->events);
		$this->assertSame(['seen', true, 7], [$this->events[0]->flag, $this->events[0]->set, $this->events[0]->message->getId()]);
	}

	/**
	 * A flag already as asked is not stored and no event is sent; the answer says nothing changed.
	 *
	 * @return void
	 */
	public function testAFlagAlreadyAsAskedIsLeftAsItIs(): void {
		$this->app->withMessage(1, ['id' => 8, 'seen' => true]);
		$out = $this->confirm(['message_id' => 8, 'seen' => true]);
		$this->assertNotContains('imap:store:+\\seen', $this->app->imapCommands());
		$this->assertSame([], $out['changed']);
		$this->assertSame([MailFlagMessages::alreadySet()], $out['notes']);
		$this->assertSame([], $this->events);
	}

	/**
	 * Clearing removes exactly that flag and keeps the others.
	 *
	 * @return void
	 */
	public function testClearingRemovesOnlyThatFlag(): void {
		$this->app->withMessage(1, ['id' => 9, 'seen' => true, 'flagged' => true, 'serverFlags' => ['\\seen', '\\flagged', '\\answered']]);
		$this->confirm(['message_id' => 9, 'flagged' => false]);
		$this->assertSame(['\\seen', '\\answered'], $this->app->messages[9]['serverFlags']);
		$this->assertContains('imap:store:-\\flagged', $this->app->imapCommands());
	}

	/**
	 * Without plan_state, or with the state of another plan, nothing is contacted: the first is an argument error,
	 * the second the new plan with its warning.
	 *
	 * @return void
	 */
	public function testAConfirmedCallNeedsTheStateOfItsPlan(): void {
		try {
			$this->tools()->call('mail_set_flags', ['message_id' => 7, 'seen' => true, 'confirm' => true], 'alice');
			$this->fail('plan_state required');
		} catch (ArgumentValidationException) {
			$this->assertSame([], $this->app->imapCommands());
		}
		$other = $this->tools()->preview('mail_set_flags', ['message_id' => 7, 'flagged' => true], 'alice')[PlanState::ARGUMENT];
		try {
			$this->tools()->call('mail_set_flags', ['message_id' => 7, 'seen' => true, 'confirm' => true, PlanState::ARGUMENT => $other], 'alice');
			$this->fail('new plan expected');
		} catch (PlanChanged $e) {
			$this->assertSame(MailFlagMessages::planChanged(), $e->plan['warnings'][0]['message']);
			$this->assertSame([], $this->app->imapCommands());
		}
	}

	/**
	 * When the Mail app resynchronizes the folder to another UID generation between the plan and the confirmation, the
	 * plan state of the old one no longer matches: the answer is the new plan and nothing is contacted.
	 *
	 * @return void
	 */
	public function testAPlanOfAnotherGenerationIsNotConfirmed(): void {
		$state = $this->tools()->preview('mail_set_flags', ['message_id' => 7, 'seen' => true], 'alice')[PlanState::ARGUMENT];
		$token = FakeMailMessageApp::syncToken('9');
		$this->app->folders[1] = ['newToken' => $token, 'changedToken' => $token, 'vanishedToken' => $token] + $this->app->folders[1];
		$this->app->uidValidity['INBOX'] = '9';
		try {
			$this->tools()->call('mail_set_flags', ['message_id' => 7, 'seen' => true, 'confirm' => true, PlanState::ARGUMENT => $state], 'alice');
			$this->fail('new plan expected');
		} catch (PlanChanged) {
			$this->assertSame([], $this->app->imapCommands());
			$this->assertSame([], $this->app->messages[7]['serverFlags']);
		}
	}

	/**
	 * A cache of another UID generation is refused before the selection; a folder recreated before the selection is
	 * refused after it, before any STORE; a different Message-ID too.
	 *
	 * @return void
	 */
	public function testAnotherGenerationNeverChangesAFlag(): void {
		$this->app->withFolder(11, ['id' => 2, 'name' => 'Regenerated']);
		$this->app->uidValidity['Regenerated'] = '8';
		$this->app->withMessage(2, ['id' => 21]);
		$this->assertSame(MailMessages::messageChanged(), $this->failure(['message_id' => 21, 'seen' => true]));
		$this->assertSame(['imap:status', 'imap:logout'], $this->app->imapCommands());

		$this->app->journal->exchangeArray([]);
		$this->app->withFolder(11, ['id' => 3, 'name' => 'Racing']);
		$this->app->uidValidityOnOpen['Racing'] = '8';
		$this->app->withMessage(3, ['id' => 31]);
		$this->assertSame(MailMessages::messageChanged(), $this->failure(['message_id' => 31, 'seen' => true]));
		$this->assertSame(['imap:status', 'imap:select', 'imap:status(selected)', 'imap:logout'], $this->app->imapCommands());

		$this->app->journal->exchangeArray([]);
		$this->app->withMessage(1, ['id' => 6, 'serverMessageId' => '<other@example.com>']);
		$this->assertSame(MailMessages::messageChanged(), $this->failure(['message_id' => 6, 'seen' => true]));
		$this->assertSame([], array_values(array_filter($this->app->imapCommands(), static fn (string $c): bool => str_starts_with($c, 'imap:store'))));
		$this->assertSame([], $this->events);
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
				$this->tools()->preview('mail_set_flags', ['message_id' => $id, 'seen' => true], 'alice');
				$this->fail('not found expected');
			} catch (ToolFailure $e) {
				$this->assertSame(MailMessages::messageNotFound(), $e->getMessage());
			}
			$this->assertSame(MailMessages::messageNotFound(), $this->failure(['message_id' => $id, 'seen' => true, PlanState::ARGUMENT => str_repeat('a', 64)]));
		}
		$this->assertSame([], $this->app->imapCommands());
	}

	/**
	 * A failing STORE gives a fixed message, never the server's text, logs out and sends no event.
	 *
	 * @return void
	 */
	public function testAServerFailureHidesItsDetails(): void {
		$this->app->storeFails = true;
		$failure = $this->failure(['message_id' => 7, 'seen' => true]);
		$this->assertSame(MailFlagMessages::unavailable(), $failure);
		$this->assertStringNotContainsString('secret', $failure);
		$this->assertSame('imap:logout', array_slice($this->app->imapCommands(), -1)[0]);
		$this->assertSame([], $this->events);
	}

	/**
	 * The confirmed answer, after a plan for the same arguments.
	 *
	 * @param array<string, mixed> $arguments arguments without confirm
	 * @return array<string, mixed> the decoded answer
	 */
	private function confirm(array $arguments): array {
		$state = $this->tools()->preview('mail_set_flags', $arguments, 'alice')[PlanState::ARGUMENT];
		$result = $this->tools()->call('mail_set_flags', $arguments + ['confirm' => true, PlanState::ARGUMENT => $state], 'alice');
		return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * The message of a failing confirmed call, after a plan for the same arguments when none is given.
	 *
	 * @param array<string, mixed> $arguments arguments without confirm
	 * @return string the failure message
	 */
	private function failure(array $arguments): string {
		try {
			$state = $arguments[PlanState::ARGUMENT] ?? $this->tools()->preview('mail_set_flags', $arguments, 'alice')[PlanState::ARGUMENT];
			$this->tools()->call('mail_set_flags', ['confirm' => true, PlanState::ARGUMENT => $state] + $arguments, 'alice');
		} catch (ToolFailure $e) {
			return $e->getMessage();
		}
		$this->fail('mail_set_flags did not fail');
	}

	/**
	 * The flag tool over the app in memory.
	 *
	 * @return MailFlagTools the tool
	 */
	private function tools(): MailFlagTools {
		$config = $this->createStub(IConfig::class);
		$config->method('getUserValue')->willReturn('UTC');
		$config->method('getSystemValueString')->willReturn('instance-secret');
		$events = $this->createStub(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(function (object $event): void {
			$this->events[] = $event;
		});
		return new MailFlagTools($this->app, new MailGateway($this->app), new UserTimezone($config), FakeMailLinks::create($this),
			new PlanState($config), $this->policy, $events);
	}
}
