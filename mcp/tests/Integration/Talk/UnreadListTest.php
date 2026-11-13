<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Talk;

use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\McpClient;

/**
 * talk_list_unread over the real Talk of the cell.
 *
 * The unit suite cannot load OCA\Talk, so this is the proof that the read writes nothing. The tool runs in the web
 * server, another process, so the proof is the database: the whole room row and Bob's whole attendee row are
 * identical around the call, and Bob has as many Talk sessions and notifications after it as before. Setup may
 * create the room and the unread message. The actor who must be barred by a lobby is Bob, a guest of Alice's group.
 */
final class UnreadListTest extends IntegrationTestCase {
	use UnreadDatabase;

	public function testAnUnreadConversationIsListed(): void {
		$token = $this->group('unread');
		$this->say($token, 'unread');

		$found = $this->unchanged([$token], fn (): ?array => $this->find($this->bob(), $token));

		$this->assertIsArray($found);
		$this->assertGreaterThanOrEqual(1, $found['unreadMessages']);
		// Talk keeps the attendee counter only for federated rooms; a local count comes from the chat.
		$this->assertSame('0', $this->row($token, Fixtures::BOB)['attendee']['unread_messages']);
	}

	public function testAnActiveLobbyIsOmittedAndWritesNothing(): void {
		$token = $this->group('active');
		$this->say($token, 'behind the lobby');
		$this->lobby($token, new \DateTime('+2 days'));

		$tokens = $this->unchanged([$token], fn (): array => $this->allTokens($this->bob()));

		$this->assertNotContains($token, $tokens);
		$this->assertNotSame('0', $this->row($token, Fixtures::BOB)['room']['lobby_state']);
	}

	public function testAnExpiredLobbyStaysStoredAndIsListed(): void {
		$token = $this->group('elapsed');
		$this->say($token, 'timer already passed');
		$this->lobby($token, new \DateTime('-2 days'));

		$found = $this->unchanged([$token], fn (): ?array => $this->find($this->bob(), $token));

		$this->assertIsArray($found);
		$room = $this->row($token, Fixtures::BOB)['room'];
		$this->assertNotSame('0', $room['lobby_state']);
		$this->assertNotNull($room['lobby_timer']);
	}

	public function testAnUnnamedRoomKeepsAnEmptyName(): void {
		$token = $this->group('unnamed');
		$this->clearName($token);
		$this->say($token, 'no stored name');
		$this->assertSame('', $this->row($token, Fixtures::BOB)['room']['name']);

		$found = $this->unchanged([$token], fn (): ?array => $this->find($this->bob(), $token));

		$this->assertIsArray($found);
		$this->assertSame('', $found['displayName']);
		$this->assertSame('', $this->row($token, Fixtures::BOB)['room']['name']);
	}

	public function testAMessageBetweenPagesMovesTheConversationOffTheContinuation(): void {
		$tokens = [$this->group('page-a'), $this->group('page-b'), $this->group('page-c')];
		foreach ($tokens as $token) {
			$this->say($token, 'page ' . $token);
		}
		$before = [];
		foreach ($tokens as $token) {
			$before[$token] = $this->row($token, Fixtures::BOB);
		}
		$bob = $this->bob();
		$seen = $this->unchanged($tokens, function () use ($bob, $tokens): array {
			$seen = [];
			$cursor = null;
			for ($step = 0; $step < 60 && count(array_intersect($tokens, $seen)) < 3; $step++) {
				$body = $this->page($bob, 1, $cursor);
				foreach ($body['conversations'] as $row) {
					$seen[] = (string)$row['token'];
				}
				$cursor = $body['nextCursor'];
				if (!is_string($cursor)) {
					break;
				}
			}
			return $seen;
		});
		$this->assertSame([], array_diff($tokens, $seen), 'the three rooms are reachable before any bump');
		$first = $this->unchanged($tokens, fn (): array => $this->page($bob, 1, null));
		$firstToken = (string)$first['conversations'][0]['token'];
		$bump = $tokens[0] === $firstToken ? $tokens[1] : $tokens[0];
		$this->activity($bump, new \DateTime('2099-01-01 00:00:00'));
		$second = $this->unchanged($tokens, fn (): array => $this->page($bob, 1, $first['nextCursor']));
		$fresh = $this->unchanged($tokens, fn (): array => $this->allTokens($bob));

		$this->assertNotContains($bump, array_column($second['conversations'], 'token'));
		foreach ($tokens as $token) {
			$this->assertContains($token, $fresh);
			$after = $this->row($token, Fixtures::BOB);
			if ($token === $bump) {
				$this->assertNotSame($before[$token]['room']['last_activity'], $after['room']['last_activity']);
				unset($before[$token]['room']['last_activity'], $after['room']['last_activity']);
			}
			$this->assertSame($before[$token], $after);
		}
	}

	/** @return list<string> */
	private function allTokens(McpClient $client): array {
		$tokens = [];
		$cursor = null;
		for ($page = 0; $page < 40; $page++) {
			$body = $this->page($client, 50, $cursor);
			foreach ($body['conversations'] as $conversation) {
				$tokens[] = (string)$conversation['token'];
			}
			$cursor = $body['nextCursor'];
			if (!is_string($cursor)) {
				return $tokens;
			}
		}
		$this->fail('unread list did not end');
	}

	/** @return array{conversations: list<array<string, mixed>>, nextCursor: string|null} */
	private function page(McpClient $client, int $limit, ?string $cursor): array {
		$arguments = ['limit' => $limit];
		if ($cursor !== null) {
			$arguments['cursor'] = $cursor;
		}
		$reply = $client->tool('talk_list_unread', $arguments);
		$this->assertTrue($reply->ok(), $reply->describe());
		$body = $reply->json();
		$this->assertIsArray($body);
		$this->assertIsArray($body['conversations'] ?? null);
		return $body;
	}

	/** @return array<string, mixed>|null */
	private function find(McpClient $client, string $token): ?array {
		foreach ($this->allTokens($client) as $seen) {
			if ($seen === $token) {
				$cursor = null;
				for ($page = 0; $page < 40; $page++) {
					$body = $this->page($client, 50, $cursor);
					foreach ($body['conversations'] as $conversation) {
						if (($conversation['token'] ?? null) === $token) {
							return $conversation;
						}
					}
					$cursor = $body['nextCursor'];
					if (!is_string($cursor)) {
						return null;
					}
				}
			}
		}
		return null;
	}
}
