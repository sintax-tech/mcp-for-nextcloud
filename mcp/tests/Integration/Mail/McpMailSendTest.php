<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Mail;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tools\Mail\MailMessages;
use OCA\Mcp\Tools\Mail\Send\MailSendMessages;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

/**
 * mail_send_message against the real Mail app, a real IMAP server and the throwaway SMTP sink of the cell
 * (ci/mail/smtp-sink.php on the loopback of the Nextcloud container; alice's account sends to it; synthetic
 * example.com/example.org addresses only).
 *
 * Without `mail.send` the tool is absent and uncallable. With it alone: a plan reaches no server and writes nothing to
 * the Outbox; a confirmed call hands the message to the SMTP server with To, Cc and Bcc in the envelope and no Bcc in the
 * headers, the Mail app saves the copy in Sent (Bcc kept there) and removes it from the Outbox; confirming the same plan
 * again sends again. The recorded outcome follows the Mail app's status: a storage or authentication refusal is refused,
 * a refused recipient among accepted ones and a connection lost after the message are uncertain, a copy to Sent that
 * fails after the server accepted the message is sent but not copied; every one but a full success stays in the Outbox.
 * Another user's account and a changed plan reach no server.
 */
#[Group('mail')]
final class McpMailSendTest extends IntegrationTestCase {
	use MailCellRows;
	use MailSinkSessions;

	/** The send tool. */
	private const TOOL = 'mail_send_message';

	/** Sent folder id of alice's account before a test changed it, to put back. */
	private ?int $sentBefore = null;

	/**
	 * Runs only in a Mail cell.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		if (getenv('MCP_IT_WITH_MAIL') !== '1') {
			$this->markTestSkipped('Mail cell only (MCP_IT_WITH_MAIL=1)');
		}
	}

	/**
	 * Leaves alice without the send and messages grants and with her Sent folder, as the other Mail tests expect.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if (getenv('MCP_IT_WITH_MAIL') === '1') {
			$policy = Server::get(GrantPolicy::class);
			$policy->setGrant(MailActor::ALICE, 'mail', 'send', false);
			$policy->setGrant(MailActor::ALICE, 'mail', 'messages', false);
			if ($this->sentBefore !== null) {
				$this->setSent($this->sentBefore);
			}
		}
		parent::tearDown();
	}

	/**
	 * With every other Mail grant but not the send one, the tool is neither listed nor callable.
	 *
	 * @return void
	 */
	public function testWithoutTheGrantTheToolIsAbsent(): void {
		$policy = Server::get(GrantPolicy::class);
		foreach (['messages', 'flags', 'move', 'draft'] as $operation) {
			$policy->setGrant(MailActor::ALICE, 'mail', $operation, true);
		}
		$policy->setGrant(MailActor::ALICE, 'mail', 'send', false);
		$this->assertNotContains(self::TOOL, array_column(MailActor::run(MailActor::ALICE, 'tools'), 'name'));
		$calls = MailActor::run(MailActor::ALICE, 'calls', ['calls' => [[self::TOOL, ['account_id' => $this->account(MailActor::ALICE), 'to' => ['ok@example.com']]]]]);
		$this->assertSame(['refused' => true], $calls[0]['result']);
		foreach (['flags', 'move', 'draft'] as $operation) {
			$policy->setGrant(MailActor::ALICE, 'mail', $operation, false);
		}
	}

