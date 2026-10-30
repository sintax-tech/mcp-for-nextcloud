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
use OCA\Mcp\Tools\Mail\Move\MailMoveMessages;
use OCA\Mcp\Tools\PlanState;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

/**
 * mail_move_message against the real Mail app and a real IMAP server (Mail cells only; mail/seed-move.sh seeds the Move
 * folder and the folders its messages go to).
 *
 * Without `mail.move` the tool is absent and uncallable. With it alone: a plan changes nothing on the server; a confirmed
 * call takes exactly that message out of its folder and puts it, same Message-ID under a new UID, into the destination
 * (every other folder stays as it was) and the Mail cache drops the source row; repeating it moves nothing. The Trash is
 * allowed with the warning of the Mail app's retention, and a retention changed after the plan is never confirmed
 * silently; the Junk folder is a plain move without the junk keyword; the message's own folder moves nothing; a cached
 * UID of an older generation, another user's message and another user's folder never move a message.
 */
#[Group('mail')]
final class McpMailMoveTest extends IntegrationTestCase {
	use MailCellRows;

	/** The move tool. */
	private const TOOL = 'mail_move_message';

	/** Folders the move tests change; every other folder must stay as it was. */
	private const MOVE_FOLDERS = ['Move', 'Move target', 'Trash', 'Junk'];

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
	 * Leaves alice without the move, flags and messages grants and without a Trash retention, as the other Mail tests
	 * expect.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if (getenv('MCP_IT_WITH_MAIL') === '1') {
			$policy = Server::get(GrantPolicy::class);
			$policy->setGrant(MailActor::ALICE, 'mail', 'move', false);
			$policy->setGrant(MailActor::ALICE, 'mail', 'flags', false);
			$policy->setGrant(MailActor::ALICE, 'mail', 'messages', false);
			$this->retention(null);
		}
		parent::tearDown();
	}

	/**
	 * With the messages and flags grants but not the move one, the tool is neither listed nor callable.
	 *
	 * @return void
	 */
	public function testWithoutTheGrantTheToolIsAbsent(): void {
		$policy = Server::get(GrantPolicy::class);
		$policy->setGrant(MailActor::ALICE, 'mail', 'messages', true);
		$policy->setGrant(MailActor::ALICE, 'mail', 'flags', true);
		$policy->setGrant(MailActor::ALICE, 'mail', 'move', false);
		$this->assertNotContains(self::TOOL, array_column(MailActor::run(MailActor::ALICE, 'tools'), 'name'));
		$own = $this->account(MailActor::ALICE);
		$target = $this->subjects($this->mailbox($own, 'Move'))['Move stays'];
		$calls = MailActor::run(MailActor::ALICE, 'calls', ['calls' => [[self::TOOL, ['message_id' => $target, 'mailbox_id' => $this->mailbox($own, 'Move target')]]]]);
		$this->assertSame(['refused' => true], $calls[0]['result']);
	}

	/**
	 * With the move grant alone, plans change nothing and confirmed moves change exactly the moved messages; the Trash
	 * warns, the Junk folder notes, the own folder moves nothing, and the refused calls change nothing.
	 *
	 * @return void
	 */
	public function testConfirmedMovesMoveExactlyThatMessage(): void {
		Server::get(GrantPolicy::class)->setGrant(MailActor::ALICE, 'mail', 'move', true);
		$own = $this->account(MailActor::ALICE);
		$folders = array_combine(self::MOVE_FOLDERS, array_map(fn (string $name): int => $this->mailbox($own, $name), self::MOVE_FOLDERS));
		$settings = $this->select('mail_accounts', 'id', $own, 'id')[0];
		$this->assertSame([$folders['Trash'], $folders['Junk']], [(int)$settings['trash_mailbox_id'], (int)$settings['junk_mailbox_id']], 'the Mail app took Trash and Junk as the account\'s');
		$bySubject = $this->subjects($folders['Move']);
		$imap = $this->imap();
		$before = $imap->state();

		self::markCell('ml6-move-start');
		$arguments = ['message_id' => $bySubject['Move to folder'], 'mailbox_id' => $folders['Move target']];
		$plan = $this->call([self::TOOL, $arguments]);
		$this->assertTrue($plan['structuredContent']['requiresConfirmation']);
		$this->assertArrayNotHasKey('subject', $plan['structuredContent']['target'], 'no subject without mail.messages');
		$this->assertSame(['mailboxId' => $folders['Move target'], 'path' => 'Move target', 'kind' => 'folder'], $plan['structuredContent']['to']);
		$this->assertSame($before, $imap->state(), 'a plan changes nothing on the server');

		$done = $this->decoded($this->confirm($plan, $arguments));
		$this->assertTrue($done['moved']);
		$this->assertIsInt($done['newUid'], 'the server tells the new UID (COPYUID)');
		$this->assertMovedTo($imap, '<Move-to-folder@mcp-it.example>', 'Move target', $done['newUid']);
		$this->assertSame([], $this->select('mail_messages', 'id', $bySubject['Move to folder'], 'id'), 'the Mail cache dropped the source row');
		$this->assertOthersUntouched($before, $imap->state());

		$current = $imap->state();
		$again = $this->call([self::TOOL, $arguments + ['confirm' => true, PlanState::ARGUMENT => $plan['structuredContent'][PlanState::ARGUMENT]]]);
		$this->assertSame(['content' => [['type' => 'text', 'text' => MailMessages::messageNotFound()]], 'isError' => true], $again, 'a repeated confirmation moves nothing');
		$this->assertSame($current, $imap->state());

		$trash = ['message_id' => $bySubject['Move to trash'], 'mailbox_id' => $folders['Trash']];
		$this->retention(30);
		$plan = $this->call([self::TOOL, $trash]);
		$this->assertSame(['mailboxId' => $folders['Trash'], 'path' => 'Trash', 'kind' => 'trash', 'retentionDays' => 30], $plan['structuredContent']['to']);
		$this->assertSame([MailMoveMessages::trashNote(30)], $plan['structuredContent']['notes']);
		$this->retention(null);
		$changed = $this->call([self::TOOL, $trash + ['confirm' => true, PlanState::ARGUMENT => $plan['structuredContent'][PlanState::ARGUMENT]]]);
		$this->assertSame(MailMoveMessages::planChanged(), $changed['structuredContent']['warnings'][0]['message'], 'a retention changed after the plan is not confirmed silently');
		$this->assertSame([MailMoveMessages::trashNote(null)], $changed['structuredContent']['notes']);
		$this->assertSame($current, $imap->state(), 'nothing moved on a changed warning');
		$done = $this->decoded($this->confirm($changed, $trash));
		$this->assertMovedTo($imap, '<Move-to-trash@mcp-it.example>', 'Trash', $done['newUid']);

		$junk = ['message_id' => $bySubject['Move to junk'], 'mailbox_id' => $folders['Junk']];
		$plan = $this->call([self::TOOL, $junk]);
		$this->assertSame([MailMoveMessages::junkNote()], $plan['structuredContent']['notes']);
		$done = $this->decoded($this->confirm($plan, $junk));
		$this->assertMovedTo($imap, '<Move-to-junk@mcp-it.example>', 'Junk', $done['newUid']);
		$this->assertSame([], $imap->state()['Junk']['flags'][$done['newUid']], 'no $junk keyword: moving reports nothing');

		$current = $imap->state();
		$same = ['message_id' => $bySubject['Move stays'], 'mailbox_id' => $folders['Move']];
		$plan = $this->call([self::TOOL, $same]);
		$this->assertFalse($plan['structuredContent']['moves']);
		$this->assertFalse($this->decoded($this->confirm($plan, $same))['moved']);

		$regen = $this->subjects($this->mailbox($own, 'Regen'))['Regen original'];
		$stale = $this->call([self::TOOL, ['message_id' => $regen, 'mailbox_id' => $folders['Move target']]]);
		$refused = $this->call([self::TOOL, ['message_id' => $regen, 'mailbox_id' => $folders['Move target'], 'confirm' => true, PlanState::ARGUMENT => $stale['structuredContent'][PlanState::ARGUMENT]]]);
		$this->assertSame(['content' => [['type' => 'text', 'text' => MailMessages::messageChanged()]], 'isError' => true], $refused, 'never a UID of another generation');
		$foreign = $this->messageIds($this->mailbox($this->account(MailActor::BOB), 'INBOX'))[0];
		$missing = $this->call([self::TOOL, ['message_id' => $foreign, 'mailbox_id' => $folders['Move target']]]);
		$this->assertSame(['content' => [['type' => 'text', 'text' => MailMessages::messageNotFound()]], 'isError' => true], $missing, 'another user\'s message reads like a missing one');
		$theirs = $this->call([self::TOOL, ['message_id' => $bySubject['Move stays'], 'mailbox_id' => $this->mailbox($this->account(MailActor::BOB), 'INBOX')]]);
		$this->assertSame(['content' => [['type' => 'text', 'text' => MailMoveMessages::folderNotFound()]], 'isError' => true], $theirs, 'another user\'s folder reads like a missing one');
		self::markCell('ml6-move-end');
		$this->assertSame($current, $imap->state(), 'the own folder and the refused calls changed nothing');
		$this->assertContains('<Move-stays@mcp-it.example>', $imap->messageIds('Move'));
		$imap->close();
	}

	/**
	 * Asserts the server holds the message, by its Message-ID, in the destination at the new UID and no more in Move.
	 *
	 * @param ImapProbe $imap probe of alice's mailbox
	 * @param string $messageId Message-ID of the message
	 * @param string $destination destination folder
	 * @param int $newUid UID the tool reported
	 * @return void
	 */
	private function assertMovedTo(ImapProbe $imap, string $messageId, string $destination, int $newUid): void {
		$this->assertNotContains($messageId, $imap->messageIds('Move'), "$messageId left Move");
		$this->assertSame($messageId, $imap->messageIds($destination)[$newUid] ?? null, "$messageId is in $destination at UID $newUid");
	}

	/**
	 * Asserts every folder the move tests do not use is exactly as before, modification sequences included.
	 *
	 * @param array<string, array<string, mixed>> $before state before the moves
	 * @param array<string, array<string, mixed>> $after state read now
	 * @return void
	 */
	private function assertOthersUntouched(array $before, array $after): void {
		$others = static fn (array $state): array => array_diff_key($state, array_flip(self::MOVE_FOLDERS));
		$this->assertSame($others($before), $others($after), 'every other folder is untouched');
	}

	/**
	 * Sets the Trash retention of alice's account as the Mail app stores it.
	 *
	 * @param int|null $days days, or null for none
	 * @return void
	 */
	private function retention(?int $days): void {
		$qb = $this->official()->getQueryBuilder();
		$qb->update('mail_accounts')
			->set('trash_retention_days', $qb->createNamedParameter($days, $days === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT))
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
