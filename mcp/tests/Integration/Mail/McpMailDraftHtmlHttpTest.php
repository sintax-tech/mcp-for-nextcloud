<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Exclusion\ExclusionTestCase;
use OCP\{Server, App\IAppManager};

/** Basic and Bearer both save only the confirmed sanitized HTML; no HTML preview appends. */
final class McpMailDraftHtmlHttpTest extends ExclusionTestCase {
	use MailCellRows;
	use MailEndpointTools;

	/** @return void */
	protected function tearDown(): void {
		if (getenv('MCP_IT_WITH_MAIL') === '1') {
			Server::get(GrantPolicy::class)->setGrant(self::ALICE, 'mail', 'draft', false);
		}
		parent::tearDown();
	}

	/** @return void */
	public function testBothAuthenticationPathsSaveTheApprovedHtml(): void {
		if (getenv('MCP_IT_WITH_MAIL') !== '1') {
			$this->markTestSkipped('Mail cell only (MCP_IT_WITH_MAIL=1)');
		}
		Server::get(IAppManager::class)->loadApp('mail');
		Server::get(GrantPolicy::class)->setGrant(self::ALICE, 'mail', 'draft', true);
		$account = $this->account(self::ALICE);
		$imap = $this->imap();
		try {
			foreach (['basic', 'bearer'] as $kind) {
				$client = $this->mcp(self::ALICE, $kind);
				$args = ['account_id' => $account, 'html' => true, 'body' => '<p>Olá <strong>ação</strong></p><img src="https://track.example/x">'];
				$before = $imap->state();
				$plan = $client->tool('mail_create_draft', $args);
				$this->assertTrue($plan->ok(), $plan->describe());
				$this->assertSame($before, $imap->state());
				$done = $client->tool('mail_create_draft', $args + ['confirm' => true, 'plan_state' => $plan->structured()['plan_state']]);
				$this->assertTrue($done->ok(), $done->describe());
				$after = $imap->state();
				$this->assertSame($before['Drafts']['exists'] + 1, $after['Drafts']['exists']);
				$new = array_values(array_diff(array_keys($after['Drafts']['flags']), array_keys($before['Drafts']['flags'])));
				$this->assertCount(1, $new);
				MailDraftHtmlMime::assertSaved($this, $imap->raw('Drafts', $new[0]), $plan->structured()['draft']['body']);
				$refused = $client->tool('mail_create_draft', ['body' => '<script>x()</script>'] + $args);
				$this->assertTrue($refused->isError());
				$this->assertSame($after, $imap->state());
			}
		} finally {
			$imap->close();
		}
	}
}
