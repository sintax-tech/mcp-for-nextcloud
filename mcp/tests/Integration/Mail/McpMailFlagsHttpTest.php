<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Mail;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Integration\Exclusion\ExclusionTestCase;
use OCA\Mcp\Tools\PlanState;
use OCP\Server;

/**
 * mail_set_flags through the authenticated HTTP endpoint, with an app password (Basic) and an OAuth access token
 * (Bearer): absent with the messages grant alone, listed as a write with the flags grant alone; its plan changes
 * nothing and the confirmed call with the plan's state sets exactly that flag on the server, then clears it; another
 * user's message reads like a missing one.
 */
final class McpMailFlagsHttpTest extends ExclusionTestCase {
	use MailCellRows;
	use MailEndpointTools;

	/** The flag tool. */
	private const TOOL = 'mail_set_flags';

	/**
	 * Leaves alice without the flags and messages grants, as the other Mail tests expect.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if (getenv('MCP_IT_WITH_MAIL') === '1') {
			$policy = Server::get(GrantPolicy::class);
			$policy->setGrant(self::ALICE, 'mail', 'flags', false);
			$policy->setGrant(self::ALICE, 'mail', 'messages', false);
		}
		parent::tearDown();
	}

	/**
	 * Basic and Bearer: no flag tool with messages alone; with flags alone, plan and confirmed change on the server.
	 *
	 * @return void
	 */
	public function testTheEndpointPlansAndSetsFlags(): void {
		if (getenv('MCP_IT_WITH_MAIL') !== '1') {
			$this->markTestSkipped('Opt-in disposable Mail cell only');
		}
		$policy = Server::get(GrantPolicy::class);
		$own = $this->account(self::ALICE);
		$target = $this->subjects($this->mailbox($own, 'Flags'))['Flag target HTTP'];
		$uid = (int)$this->select('mail_messages', 'id', $target, 'id')[0]['uid'];
		$foreign = $this->subjects($this->mailbox($this->account(self::BOB), 'INBOX'))['Message of bob'];
		$imap = $this->imap();
		foreach (['basic' => true, 'bearer' => false] as $kind => $flagged) {
			$policy->setGrant(self::ALICE, 'mail', 'messages', true);
			$policy->setGrant(self::ALICE, 'mail', 'flags', false);
			$this->assertNotContains(self::TOOL, array_column($this->toolsList($kind), 'name'), "$kind with messages only");

			$policy->setGrant(self::ALICE, 'mail', 'messages', false);
			$policy->setGrant(self::ALICE, 'mail', 'flags', true);
			$tool = array_values(array_filter($this->toolsList($kind), static fn (array $tool): bool => $tool['name'] === self::TOOL))[0];
			$this->assertFalse($tool['annotations']['readOnlyHint'] ?? false, $kind);
			$client = $this->mcp(self::ALICE, $kind);
			$before = $imap->state();
			$plan = $client->tool(self::TOOL, ['message_id' => $target, 'flagged' => $flagged]);
			$this->assertTrue($plan->ok(), $plan->describe());
			$this->assertTrue($plan->structured()['requiresConfirmation']);
			$this->assertSame($before, $imap->state(), "$kind: a plan changes nothing");
			$done = $client->tool(self::TOOL, ['message_id' => $target, 'flagged' => $flagged, 'confirm' => true, PlanState::ARGUMENT => $plan->structured()[PlanState::ARGUMENT]]);
			$this->assertTrue($done->ok(), $done->describe());
			$this->assertSame($flagged ? ['\\Flagged'] : [], $imap->state()['Flags']['flags'][$uid], "$kind: exactly \\Flagged " . ($flagged ? 'set' : 'cleared'));
			$other = $client->tool(self::TOOL, ['message_id' => $foreign, 'seen' => true]);
			$missing = $client->tool(self::TOOL, ['message_id' => 987654321, 'seen' => true]);
			$this->assertTrue($other->isError());
			$this->assertSame($missing->fingerprint(), $other->fingerprint());
		}
		$imap->close();
	}
}
