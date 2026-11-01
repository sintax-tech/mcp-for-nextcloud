<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Mail;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tools\Mail\Draft\MailDraftMessages;
use OCA\Mcp\Tools\Mail\MailMessages;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

/**
 * mail_create_draft against the real Mail app and a real IMAP server (Mail cells only; mail/seed-drafts.sh creates
 * alice's Drafts folder; the cell has no SMTP server listening).
 *
 * Without `mail.draft` the tool is absent and uncallable. With it alone: a plan saves nothing; a confirmed call appends
 * exactly one message with \Draft to alice's Drafts folder, with the planned sender, recipients (Bcc included, which
 * the cell's Mail 5.12.5 serializes: the control of MailDraftBcc), subject and text, writes
 * nothing to the Mail app's Outbox (no local message) and changes no other folder; confirming the same plan again creates
 * a second draft; a changed draft and another user's account save nothing. (An account without Drafts is a unit case:
 * the pinned IMAP image creates Drafts for every user at the first login.)
 */
#[Group('mail')]
final class McpMailDraftTest extends IntegrationTestCase {
	use MailCellRows;

	/** The draft tool. */
	private const TOOL = 'mail_create_draft';

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
	 * Leaves alice without the draft and messages grants, as the other Mail tests expect.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if (getenv('MCP_IT_WITH_MAIL') === '1') {
			$policy = Server::get(GrantPolicy::class);
			$policy->setGrant(MailActor::ALICE, 'mail', 'draft', false);
			$policy->setGrant(MailActor::ALICE, 'mail', 'messages', false);
		}
		parent::tearDown();
	}

	/**
	 * With every other Mail grant but not the draft one, the tool is neither listed nor callable.
	 *
	 * @return void
	 */
	public function testWithoutTheGrantTheToolIsAbsent(): void {
		$policy = Server::get(GrantPolicy::class);
		$policy->setGrant(MailActor::ALICE, 'mail', 'messages', true);
		$policy->setGrant(MailActor::ALICE, 'mail', 'draft', false);
		$this->assertNotContains(self::TOOL, array_column(MailActor::run(MailActor::ALICE, 'tools'), 'name'));
		$calls = MailActor::run(MailActor::ALICE, 'calls', ['calls' => [[self::TOOL, ['account_id' => $this->account(MailActor::ALICE)]]]]);
		$this->assertSame(['refused' => true], $calls[0]['result']);
	}

	/**
	 * With the draft grant alone, a plan saves nothing; the confirmed call appends exactly the planned draft to Drafts,
	 * nothing to the Outbox, nothing elsewhere; a second confirmation creates a second draft; refused calls save nothing.
	 *
	 * @return void
	 */
	public function testAConfirmedCallSavesExactlyThePlannedDraft(): void {
		$policy = Server::get(GrantPolicy::class);
		$policy->setGrant(MailActor::ALICE, 'mail', 'draft', true);
		$own = $this->account(MailActor::ALICE);
		$drafts = $this->mailbox($own, 'Drafts');
		$this->assertSame($drafts, (int)$this->select('mail_accounts', 'id', $own, 'id')[0]['drafts_mailbox_id'], 'the Mail app took Drafts as the account\'s');
		$arguments = ['account_id' => $own, 'to' => ['bob@example.com'], 'cc' => ['carol@example.com'], 'bcc' => ['dave@example.com'],
			'subject' => 'Draft from MCP', 'body' => "Hello Bob,\nthis is only a draft.\n"];
		$imap = $this->imap();
		$before = $imap->state();
		$outbox = $this->select('mail_local_messages', 'account_id', $own, 'id');

		self::markCell('ml7-draft-start');
		$plan = $this->call([self::TOOL, $arguments]);
		$this->assertTrue($plan['structuredContent']['requiresConfirmation']);
		$this->assertSame(['accountId' => $own, 'draftsMailboxId' => $drafts, 'account' => 'alice@example.com', 'draftsPath' => 'Drafts'], $plan['structuredContent']['target']);
		$this->assertSame($before, $imap->state(), 'a plan saves nothing');

		$done = $this->decoded($this->confirm($plan, $arguments));
		$this->assertSame([true, false, $drafts], [$done['created'], $done['sent'], $done['draftsMailboxId']]);
		$this->assertIsInt($done['uid'], 'the server tells the new UID (APPENDUID)');
		$after = $imap->state();
		$this->assertSame($before['Drafts']['exists'] + 1, $after['Drafts']['exists'], 'exactly one more message in Drafts');
		$this->assertContains('\\Draft', $after['Drafts']['flags'][$done['uid']]);
		$raw = $imap->raw('Drafts', $done['uid']);
		foreach (['/^From: .*alice@example\.com/mi', '/^To: bob@example\.com/mi', '/^Cc: carol@example\.com/mi', '/^Bcc: dave@example\.com/mi', '/^Subject: Draft from MCP/mi', '/^Message-ID: </mi', '/Hello Bob,/'] as $pattern) {
			$this->assertMatchesRegularExpression($pattern, $raw);
		}
		$this->assertSame($outbox, $this->select('mail_local_messages', 'account_id', $own, 'id'), 'nothing in the Outbox: no local message');
		$others = static fn (array $state): array => array_diff_key($state, ['Drafts' => 0]);
		$this->assertSame($others($before), $others($after), 'every other folder, Sent included, is untouched');

		$this->decoded($this->confirm($plan, $arguments));
		$this->assertSame($before['Drafts']['exists'] + 2, $imap->state()['Drafts']['exists'], 'confirming the same plan again creates a second draft');

		$current = $imap->state();
		$changed = $this->call([self::TOOL, ['subject' => 'Another subject'] + $arguments + ['confirm' => true, PlanState::ARGUMENT => $plan['structuredContent'][PlanState::ARGUMENT]]]);
		$this->assertSame(MailDraftMessages::planChanged(), $changed['structuredContent']['warnings'][0]['message'], 'a changed draft is not saved');
		$foreign = $this->call([self::TOOL, ['account_id' => $this->account(MailActor::BOB)]]);
		$this->assertSame(['content' => [['type' => 'text', 'text' => MailMessages::accountNotFound()]], 'isError' => true], $foreign, 'another user\'s account reads like a missing one');
		self::markCell('ml7-draft-end');
		$this->assertSame($current, $imap->state(), 'the refused calls saved nothing');
		$imap->close();
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