	/**
	 * A plan reaches no server; the confirmed call gives the SMTP server To, Cc and Bcc in the envelope and no Bcc in the
	 * headers, the copy in Sent keeps Bcc, the Outbox keeps nothing, and the same plan confirmed again sends again.
	 *
	 * @return void
	 */
	public function testASentMessageHasBccInTheEnvelopeOnly(): void {
		Server::get(GrantPolicy::class)->setGrant(MailActor::ALICE, 'mail', 'send', true);
		$own = $this->account(MailActor::ALICE);
		$sent = $this->mailbox($own, 'Sent');
		$this->assertSame($sent, (int)$this->select('mail_accounts', 'id', $own, 'id')[0]['sent_mailbox_id'], 'the Mail app took Sent as the account\'s');
		$arguments = ['account_id' => $own, 'to' => ['ok-to@example.com'], 'cc' => ['ok-cc@example.com'], 'bcc' => ['ok-bcc@example.org'],
			'subject' => 'Sent through MCP', 'body' => "Hello,\nthis goes to the test sink only.\n"];
		$imap = $this->imap();
		$before = $imap->state();
		$outbox = $this->select('mail_local_messages', 'account_id', $own, 'id');
		$sessions = count($this->sinkSessions());

		self::markCell('ml8-send-start');
		$plan = $this->call([self::TOOL, $arguments]);
		$this->assertTrue($plan['structuredContent']['requiresConfirmation']);
		$this->assertSame('alice@example.com', $plan['structuredContent']['target']['from']);
		$this->assertSame($sessions, count($this->sinkSessions()), 'a plan reaches no SMTP server');
		$this->assertSame($outbox, $this->select('mail_local_messages', 'account_id', $own, 'id'), 'a plan writes nothing to the Outbox');
		$this->assertSame($before, $imap->state(), 'a plan changes no folder');

		$done = $this->decoded($this->confirm($plan, $arguments));
		$this->assertSame(['sent', true, true], [$done['outcome'], $done['sent'], $done['copiedToSent']]);
		$session = $this->newSinkSessions($sessions, 1)[0];
		$this->assertSame('alice@example.com', $session['from']);
		$this->assertEqualsCanonicalizing(['ok-to@example.com', 'ok-cc@example.com', 'ok-bcc@example.org'], array_column($session['rcpt'], 'address'), 'To, Cc and Bcc in the envelope');
		$this->assertTrue($session['data'] && $session['answered']);
		$this->assertMatchesRegularExpression('/^To: ok-to@example\.com/mi', (string)$session['message']);
		$this->assertMatchesRegularExpression('/^Cc: ok-cc@example\.com/mi', (string)$session['message']);
		$this->assertDoesNotMatchRegularExpression('/^Bcc:/mi', (string)$session['message'], 'no Bcc header reaches the SMTP server');
		$this->assertStringNotContainsString('ok-bcc@example.org', (string)$session['message'], 'the Bcc address is nowhere in the transmitted message');
		$this->assertStringContainsString('Message-ID: ' . $done['messageIdHeader'], (string)$session['message']);

		$after = $imap->state();
		$this->assertSame($before['Sent']['exists'] + 1, $after['Sent']['exists'], 'one copy in Sent');
		$copy = array_values(array_diff(array_keys($after['Sent']['flags']), array_keys($before['Sent']['flags'])))[0];
		$this->assertMatchesRegularExpression('/^Bcc: ok-bcc@example\.org/mi', $imap->raw('Sent', $copy), 'the copy in Sent keeps Bcc (Mail 5.10.11 on)');
		$this->assertSame($outbox, $this->select('mail_local_messages', 'account_id', $own, 'id'), 'the Mail app removed the sent message from the Outbox');
		$others = static fn (array $state): array => array_diff_key($state, ['Sent' => 0]);
		$this->assertSame($others($before), $others($after), 'every other folder is untouched');

		$this->decoded($this->confirm($plan, $arguments));
		$this->assertCount(2, $this->newSinkSessions($sessions, 2), 'confirming the same plan again sends again');
		self::markCell('ml8-send-end');
		$imap->close();
	}

	/**
	 * Each SMTP refusal gives the outcome the Mail app's status says: storage and authentication refusals are refused,
	 * a refused recipient among accepted ones (Horde stops before DATA, the Mail app records an error) and a connection
	 * lost after the message are uncertain. None reaches Sent; each stays in the Outbox with its status.
	 *
	 * @return void
	 */
	public function testRefusalsAndLostConnectionsFollowTheMailAppsStatus(): void {
		Server::get(GrantPolicy::class)->setGrant(MailActor::ALICE, 'mail', 'send', true);
		$own = $this->account(MailActor::ALICE);
		$imap = $this->imap();
		$sentBefore = $imap->state()['Sent']['exists'];
		$cases = [
			'quota' => [['quota@example.com'], 'refused', 10, false],
			'auth' => [['auth@example.com'], 'refused', 10, false],
			'mixed' => [['ok-mixed@example.com', 'reject@example.com'], 'uncertain', 13, false],
			'drop' => [['drop@example.com'], 'uncertain', 13, true],
		];
		self::markCell('ml8-refusals-start');
		foreach ($cases as $name => [$to, $outcome, $status, $data]) {
			$arguments = ['account_id' => $own, 'to' => $to, 'subject' => "Case $name"];
			$sessions = count($this->sinkSessions());
			$done = $this->decoded($this->confirm($this->call([self::TOOL, $arguments]), $arguments));
			$this->assertSame($outcome, $done['outcome'], $name);
			$this->assertArrayNotHasKey('messageIdHeader', $done, $name);
			$row = $this->select('mail_local_messages', 'id', (int)$done['outboxId'], 'id');
			$this->assertCount(1, $row, "$name: stays in the Outbox");
			$this->assertSame($status, (int)$row[0]['status'], "$name: the Mail app's status");
			$session = $this->newSinkSessions($sessions, 1)[0];
			$this->assertSame($data, $session['data'], "$name: DATA reached the server");
			if ($name === 'mixed') {
				$this->assertSame([250, 550], array_column($session['rcpt'], 'reply'), 'one recipient accepted, one refused, then no DATA');
			}
		}
		self::markCell('ml8-refusals-end');
		$this->assertSame($sentBefore, $imap->state()['Sent']['exists'], 'nothing reached Sent');
		$imap->close();
	}

