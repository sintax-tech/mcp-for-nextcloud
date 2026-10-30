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
 * mail_send_message through the authenticated HTTP endpoint, with an app password (Basic) and an OAuth access token
 * (Bearer): absent with the draft grant alone, listed as a write with the send grant alone; its plan reaches no SMTP
 * server and the confirmed call with the plan's state hands exactly that message to the cell's SMTP sink; another
 * user's account reads like a missing one.
 */
final class McpMailSendHttpTest extends ExclusionTestCase {
	use MailCellRows;
	use MailEndpointTools;
	use MailSinkSessions;

	/** The send tool. */
	private const TOOL = 'mail_send_message';

	/**
	 * Leaves alice without the send and draft grants, as the other Mail tests expect.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if (getenv('MCP_IT_WITH_MAIL') === '1') {
			$policy = Server::get(GrantPolicy::class);
			$policy->setGrant(self::ALICE, 'mail', 'send', false);
			$policy->setGrant(self::ALICE, 'mail', 'draft', false);
		}
		parent::tearDown();
	}

	/**
	 * Basic and Bearer: no send tool with draft alone; with send alone, a plan reaches no server and the confirmed call
	 * sends.
	 *
	 * @return void
	 */
	public function testTheEndpointPlansAndSends(): void {
		if (getenv('MCP_IT_WITH_MAIL') !== '1') {
			$this->markTestSkipped('Opt-in disposable Mail cell only');
		}
		$policy = Server::get(GrantPolicy::class);
		$own = $this->account(self::ALICE);
		foreach (['basic', 'bearer'] as $kind) {
			$policy->setGrant(self::ALICE, 'mail', 'draft', true);
			$policy->setGrant(self::ALICE, 'mail', 'send', false);
			$this->assertNotContains(self::TOOL, array_column($this->toolsList($kind), 'name'), "$kind with draft only");

			$policy->setGrant(self::ALICE, 'mail', 'draft', false);
			$policy->setGrant(self::ALICE, 'mail', 'send', true);
			$tool = array_values(array_filter($this->toolsList($kind), static fn (array $tool): bool => $tool['name'] === self::TOOL))[0];
			$this->assertFalse($tool['annotations']['readOnlyHint'] ?? false, $kind);
			$client = $this->mcp(self::ALICE, $kind);
			$arguments = ['account_id' => $own, 'to' => ["ok-$kind@example.com"], 'subject' => "Sent over $kind", 'body' => 'Test sink only.'];
			$sessions = count($this->sinkSessions());
			$plan = $client->tool(self::TOOL, $arguments);
			$this->assertTrue($plan->ok(), $plan->describe());
			$this->assertTrue($plan->structured()['requiresConfirmation']);
			$this->assertSame($sessions, count($this->sinkSessions()), "$kind: a plan reaches no SMTP server");
			$done = $client->tool(self::TOOL, $arguments + ['confirm' => true, PlanState::ARGUMENT => $plan->structured()[PlanState::ARGUMENT]]);
			$this->assertTrue($done->ok(), $done->describe());
			$session = $this->newSinkSessions($sessions, 1)[0];
			$this->assertSame(["ok-$kind@example.com"], array_column($session['rcpt'], 'address'), $kind);
			$this->assertMatchesRegularExpression("/^Subject: Sent over $kind/mi", (string)$session['message']);
			$other = $client->tool(self::TOOL, ['account_id' => $this->account(self::BOB), 'to' => ['ok@example.com']]);
			$missing = $client->tool(self::TOOL, ['account_id' => 987654321, 'to' => ['ok@example.com']]);
			$this->assertTrue($other->isError());
			$this->assertSame($missing->fingerprint(), $other->fingerprint());
		}
	}
}
