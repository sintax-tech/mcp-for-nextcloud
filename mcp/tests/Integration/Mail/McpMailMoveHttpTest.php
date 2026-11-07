<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Mail;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Exclusion\ExclusionTestCase;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCP\Server;

/**
 * mail_move_message through the authenticated HTTP endpoint, with an app password (Basic) and an OAuth access token
 * (Bearer): absent with the flags grant alone, listed as a write with the move grant alone; its plan changes nothing and
 * the confirmed call with the plan's state moves exactly that message, by its Message-ID, into the destination under a
 * new UID; another user's message reads like a missing one.
 */
final class McpMailMoveHttpTest extends ExclusionTestCase {
	use MailCellRows;
	use MailEndpointTools;

	/** The move tool. */
	private const TOOL = 'mail_move_message';

	/**
	 * Leaves alice without the move and flags grants, as the other Mail tests expect.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if (getenv('MCP_IT_WITH_MAIL') === '1') {
			$policy = Server::get(GrantPolicy::class);
			$policy->setGrant(self::ALICE, 'mail', 'move', false);
			$policy->setGrant(self::ALICE, 'mail', 'flags', false);
		}
		parent::tearDown();
	}

	/**
	 * Basic and Bearer: no move tool with flags alone; with move alone, plan and confirmed move on the server.
	 *
	 * @return void
	 */
	public function testTheEndpointPlansAndMoves(): void {
		if (getenv('MCP_IT_WITH_MAIL') !== '1') {
			$this->markTestSkipped('Opt-in disposable Mail cell only');
		}
		$policy = Server::get(GrantPolicy::class);
		$own = $this->account(self::ALICE);
		$messages = $this->subjects($this->mailbox($own, 'Move'));
		$destination = $this->mailbox($own, 'Move target');
		$foreign = $this->subjects($this->mailbox($this->account(self::BOB), 'INBOX'))['Message of bob'];
		$imap = $this->imap();
		foreach (['basic' => 'Move HTTP basic', 'bearer' => 'Move HTTP bearer'] as $kind => $subject) {
			$policy->setGrant(self::ALICE, 'mail', 'flags', true);
			$policy->setGrant(self::ALICE, 'mail', 'move', false);
			$this->assertNotContains(self::TOOL, array_column($this->toolsList($kind), 'name'), "$kind with flags only");

			$policy->setGrant(self::ALICE, 'mail', 'flags', false);
			$policy->setGrant(self::ALICE, 'mail', 'move', true);
			$tool = array_values(array_filter($this->toolsList($kind), static fn (array $tool): bool => $tool['name'] === self::TOOL))[0];
			$this->assertFalse($tool['annotations']['readOnlyHint'] ?? false, $kind);
			$client = $this->mcp(self::ALICE, $kind);
			$arguments = ['message_id' => $messages[$subject], 'mailbox_id' => $destination];
			$before = $imap->state();
			$plan = $client->tool(self::TOOL, $arguments);
			$this->assertTrue($plan->ok(), $plan->describe());
			$this->assertTrue($plan->structured()['requiresConfirmation']);
			$this->assertSame($before, $imap->state(), "$kind: a plan changes nothing");
			$done = $client->tool(self::TOOL, $arguments + ['confirm' => true, PlanState::ARGUMENT => $plan->structured()[PlanState::ARGUMENT]]);
			$this->assertTrue($done->ok(), $done->describe());
			$messageId = '<' . str_replace(' ', '-', $subject) . '@mcp-it.example>';
			$this->assertNotContains($messageId, $imap->messageIds('Move'), "$kind: the message left Move");
			$this->assertContains($messageId, $imap->messageIds('Move target'), "$kind: the message is in Move target");
			$other = $client->tool(self::TOOL, ['message_id' => $foreign, 'mailbox_id' => $destination]);
			$missing = $client->tool(self::TOOL, ['message_id' => 987654321, 'mailbox_id' => $destination]);
			$this->assertTrue($other->isError());
			$this->assertSame($missing->fingerprint(), $other->fingerprint());
		}
		$imap->close();
	}
}
