<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Deck;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCA\Mcp\Tests\Integration\Support\ToolReply;
use OCP\IConfig;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Deadlines with dates and times survive native timezone-less storage and every existing read surface.
 */
#[Group('deck')]
final class CardDueDatePersistenceTest extends IntegrationTestCase {
	/**
	 * Accounts on opposite sides of UTC and their independent expected midnight wire values.
	 *
	 * @return iterable<string, array{string, string, string}> UID, account timezone and October1 midnight UTC.
	 */
	public static function accounts(): iterable {
		yield 'Brazil' => [DeckFixture::OWNER, 'America/Sao_Paulo', '2026-10-01T03:00:00Z'];
		yield 'Japan' => [DeckFixture::EDITOR, 'Asia/Tokyo', '2026-09-30T15:00:00Z'];
	}

	/**
	 * Run an HTTP scenario with exact restoration of account timezone and the app's configuration.
	 *
	 * @param string $uid Disposable account to authenticate over HTTP Basic.
	 * @param string $zone Account preference, without changing PHP's or the server's global timezone.
	 * @param string|null $instant Optional native UTC fixture instant.
	 * @param callable(McpClient, array<string, int>):void $body Scenario using the owned resource ledger.
	 * @return void
	 */
	private function scenario(string $uid, string $zone, ?string $instant, callable $body): void {
		$config = Server::get(IConfig::class);
		$oldZone = $config->getUserValue($uid, 'core', 'timezone', '__missing__');
		$oldApp = [];
		foreach ($config->getAppKeys('mcp') as $key) {
			$oldApp[$key] = $config->getAppValue('mcp', $key);
		}
		$f = null;
		try {
			$config->setUserValue($uid, 'core', 'timezone', $zone);
			$policy = Server::get(GrantPolicy::class);
			$policy->setGlobalEnabled(true);
			$policy->setEligible($uid, true);
			$policy->setConnected($uid, true);
			foreach (['read', 'create', 'edit'] as $operation) {
				$policy->setGrant($uid, 'deck', $operation, true);
			}
			$f = DeckFixture::run($uid, 'date-seed', ['instant' => $instant]);
			$body(new McpClient(LoginFactory::basic($uid)), $f);
		} finally {
			if ($f !== null) {
				DeckFixture::run($uid, 'date-cleanup', $f);
			}
			if ($oldZone === '__missing__') {
				$config->deleteUserValue($uid, 'core', 'timezone');
			} else {
				$config->setUserValue($uid, 'core', 'timezone', $oldZone);
			}
			foreach ($config->getAppKeys('mcp') as $key) {
				if (!array_key_exists($key, $oldApp)) {
					$config->deleteAppValue('mcp', $key);
				}
			}
			foreach ($oldApp as $key => $value) {
				$config->setAppValue('mcp', $key, $value);
			}
		}
	}

	/**
	 * Decode only a successful authenticated tool response.
	 *
	 * @param ToolReply $reply Actual HTTP response, never an in-process module call.
	 * @return array<string, mixed> Public tool payload.
	 */
	private function payload(ToolReply $reply): array {
		self::assertTrue($reply->ok(), $reply->describe());
		$data = $reply->structured();
		if ($data === []) {
			$data = $reply->json();
		}
		self::assertIsArray($data, $reply->describe());
		return $data;
	}

	/**
	 * Assert the same local day on read, list and followup after a separate persisted write.
	 *
	 * @param McpClient $client Authenticated client with the configured account timezone.
	 * @param array<string, int> $f Owned board and stack identities.
	 * @param int $cardId Persisted card to find on every read surface.
	 * @param string $day Independent expected local day.
	 * @param string|null $dueAt Expected local ISO instant when supplied.
	 * @return void
	 */
	private function assertReadSurfaces(McpClient $client, array $f, int $cardId, string $day, ?string $dueAt = null): void {
		$read = $this->payload($client->tool('deck_read_card', ['cardId' => $cardId]));
		self::assertSame($day, $read['duedate']);
		self::assertArrayHasKey('dueAt', $read);
		self::assertArrayNotHasKey('timezone', $read);
		if ($dueAt !== null) {
			self::assertSame($dueAt, $read['dueAt']);
		}
		$list = $this->payload($client->tool('deck_list_cards', ['stackId' => $f['stack']]));
		$followup = $this->payload($client->tool('deck_followup_cards', ['status' => 'all', 'boardId' => $f['board']]));
		$followupCards = [];
		foreach ($followup['groups'] as $group) {
			array_push($followupCards, ...$group['cards']);
		}
		foreach ([$list['cards'], $followupCards] as $cards) {
			$indexed = array_column($cards, null, 'id');
			self::assertArrayHasKey($cardId, $indexed);
			self::assertSame($day, $indexed[$cardId]['duedate']);
			self::assertArrayHasKey('dueAt', $indexed[$cardId]);
			if ($dueAt !== null) {
				self::assertSame($dueAt, $indexed[$cardId]['dueAt']);
			}
		}
	}

