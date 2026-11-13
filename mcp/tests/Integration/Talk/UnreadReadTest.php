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
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * talk_read_unread over the real Talk of the cell.
 *
 * Bob reads Alice's messages after his read marker. Every page leaves the room row, Bob's attendee row (his marker
 * included), his sessions and his notifications as they were. A marker moved between pages, as another client would
 * move it, does not make the continuation skip a message.
 */
final class UnreadReadTest extends IntegrationTestCase {
	use UnreadDatabase;

	public function testEveryPageAfterTheMarkerIsReadOnceAndWritesNothing(): void {
		$token = $this->group('read');
		foreach (['one', 'two', 'three'] as $text) {
			$this->say($token, $text);
		}
		$marker = $this->row($token, Fixtures::BOB)['attendee']['last_read_message'];

		$texts = $this->unchanged([$token], function () use ($token): array {
			$texts = [];
			$cursor = null;
			for ($page = 0; $page < 20; $page++) {
				$body = $this->read($this->bob(), $token, 1, $cursor);
				$texts = [...$texts, ...$this->aliceTexts($body)];
				$cursor = $body['nextCursor'];
				if (!is_string($cursor)) {
					return $texts;
				}
			}
			$this->fail('unread read did not end');
		});

		$this->assertSame(['one', 'two', 'three'], $texts);
		$this->assertSame($marker, $this->row($token, Fixtures::BOB)['attendee']['last_read_message']);
	}

	public function testAMarkerMovedByAnotherClientDoesNotSkipTheRest(): void {
		$token = $this->group('moved');
		foreach (['first', 'second', 'third'] as $text) {
			$this->say($token, $text);
		}
		$bob = $this->bob();
		// One message per page until Alice's first one: the room's own system messages may come before it.
		$start = $this->unchanged([$token], function () use ($bob, $token): array {
			$cursor = null;
			for ($page = 0; $page < 10; $page++) {
				$body = $this->read($bob, $token, 1, $cursor);
				if ($this->aliceTexts($body) !== []) {
					return $body;
				}
				$cursor = $body['nextCursor'];
				$this->assertIsString($cursor);
			}
			$this->fail('the first message was not reached');
		});
		$this->assertSame(['first'], $this->aliceTexts($start));
		$this->assertIsString($start['nextCursor']);

		// Another client marks the whole conversation as read.
		$this->markAllRead($token);
		$rest = $this->unchanged([$token], fn (): array => $this->read($bob, $token, 50, $start['nextCursor']));

		$this->assertSame(['second', 'third'], $this->aliceTexts($rest));
		$this->assertSame($start['lastReadMessage'], $rest['lastReadMessage']);
		$this->assertNull($rest['nextCursor']);
	}

	public function testAClosedLobbyRefusesAndStaysClosed(): void {
		$token = $this->group('lobby');
		$this->say($token, 'behind the lobby');
		$this->lobby($token, new \DateTime('+2 days'));

		$reply = $this->unchanged([$token], fn () => $this->bob()->tool('talk_read_unread', ['conversation_token' => $token]));

		$this->assertFalse($reply->ok(), $reply->describe());
		$this->assertNotSame('0', $this->row($token, Fixtures::BOB)['room']['lobby_state']);
	}

	/**
	 * Moves Bob's marker to the last message, outside MCP, as Talk does when another client reads the chat.
	 */
	private function markAllRead(string $token): void {
		$room = $this->row($token, Fixtures::BOB)['room'];
		$update = $this->official()->getQueryBuilder();
		$update->update('talk_attendees')
			->set('last_read_message', $update->createNamedParameter((int)$room['last_message'], IQueryBuilder::PARAM_INT))
			->where($update->expr()->eq('room_id', $update->createNamedParameter((int)$room['id'], IQueryBuilder::PARAM_INT)))
			->andWhere($update->expr()->eq('actor_type', $update->createNamedParameter('users')))
			->andWhere($update->expr()->eq('actor_id', $update->createNamedParameter(Fixtures::BOB)));
		$this->assertSame(1, $update->executeStatement());
	}

	/**
	 * @param array{messages: list<array<string, mixed>>} $body one page
	 * @return list<string> texts Alice typed, in page order; system messages are left out
	 */
	private function aliceTexts(array $body): array {
		$texts = [];
		foreach ($body['messages'] as $message) {
			if (($message['actorId'] ?? null) === Fixtures::ALICE && ($message['type'] ?? null) === 'comment') {
				$texts[] = (string)$message['text'];
			}
		}
		return $texts;
	}

	/** @return array{lastReadMessage: int, messages: list<array<string, mixed>>, nextCursor: string|null} */
	private function read(McpClient $client, string $token, int $limit, ?string $cursor): array {
		$arguments = ['conversation_token' => $token, 'limit' => $limit];
		if ($cursor !== null) {
			$arguments['cursor'] = $cursor;
		}
		$reply = $client->tool('talk_read_unread', $arguments);
		$this->assertTrue($reply->ok(), $reply->describe());
		$body = $reply->json();
		$this->assertIsArray($body);
		$this->assertIsArray($body['messages'] ?? null);
		return $body;
	}
}
