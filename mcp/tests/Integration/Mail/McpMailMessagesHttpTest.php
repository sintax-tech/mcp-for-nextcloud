<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Mail;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Integration\Exclusion\ExclusionTestCase;
use OCP\Server;

/**
 * The message tools through the authenticated HTTP endpoint, with an app password (Basic) and an OAuth access token
 * (Bearer), no Mail class loaded into the requesting process: listed as read-only only with the `mail.messages` grant,
 * they list and read alice's messages, answer another user's message exactly like a missing one, and leave the Mail
 * tables of the account as they were.
 */
final class McpMailMessagesHttpTest extends ExclusionTestCase {
	use MailCellRows;
	use MailEndpointTools;

	/** The message tools. */
	private const TOOLS = ['mail_list_messages', 'mail_search_messages', 'mail_read_message', 'mail_read_thread'];

	/**
	 * Leaves alice without the messages grant, as the other Mail tests expect.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if (getenv('MCP_IT_WITH_MAIL') === '1') {
			Server::get(GrantPolicy::class)->setGrant(self::ALICE, 'mail', 'messages', false);
		}
		parent::tearDown();
	}

	/**
	 * Basic and Bearer: no message tool without the grant; with it, read-only tools that read own messages only.
	 *
	 * @return void
	 */
	public function testTheEndpointServesTheMessageToolsOnlyWithTheGrant(): void {
		if (getenv('MCP_IT_WITH_MAIL') !== '1') {
			$this->markTestSkipped('Opt-in disposable Mail cell only');
		}
		$policy = Server::get(GrantPolicy::class);
		$policy->setGrant(self::ALICE, 'mail', 'read', true);
		$own = $this->account(self::ALICE);
		$inbox = $this->mailbox($own, 'INBOX');
		$message = $this->subjects($inbox)['Unread message'];
		$foreign = $this->subjects($this->mailbox($this->account(self::BOB), 'INBOX'))['Message of bob'];
		$before = $this->cacheHash($own);
		foreach (['basic', 'bearer'] as $kind) {
			$policy->setGrant(self::ALICE, 'mail', 'messages', false);
			$this->assertSame([], array_values(array_intersect(self::TOOLS, array_column($this->toolsList($kind), 'name'))), "$kind without the grant");

			$policy->setGrant(self::ALICE, 'mail', 'messages', true);
			$listed = array_values(array_filter($this->toolsList($kind), static fn (array $tool): bool => in_array($tool['name'], self::TOOLS, true)));
			$this->assertSame(self::TOOLS, array_column($listed, 'name'), $kind);
			foreach ($listed as $tool) {
				$this->assertTrue($tool['annotations']['readOnlyHint'], "$kind {$tool['name']}");
			}
			$client = $this->mcp(self::ALICE, $kind);
			$page = $client->tool('mail_list_messages', ['mailbox_id' => $inbox]);
			$this->assertTrue($page->ok(), $page->describe());
			$this->assertContains($message, array_column($page->json()['messages'], 'id'));
			$read = $client->tool('mail_read_message', ['message_id' => $message]);
			$this->assertTrue($read->ok(), $read->describe());
			$this->assertSame('Body of Unread message.', trim($read->json()['message']['body']['text']));
			$this->assertTrue($read->json()['message']['unread'], 'still unread after reading');
			$other = $client->tool('mail_read_message', ['message_id' => $foreign]);
			$missing = $client->tool('mail_read_message', ['message_id' => 987654321]);
			$this->assertTrue($other->isError());
			$this->assertSame($missing->fingerprint(), $other->fingerprint());
		}
		$this->assertSame($before, $this->cacheHash($own), 'HTTP calls must leave the Mail tables of the account unchanged');
	}
}
