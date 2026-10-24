<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Mail;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Integration\Exclusion\ExclusionTestCase;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCP\Server;

/** Authenticated HTTP discovery and calls, with no Mail loadApp or actor in the requesting process. */
final class McpMailHttpTest extends ExclusionTestCase {
	/** @return void both supported HTTP credentials reach Mail through the endpoint's normal boot and admission */
	public function testAuthenticatedEndpointDiscoversAndReadsOnlyOwnedCachedMail(): void {
		if (getenv('MCP_IT_WITH_MAIL') !== '1') {
			$this->markTestSkipped('Opt-in disposable Mail cell only');
		}
		Server::get(GrantPolicy::class)->setGrant(self::ALICE, 'mail', 'read', true);
		$own = $this->account(self::ALICE);
		$foreign = $this->account(self::BOB);
		$before = $this->cacheHash($own);
		foreach (['basic', 'bearer'] as $kind) {
			$credential = $this->login(self::ALICE, $kind);
			$response = Http::request('POST', Http::BASE . '/index.php/apps/mcp/', [
				'Authorization: ' . $credential->authorization(), 'Content-Type: application/json', 'Accept: application/json, text/event-stream',
			], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], JSON_THROW_ON_ERROR));
			self::assertSame(200, $response['status']);
			$body = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
			$names = array_column($body['result']['tools'], 'name');
			self::assertContains('mail_list_accounts', $names);
			self::assertContains('mail_list_mailboxes', $names);
			$client = $this->mcp(self::ALICE, $kind);
			$accounts = $client->tool('mail_list_accounts');
			self::assertTrue($accounts->ok(), $accounts->describe());
			self::assertSame([$own], array_column($accounts->json()['accounts'], 'id'));
			$folders = $client->tool('mail_list_mailboxes', ['account_id' => $own]);
			self::assertTrue($folders->ok(), $folders->describe());
			self::assertContains('INBOX', array_column($folders->json()['mailboxes'], 'path'));
			$other = $client->tool('mail_list_mailboxes', ['account_id' => $foreign]);
			$missing = $client->tool('mail_list_mailboxes', ['account_id' => 987654321]);
			self::assertTrue($other->isError());
			self::assertSame($missing->fingerprint(), $other->fingerprint());
		}
		self::assertSame($before, $this->cacheHash($own), 'HTTP calls must leave cached account/folder state unchanged');
	}

	/**
	 * @param string $uid owner provisioned by the official Mail cell
	 * @return int sole account id, read directly without loading a Mail service into this process
	 */
	private function account(string $uid): int {
		$query = $this->official()->getQueryBuilder();
		$result = $query->select('id')->from('mail_accounts')->where($query->expr()->eq('user_id', $query->createNamedParameter($uid)))->executeQuery();
		try { $rows = $result->fetchAll(); } finally { $result->closeCursor(); }
		self::assertCount(1, $rows);
		return (int)$rows[0]['id'];
	}

	/**
	 * @param int $account owned account id
	 * @return string hash of full account/folder rows, never printing account secrets on mismatch
	 */
	private function cacheHash(int $account): string {
		$rows = [];
		foreach (['mail_accounts' => 'id', 'mail_mailboxes' => 'account_id'] as $table => $column) {
			$query = $this->official()->getQueryBuilder();
			$result = $query->select('*')->from($table)->where($query->expr()->eq($column, $query->createNamedParameter($account)))->orderBy('id')->executeQuery();
			try { $rows[$table] = $result->fetchAll(); } finally { $result->closeCursor(); }
		}
		return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
	}
}
