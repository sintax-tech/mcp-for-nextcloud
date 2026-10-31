<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Mail;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Integration\Exclusion\ExclusionTestCase;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCP\Server;

/**
 * mail_create_draft through the authenticated HTTP endpoint, with an app password (Basic) and an OAuth access token
 * (Bearer): absent with the move grant alone, listed as a write with the draft grant alone; its plan saves nothing and
 * the confirmed call with the plan's state appends exactly one draft with that subject to alice's Drafts folder; another
 * user's account reads like a missing one.
 */
final class McpMailDraftHttpTest extends ExclusionTestCase {
	use MailCellRows;
	use MailEndpointTools;

	/** The draft tool. */
	private const TOOL = 'mail_create_draft';

	/**
	 * Leaves alice without the draft and move grants, as the other Mail tests expect.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if (getenv('MCP_IT_WITH_MAIL') === '1') {
			$policy = Server::get(GrantPolicy::class);
			$policy->setGrant(self::ALICE, 'mail', 'draft', false);
			$policy->setGrant(self::ALICE, 'mail', 'move', false);
		}
		parent::tearDown();
	}

	/**
	 * Basic and Bearer: no draft tool with move alone; with draft alone, plan and confirmed draft on the server.
	 *
	 * @return void
	 */
	public function testTheEndpointPlansAndSavesADraft(): void {
		if (getenv('MCP_IT_WITH_MAIL') !== '1') {
			$this->markTestSkipped('Opt-in disposable Mail cell only');
		}
		$policy = Server::get(GrantPolicy::class);
		$own = $this->account(self::ALICE);
		$imap = $this->imap();
		foreach (['basic', 'bearer'] as $kind) {
			$policy->setGrant(self::ALICE, 'mail', 'move', true);
			$policy->setGrant(self::ALICE, 'mail', 'draft', false);
			$this->assertNotContains(self::TOOL, array_column($this->toolsList($kind), 'name'), "$kind with move only");

			$policy->setGrant(self::ALICE, 'mail', 'move', false);
			$policy->setGrant(self::ALICE, 'mail', 'draft', true);
			$tool = array_values(array_filter($this->toolsList($kind), static fn (array $tool): bool => $tool['name'] === self::TOOL))[0];
			$this->assertFalse($tool['annotations']['readOnlyHint'] ?? false, $kind);
			$client = $this->mcp(self::ALICE, $kind);
			$arguments = ['account_id' => $own, 'to' => ['bob@example.com'], 'subject' => "Draft over $kind", 'body' => 'Only a draft.'];
			$before = $imap->state();
			$plan = $client->tool(self::TOOL, $arguments);
			$this->assertTrue($plan->ok(), $plan->describe());
			$this->assertTrue($plan->structured()['requiresConfirmation']);
			$this->assertSame($before, $imap->state(), "$kind: a plan saves nothing");
			$done = $client->tool(self::TOOL, $arguments + ['confirm' => true, PlanState::ARGUMENT => $plan->structured()[PlanState::ARGUMENT]]);
			$this->assertTrue($done->ok(), $done->describe());
			$after = $imap->state();
			$this->assertSame($before['Drafts']['exists'] + 1, $after['Drafts']['exists'], "$kind: one more draft");
			$new = array_values(array_diff(array_keys($after['Drafts']['flags']), array_keys($before['Drafts']['flags'])));
			$this->assertCount(1, $new);
			$this->assertMatchesRegularExpression("/^Subject: Draft over $kind/mi", $imap->raw('Drafts', $new[0]));
			$other = $client->tool(self::TOOL, ['account_id' => $this->account(self::BOB)]);
			$missing = $client->tool(self::TOOL, ['account_id' => 987654321]);
			$this->assertTrue($other->isError());
			$this->assertSame($missing->fingerprint(), $other->fingerprint());
		}
		$imap->close();
	}
}