	/**
	 * Create and edit only calendar dates, proving native UTC persistence and the unchanged public day.
	 *
	 * @param string $uid Disposable account.
	 * @param string $zone Account timezone west or east of UTC.
	 * @param string $utc Independent expected UTC instant for the input day.
	 * @return void
	 */
	#[DataProvider('accounts')]
	public function testDateOnlyRoundTrip(string $uid, string $zone, string $utc): void {
		$this->scenario($uid, $zone, null, function (McpClient $client, array $f) use ($uid, $zone, $utc): void {
			$created = $this->payload($client->write('deck_create_card', ['stackId' => $f['stack'], 'title' => 'Local calendar day', 'duedate' => '2026-10-01']));
			self::assertSame('2026-10-01', $created['duedate']);
			$cardId = (int)$created['id'];
			$this->assertReadSurfaces($client, $f, $cardId, '2026-10-01');
			$native = DeckFixture::run($uid, 'date-read', ['card' => $cardId]);
			self::assertSame((new \DateTimeImmutable($utc))->getTimestamp(), $native['timestamp']);
			$edited = $this->payload($client->write('deck_edit_card', ['cardId' => $f['card'], 'duedate' => '2026-10-01']));
			self::assertSame('2026-10-01', $edited['duedate']);
			$this->assertReadSurfaces($client, $f, $f['card'], '2026-10-01');
			$nativeEdited = DeckFixture::run($uid, 'date-read', $f);
			self::assertSame($native['timestamp'], $nativeEdited['timestamp']);
			$this->payload($client->write('deck_edit_card', ['cardId' => $cardId, 'title' => 'Same due instant']));
			self::assertSame($native, DeckFixture::run($uid, 'date-read', ['card' => $cardId]));
			file_put_contents('/tmp/mcp-it-observations.txt', 'Date-only roundtrip ' . $zone . ': create/edit/read/list/followup=2026-10-01; native=' . $native['instant'] . PHP_EOL, FILE_APPEND);
		});
	}

	/**
	 * Existing non-midnight UI instants remain unchanged to the second when an edit omits duedate.
	 *
	 * @param string $uid Disposable account.
	 * @param string $zone Account timezone.
	 * @param string $utc Unused provider midnight, retained for a shared account table.
	 * @return void
	 */
	#[DataProvider('accounts')]
	public function testEditWithoutDueDatePreservesNativeInstant(string $uid, string $zone, string $utc): void {
		$this->scenario($uid, $zone, '2026-10-03T02:47:19Z', function (McpClient $client, array $f) use ($uid, $zone): void {
			$before = DeckFixture::run($uid, 'date-read', $f);
			$this->payload($client->write('deck_edit_card', ['cardId' => $f['card'], 'description' => 'Preserve the native UI time']));
			self::assertSame($before, DeckFixture::run($uid, 'date-read', $f));
			$day = $zone === 'America/Sao_Paulo' ? '2026-10-02' : '2026-10-03';
			$dueAt = $zone === 'America/Sao_Paulo' ? '2026-10-02T23:47:19-03:00' : '2026-10-03T11:47:19+09:00';
			$this->assertReadSurfaces($client, $f, $f['card'], $day, $dueAt);
		});
	}