	/**
	 * When the copy to Sent fails after the SMTP server accepted the message, the outcome is sent but not copied, and the
	 * message stays in the Outbox with that status.
	 *
	 * @return void
	 */
	public function testAFailedCopyAfterAcceptanceIsSentNotCopied(): void {
		Server::get(GrantPolicy::class)->setGrant(MailActor::ALICE, 'mail', 'send', true);
		$own = $this->account(MailActor::ALICE);
		$gone = $this->mailbox($own, 'Sent gone');
		$this->sentBefore = (int)$this->select('mail_accounts', 'id', $own, 'id')[0]['sent_mailbox_id'];
		$imap = $this->imap();
		$imap->delete('Sent gone');
		$imap->close();
		$this->setSent($gone);
		$arguments = ['account_id' => $own, 'to' => ['ok-after@example.com'], 'subject' => 'Accepted, then no copy'];
		$sessions = count($this->sinkSessions());
		$plan = $this->call([self::TOOL, $arguments]);
		$this->assertSame($gone, $plan['structuredContent']['target']['sentMailboxId']);
		$done = $this->decoded($this->confirm($plan, $arguments));
		$this->assertSame(['sentNotCopied', true, false], [$done['outcome'], $done['sent'], $done['copiedToSent']]);
		$this->assertTrue($this->newSinkSessions($sessions, 1)[0]['answered'], 'the server accepted the message');
		$this->assertSame(11, (int)$this->select('mail_local_messages', 'id', (int)$done['outboxId'], 'id')[0]['status'], 'stays in the Outbox, not copied');
	}

	/**
	 * Another user's account reads like a missing one and a changed plan gives the new plan: neither reaches a server or
	 * the Outbox.
	 *
	 * @return void
	 */
	public function testRefusedCallsReachNoServer(): void {
		Server::get(GrantPolicy::class)->setGrant(MailActor::ALICE, 'mail', 'send', true);
		$own = $this->account(MailActor::ALICE);
		$sessions = count($this->sinkSessions());
		$outbox = $this->select('mail_local_messages', 'account_id', $own, 'id');
		$foreign = $this->call([self::TOOL, ['account_id' => $this->account(MailActor::BOB), 'to' => ['ok@example.com']]]);
		$this->assertSame(['content' => [['type' => 'text', 'text' => MailMessages::accountNotFound()]], 'isError' => true], $foreign);
		$arguments = ['account_id' => $own, 'to' => ['ok@example.com'], 'subject' => 'Planned'];
		$plan = $this->call([self::TOOL, $arguments]);
		$changed = $this->call([self::TOOL, ['subject' => 'Changed'] + $arguments + ['confirm' => true, PlanState::ARGUMENT => $plan['structuredContent'][PlanState::ARGUMENT]]]);
		$this->assertSame(MailSendMessages::planChanged(), $changed['structuredContent']['warnings'][0]['message']);
		usleep(300_000);
		$this->assertSame($sessions, count($this->sinkSessions()), 'no SMTP session');
		$this->assertSame($outbox, $this->select('mail_local_messages', 'account_id', $own, 'id'), 'nothing in the Outbox');
	}

	/**
	 * Names a folder as alice's Sent folder, as the account settings of the Mail app store it.
	 *
	 * @param int $mailboxId folder id
	 * @return void
	 */
	private function setSent(int $mailboxId): void {
		$qb = $this->official()->getQueryBuilder();
		$qb->update('mail_accounts')
			->set('sent_mailbox_id', $qb->createNamedParameter($mailboxId, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter(MailActor::ALICE)))
			->executeStatement();
	}

	/**
	 * One call as alice through the real registry.
	 *
	 * @param array{0:string, 1:array<string, mixed>} $call tool and arguments
	 * @return array<string, mixed> the MCP result
	 */
	private function call(array $call): array {
		return MailActor::run(MailActor::ALICE, 'calls', ['calls' => [$call]])[0]['result'];
	}

	/**
	 * The confirmed call of a plan.
	 *
	 * @param array<string, mixed> $plan the MCP result of the plan
	 * @param array<string, mixed> $arguments the arguments of the plan
	 * @return array<string, mixed> the MCP result of the confirmed call
	 */
	private function confirm(array $plan, array $arguments): array {
		$result = $this->call([self::TOOL, $arguments + ['confirm' => true, PlanState::ARGUMENT => $plan['structuredContent'][PlanState::ARGUMENT]]]);
		$this->assertArrayNotHasKey('isError', $result, json_encode($result, JSON_THROW_ON_ERROR));
		return $result;
	}
}
