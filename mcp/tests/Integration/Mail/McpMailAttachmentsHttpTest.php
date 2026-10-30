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
 * mail_read_attachment through the authenticated HTTP endpoint, with an app password (Basic) and an OAuth access token
 * (Bearer), no Mail class loaded into the requesting process: absent with the messages grant alone, listed as read-only
 * with the attachments grant alone, it reads the text of alice's attachment, answers another user's message exactly
 * like a missing one, leaves the message unread and the Mail tables of the account as they were.
 */
final class McpMailAttachmentsHttpTest extends ExclusionTestCase {
	use MailCellRows;
	use MailEndpointTools;

	/** The attachment tool. */
	private const TOOL = 'mail_read_attachment';

	/**
	 * Leaves alice without the messages and attachments grants, as the other Mail tests expect.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if (getenv('MCP_IT_WITH_MAIL') === '1') {
			$policy = Server::get(GrantPolicy::class);
			$policy->setGrant(self::ALICE, 'mail', 'messages', false);
			$policy->setGrant(self::ALICE, 'mail', 'attachments', false);
		}
		parent::tearDown();
	}

	/**
	 * Basic and Bearer: no attachment tool with the messages grant alone; with the attachments grant alone, a read-only
	 * tool that reads own attachments only.
	 *
	 * @return void
	 */
	public function testTheEndpointServesTheAttachmentToolOnlyWithItsGrant(): void {
		if (getenv('MCP_IT_WITH_MAIL') !== '1') {
			$this->markTestSkipped('Opt-in disposable Mail cell only');
		}
		$policy = Server::get(GrantPolicy::class);
		$policy->setGrant(self::ALICE, 'mail', 'read', true);
		$own = $this->account(self::ALICE);
		$attachments = $this->mailbox($own, 'Attachments');
		$message = $this->subjects($attachments)['Attachment csv'];
		$foreign = $this->subjects($this->mailbox($this->account(self::BOB), 'INBOX'))['Message of bob'];
		$before = $this->cacheHash($own);
		foreach (['basic', 'bearer'] as $kind) {
			$policy->setGrant(self::ALICE, 'mail', 'messages', true);
			$policy->setGrant(self::ALICE, 'mail', 'attachments', false);
			$this->assertNotContains(self::TOOL, array_column($this->toolsList($kind), 'name'), "$kind with messages only");

			$policy->setGrant(self::ALICE, 'mail', 'messages', false);
			$policy->setGrant(self::ALICE, 'mail', 'attachments', true);
			$listed = array_values(array_filter($this->toolsList($kind), static fn (array $tool): bool => $tool['name'] === self::TOOL));
			$this->assertCount(1, $listed, $kind);
			$this->assertTrue($listed[0]['annotations']['readOnlyHint'], $kind);
			$client = $this->mcp(self::ALICE, $kind);
			$read = $client->tool(self::TOOL, ['message_id' => $message, 'attachment_id' => '2']);
			$this->assertTrue($read->ok(), $read->describe());
			$this->assertSame("nome;cidade\nJoão;São Paulo\n", $read->json()['attachment']['text'], $kind);
			$other = $client->tool(self::TOOL, ['message_id' => $foreign, 'attachment_id' => '2']);
			$missing = $client->tool(self::TOOL, ['message_id' => 987654321, 'attachment_id' => '2']);
			$this->assertTrue($other->isError());
			$this->assertSame($missing->fingerprint(), $other->fingerprint());
		}
		$this->assertSame(0, (int)$this->select('mail_messages', 'id', $message, 'id')[0]['flag_seen'], 'the message stays unread in the Mail cache');
		$this->assertSame($before, $this->cacheHash($own), 'HTTP calls must leave the Mail tables of the account unchanged');
	}
}
