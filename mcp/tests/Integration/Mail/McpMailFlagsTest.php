<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Mail;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tools\Mail\Flag\MailFlagMessages;
use OCA\Mcp\Tools\Mail\MailMessages;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

/**
 * mail_set_flags against the real Mail app and a real IMAP server (Mail cells only; mail/seed-flags.sh seeds the
 * Flags folder).
 *
 * Without `mail.flags` the tool is absent and uncallable. With it alone: a plan changes nothing on the server; a
 * confirmed call sets exactly the asked flag of exactly that message (every other folder, message and flag on the server
 * stays as it was) and the Mail cache follows; a flag already as asked changes nothing; a plan_state of another plan, a
 * cached UID of an older generation and another user's message never change a flag.
 */
#[Group('mail')]
final class McpMailFlagsTest extends IntegrationTestCase {
	use MailCellRows;

	/** The flag tool. */
	private const TOOL = 'mail_set_flags';

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
	 * Leaves alice without the flags and messages grants, as the other Mail tests expect.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if (getenv('MCP_IT_WITH_MAIL') === '1') {
			$policy = Server::get(GrantPolicy::class);
			$policy->setGrant(MailActor::ALICE, 'mail', 'flags', false);
			$policy->setGrant(MailActor::ALICE, 'mail', 'messages', false);
		}
		parent::tearDown();
	}

	/**
	 * With the messages grant but not the flags one, the tool is neither listed nor callable.
	 *
	 * @return void
	 */
	public function testWithoutTheGrantTheToolIsAbsent(): void {
		$policy = Server::get(GrantPolicy::class);
		$policy->setGrant(MailActor::ALICE, 'mail', 'messages', true);
		$policy->setGrant(MailActor::ALICE, 'mail', 'flags', false);
		$this->assertNotContains(self::TOOL, array_column(MailActor::run(MailActor::ALICE, 'tools'), 'name'));
		$target = $this->subjects($this->mailbox($this->account(MailActor::ALICE), 'Flags'))['Flag target'];
		$calls = MailActor::run(MailActor::ALICE, 'calls', ['calls' => [[self::TOOL, ['message_id' => $target, 'seen' => true]]]]);
		$this->assertSame(['refused' => true], $calls[0]['result']);
	}

	/**
	 * With the flags grant alone, a plan changes nothing; confirmed calls change exactly the asked flag and the cache
	 * follows; already-set flags, a foreign plan state, an older generation and another user's message change nothing.
	 *
	 * @return void
	 */
	public function testConfirmedCallsChangeExactlyTheAskedFlags(): void {
		Server::get(GrantPolicy::class)->setGrant(MailActor::ALICE, 'mail', 'flags', true);
		$own = $this->account(MailActor::ALICE);
		$folder = $this->mailbox($own, 'Flags');
		$bySubject = $this->subjects($folder);
		$uid = fn (int $id): int => (int)$this->select('mail_messages', 'id', $id, 'id')[0]['uid'];
		$foreign = $this->messageIds($this->mailbox($this->account(MailActor::BOB), 'INBOX'))[0];
		$regen = $this->subjects($this->mailbox($own, 'Regen'))['Regen original'];
		$imap = $this->imap();
		$before = $imap->state();

		self::markCell('ml5-flags-start');
		$plan = $this->call([self::TOOL, ['message_id' => $bySubject['Flag target'], 'seen' => true]]);
		$this->assertTrue($plan['structuredContent']['requiresConfirmation']);
		$this->assertArrayNotHasKey('subject', $plan['structuredContent']['target'], 'no subject without mail.messages');
		$this->assertSame($before, $imap->state(), 'a plan changes nothing on the server');

		$this->confirm($plan, ['message_id' => $bySubject['Flag target'], 'seen' => true]);
		$expected = $before;
		$expected['Flags']['flags'][$uid($bySubject['Flag target'])] = ['\\Seen'];
		$this->assertSameExceptModseq($expected, $imap->state(), 'only \\Seen of the target changed');
		$this->assertSame(1, (int)$this->select('mail_messages', 'id', $bySubject['Flag target'], 'id')[0]['flag_seen'], 'the Mail cache follows');

		$both = [self::TOOL, ['message_id' => $bySubject['Flag both'], 'flagged' => false]];
		$this->confirm($this->call($both), $both[1]);
		$expected['Flags']['flags'][$uid($bySubject['Flag both'])] = ['\\Answered', '\\Seen'];
		$this->assertSameExceptModseq($expected, $imap->state(), 'only \\Flagged of the other message was removed');

		$current = $imap->state();
		$already = [self::TOOL, ['message_id' => $bySubject['Flag already read'], 'seen' => true]];
		$done = $this->confirm($this->call($already), $already[1]);
		$this->assertSame([], json_decode($done['content'][0]['text'], true)['changed']);
		$this->assertSame($current, $imap->state(), 'an already-set flag changes nothing, not even a modification sequence');

		$other = $this->call([self::TOOL, ['message_id' => $bySubject['Flag target HTTP'], 'flagged' => true]]);
		$wrong = $this->call([self::TOOL, ['message_id' => $bySubject['Flag target HTTP'], 'seen' => true, 'confirm' => true, PlanState::ARGUMENT => $other['structuredContent'][PlanState::ARGUMENT]]]);
		$this->assertSame(MailFlagMessages::planChanged(), $wrong['structuredContent']['warnings'][0]['message'], 'the plan of other arguments confirms nothing');

		$stale = $this->call([self::TOOL, ['message_id' => $regen, 'seen' => true]]);
		$refused = $this->call([self::TOOL, ['message_id' => $regen, 'seen' => true, 'confirm' => true, PlanState::ARGUMENT => $stale['structuredContent'][PlanState::ARGUMENT]]]);
		$this->assertSame(['content' => [['type' => 'text', 'text' => MailMessages::messageChanged()]], 'isError' => true], $refused, 'never on a UID of another generation');
		$missing = $this->call([self::TOOL, ['message_id' => $foreign, 'seen' => true]]);
		$this->assertSame(['content' => [['type' => 'text', 'text' => MailMessages::messageNotFound()]], 'isError' => true], $missing, 'another user\'s message reads like a missing one');
		self::markCell('ml5-flags-end');
		$this->assertSame($current, $imap->state(), 'the refused calls changed nothing');
		$imap->close();
	}

	/**
	 * Asserts two server states are equal but for the highest modification sequences, which any STORE moves.
	 *
	 * @param array<string, array<string, mixed>> $expected expected state
	 * @param array<string, array<string, mixed>> $actual state read now
	 * @param string $message what is checked
	 * @return void
	 */
	private function assertSameExceptModseq(array $expected, array $actual, string $message): void {
		$strip = static fn (array $state): array => array_map(static fn (array $folder): array => array_diff_key($folder, ['highestmodseq' => 0]), $state);
		$this->assertSame($strip($expected), $strip($actual), $message);
		foreach ($expected as $name => $folder) {
			if ($name !== 'Flags') {
				$this->assertSame($folder['highestmodseq'] ?? null, $actual[$name]['highestmodseq'] ?? null, "$name untouched");
			}
		}
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