	/**
	 * Local hours survive UTC storage, time-only edits and edits that omit the deadline.
	 *
	 * @param string $uid Disposable HTTP account.
	 * @param string $zone Account timezone.
	 * @param string $utc Provider midnight unused by this independent timed scenario.
	 * @return void
	 */
	#[DataProvider('accounts')]
	public function testTimedRoundTrip(string $uid, string $zone, string $utc): void {
		$this->scenario($uid, $zone, null, function (McpClient $client, array $f) use ($uid, $zone): void {
			$offset = $zone === 'America/Sao_Paulo' ? '-03:00' : '+09:00';
			$expectedUtc = $zone === 'America/Sao_Paulo' ? '2026-10-01T18:30:19Z' : '2026-10-01T06:30:19Z';
			$created = $this->payload($client->write('deck_create_card', ['stackId' => $f['stack'], 'title' => 'Local time', 'duedate' => '2026-10-01T15:30:19']));
			$id = (int)$created['id'];
			self::assertSame('2026-10-01T15:30:19' . $offset, $created['dueAt']);
			$this->assertReadSurfaces($client, $f, $id, '2026-10-01', $created['dueAt']);
			$native = DeckFixture::run($uid, 'date-read', ['card' => $id]);
			self::assertSame((new \DateTimeImmutable($expectedUtc))->getTimestamp(), $native['timestamp']);
			$preview = $client->tool('deck_edit_card', ['cardId' => $id, 'duedate' => '2026-10-01T16:30:19']);
			self::assertStringContainsString(str_replace('_', '\\_', $zone), $preview->text());
			self::assertSame($native, DeckFixture::run($uid, 'date-read', ['card' => $id]));
			$edited = $this->payload($client->write('deck_edit_card', ['cardId' => $id, 'duedate' => '2026-10-01T16:30:19']));
			self::assertSame('2026-10-01T16:30:19' . $offset, $edited['dueAt']);
			$this->assertReadSurfaces($client, $f, $id, '2026-10-01', $edited['dueAt']);
			$before = DeckFixture::run($uid, 'date-read', ['card' => $id]);
			$this->payload($client->write('deck_edit_card', ['cardId' => $id, 'description' => 'Keep all seconds']));
			self::assertSame($before, DeckFixture::run($uid, 'date-read', ['card' => $id]));
			$this->assertReadSurfaces($client, $f, $id, '2026-10-01', $edited['dueAt']);
			$explicit = $this->payload($client->write('deck_edit_card', ['cardId' => $id, 'duedate' => '2026-10-01T18:30Z']));
			self::assertSame($zone === 'America/Sao_Paulo' ? '2026-10-01T15:30:00-03:00' : '2026-10-02T03:30:00+09:00', $explicit['dueAt']);
			$zeroArgs = ['stackId' => $f['stack'], 'title' => 'Negative zero offset', 'duedate' => '2026-10-01T10:00-00:00'];
			$zeroPreview = $client->tool('deck_create_card', $zeroArgs);
			self::assertTrue($zeroPreview->ok(), $zeroPreview->describe());
			self::assertStringContainsString($zone === 'America/Sao_Paulo' ? '07:00' : '19:00', $zeroPreview->text());
			$zero = $this->payload($client->write('deck_create_card', $zeroArgs));
			self::assertSame($zone === 'America/Sao_Paulo' ? '2026-10-01T07:00:00-03:00' : '2026-10-01T19:00:00+09:00', $zero['dueAt']);
			self::assertSame((new \DateTimeImmutable('2026-10-01T10:00Z'))->getTimestamp(), DeckFixture::run($uid, 'date-read', ['card' => (int)$zero['id']])['timestamp']);
			file_put_contents('/tmp/mcp-it-observations.txt', 'Timed roundtrip ' . $zone . ': native=' . $native['instant'] . '; dueAt=' . $created['dueAt'] . '; edit/read/list/followup/preservation=OK' . PHP_EOL, FILE_APPEND);
		});
	}

	/**
	 * A nonexistent explicitly timed midnight is refused without modifying the existing card.
	 *
	 * @return void
	 */
	public function testExplicitLocalGapDoesNotWrite(): void {
		$this->scenario(DeckFixture::OWNER, 'America/Santiago', '2026-09-05T12:00:00Z', function (McpClient $client, array $f): void {
			$before = DeckFixture::run(DeckFixture::OWNER, 'date-read', $f);
			$reply = $client->write('deck_edit_card', ['cardId' => $f['card'], 'duedate' => '2026-09-06T00:00']);
			self::assertFalse($reply->ok(), $reply->describe());
			self::assertSame($before, DeckFixture::run(DeckFixture::OWNER, 'date-read', $f));
		});
	}

	/**
	 * Timed blueprint cards cross the same account-zone boundary as individual card creation.
	 *
	 * @param string $uid Disposable HTTP account.
	 * @param string $zone Account timezone.
	 * @param string $utc Unused midnight provider value.
	 * @return void
	 */
	#[DataProvider('accounts')]
	public function testTimedBlueprintRoundTrip(string $uid, string $zone, string $utc): void {
		$this->scenario($uid, $zone, null, function (McpClient $client) use ($uid, $zone): void {
			$board = null;
			try {
				$result = $this->payload($client->write('deck_create_board', ['title' => 'Timed blueprint', 'stacks' => [['title' => 'Todo', 'cards' => [['title' => 'Timed card', 'duedate' => '2026-10-01T15:30']]]]]));
				$board = (int)$result['created']['board']['id'];
				self::assertTrue($result['complete']);
				$stack = $result['created']['stacks'][0];
				$card = (int)$stack['cards'][0]['id'];
				$this->assertReadSurfaces($client, ['board' => $board, 'stack' => (int)$stack['id']], $card, '2026-10-01', '2026-10-01T15:30:00' . ($zone === 'America/Sao_Paulo' ? '-03:00' : '+09:00'));
			} finally {
				if ($board !== null) {
					DeckFixture::run($uid, 'date-cleanup', ['board' => $board]);
				}
			}
		});
	}
}
