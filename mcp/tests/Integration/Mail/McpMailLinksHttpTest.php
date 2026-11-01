<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Mail;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Exclusion\ExclusionTestCase;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCP\Server;

/**
 * The Mail links the tools return open the right folder and the right conversation in the real Mail app, through the
 * authenticated endpoint (app password and OAuth): the folder link carries the folder id, the message link the
 * message's own folder and database id; the conversation the Mail front end loads for that id (its own API,
 * `api/messages/{id}/thread`) holds exactly that message in that folder for its owner and is refused to another user;
 * the linked page itself answers. Nothing changes in the Mail tables.
 */
final class McpMailLinksHttpTest extends ExclusionTestCase {
	use MailCellRows;

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
	 * Basic and Bearer: folder and message links point to the folder and the conversation of that very message.
	 *
	 * @return void
	 */
	public function testTheLinksOpenTheRightFolderAndConversation(): void {
		if (getenv('MCP_IT_WITH_MAIL') !== '1') {
			$this->markTestSkipped('Opt-in disposable Mail cell only');
		}
		$policy = Server::get(GrantPolicy::class);
		$policy->setGrant(self::ALICE, 'mail', 'read', true);
		$policy->setGrant(self::ALICE, 'mail', 'messages', true);
		$own = $this->account(self::ALICE);
		$reading = $this->mailbox($own, 'Reading');
		$bySubject = $this->subjects($reading);
		$start = $bySubject['Thread start'];
		$reply = $bySubject['Re: Thread start'];
		$before = $this->cacheHash($own);
		foreach (['basic', 'bearer'] as $kind) {
			$client = $this->mcp(self::ALICE, $kind);
			$folders = $client->tool('mail_list_mailboxes', ['account_id' => $own]);
			$this->assertTrue($folders->ok(), $folders->describe());
			$folder = array_values(array_filter($folders->json()['mailboxes'], static fn (array $row): bool => $row['id'] === $reading))[0];
			$this->assertMatchesRegularExpression('#^(/index\.php)?/apps/mail/box/' . $reading . '$#', (string)parse_url((string)$folder['url'], PHP_URL_PATH), $kind);

			$read = $client->tool('mail_read_message', ['message_id' => $start]);
			$this->assertTrue($read->ok(), $read->describe());
			$url = (string)$read->json()['message']['url'];
			$this->assertMatchesRegularExpression('#^(/index\.php)?/apps/mail/box/' . $reading . '/thread/' . $start . '$#', (string)parse_url($url, PHP_URL_PATH), $kind);
		}

		$this->assertSame(200, Http::request('GET', Http::BASE . parse_url($url, PHP_URL_PATH), ['Authorization: ' . $this->login(self::ALICE)->authorization()])['status'], 'the linked page answers');
		$thread = $this->thread($start, self::ALICE);
		$this->assertSame(200, $thread['status']);
		$envelopes = array_column(json_decode($thread['body'], true, 512, JSON_THROW_ON_ERROR), null, 'databaseId');
		$this->assertSame($reading, $envelopes[$start]['mailboxId'] ?? null, 'the conversation holds the linked message, in the linked folder');
		$this->assertArrayHasKey($reply, $envelopes, 'and the rest of its conversation');
		// The Mail app refuses another user natively: its delegation check throws a ClientException, which its error
		// middleware answers with 400 (403 or 404 in other paths); never with the conversation.
		$foreign = $this->thread($start, self::BOB);
		$this->assertContains($foreign['status'], [400, 403, 404], 'another user cannot open it');
		$this->assertStringNotContainsString('databaseId', $foreign['body'], 'nothing of the conversation reaches another user');
		$this->assertSame($before, $this->cacheHash($own), 'the Mail tables of the account are unchanged');
	}

	/**
	 * The conversation the Mail front end loads for a linked message id, through the Mail app's own API.
	 *
	 * @param int $messageId message id of the link
	 * @param string $uid user asking
	 * @return array{status:int, body:string} the response
	 */
	private function thread(int $messageId, string $uid): array {
		return Http::request('GET', Http::BASE . '/index.php/apps/mail/api/messages/' . $messageId . '/thread', [
			'Authorization: ' . $this->login($uid)->authorization(), 'Accept: application/json',
		]);
	}
}
