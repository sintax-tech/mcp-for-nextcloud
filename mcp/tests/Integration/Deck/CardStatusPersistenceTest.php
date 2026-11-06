<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Deck;

use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\{LoginFactory, McpClient, ToolReply};
use OCP\{Server, IConfig};
use PHPUnit\Framework\Attributes\Group;

/** Issue27: actual Bearer confirmation, native services, raw persistence and account-independent epoch. */
#[Group('deck')]
final class CardStatusPersistenceTest extends IntegrationTestCase {
	/** @param ToolReply $reply Real HTTP reply. @return array<string,mixed> Successful public payload. */
	private function data(ToolReply $reply): array {
		self::assertTrue($reply->ok(), $reply->describe());
		return $reply->structured() ?: $reply->json();
	}

	/** @param McpClient $client Real caller. @param string $tool Specific status setter. @param array $args Intent. @return array Observed confirmed result. */
	private function write(McpClient $client, string $tool, array $args): array {
		$plan = $this->data($client->tool($tool, $args));
		self::assertTrue($plan['requiresConfirmation']); self::assertArrayHasKey('plan_state', $plan);
		return $this->data($client->write($tool, $args + ['plan_state' => $plan['plan_state'], 'confirm_shared' => true]));
	}

	/** Both actors/timezones persist the native instant, native destination and unchanged completion across archive. */
	public function testStatusSettersPersistNativeUtcAndPreserveOtherFields(): void {
		$scene = new FunctionalDeckScene();
		try {
			$a = $scene->uid(0); $b = $scene->uid(1); $f = $scene->seed($a, $b);
			$column = FunctionalDeckScene::run($a, 'column', $f + ['enabled' => true]);
			foreach ([[$a, 'America/Sao_Paulo', $f['card']], [$b, 'Asia/Tokyo', $f['nativeCard']]] as [$uid, $zone, $card]) {
				Server::get(IConfig::class)->setUserValue($uid, 'core', 'timezone', $zone);
				$client = new McpClient(LoginFactory::bearer($uid));
				$before = FunctionalDeckScene::run($uid, 'read', ['card' => $card]);
				$preview = $this->data($client->tool('deck_set_card_done', ['cardId' => $card, 'done' => true]));
				self::assertTrue($preview['requiresConfirmation']);
				self::assertSame($before['raw'], FunctionalDeckScene::run($uid, 'read', ['card' => $card])['raw']);
				$missing = $client->write('deck_set_card_done', ['cardId' => $card, 'done' => true, 'confirm_shared' => true]);
				self::assertFalse($missing->ok(), 'A bare confirmation must not write');
				$written = $this->write($client, 'deck_set_card_done', ['cardId' => $card, 'done' => true]);
				self::assertTrue($written['confirmed']); self::assertTrue($written['changed']);
				$native = FunctionalDeckScene::run($uid, 'read', ['card' => $card]);
				self::assertSame('UTC', $native['timezone']);
				self::assertGreaterThanOrEqual($before['clock'], $native['done']);
				self::assertLessThanOrEqual($native['clock'], $native['done']);
				self::assertSame($native['done'], (new \DateTimeImmutable((string)$native['raw']['done'], new \DateTimeZone('UTC')))->getTimestamp());
				self::assertSame($native['done'], $written['done']);
				self::assertSame($column['supported'] ? $f['doneStack'] : $f['stack'], $written['stackId']);
				self::assertSame($before['duedate'], $native['duedate']);
				$noop = $this->write($client, 'deck_set_card_done', ['cardId' => $card, 'done' => true]);
				self::assertFalse($noop['changed']); self::assertSame($native['raw'], FunctionalDeckScene::run($uid, 'read', ['card' => $card])['raw']);
				$archived = $this->write($client, 'deck_set_card_archived', ['cardId' => $card, 'archived' => true]);
				self::assertTrue($archived['archived']); self::assertSame($native['done'], $archived['done']);
				self::assertFalse($client->tool('deck_set_card_done', ['cardId' => $card, 'done' => false])->ok());
				$unarchived = $this->write($client, 'deck_set_card_archived', ['cardId' => $card, 'archived' => false]);
				self::assertFalse($unarchived['archived']); self::assertSame($native['done'], $unarchived['done']);
				$reopened = $this->write($client, 'deck_set_card_done', ['cardId' => $card, 'done' => false]);
				self::assertNull($reopened['done']); self::assertSame($written['stackId'], $reopened['stackId']);
				self::assertNull(FunctionalDeckScene::run($uid, 'read', ['card' => $card])['raw']['done']);
				file_put_contents('/tmp/mcp-it-observations.txt', json_encode(['issue27' => 'UTC and native states persisted',
					'zone' => $zone, 'done' => $native['done'], 'rawDone' => $native['raw']['done'],
					'clockWindow' => [$before['clock'], $native['clock']], 'nativeAutoMove' => $column['supported']], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
			}
		} finally { $scene->close(); }
	}

	/** A later done column never turns a completed-card no-op into a move; stale snapshots cannot execute. */
	public function testLaterColumnShowsNoOpWarningAndInvalidatesAFormerPlan(): void {
		$scene = new FunctionalDeckScene();
		try {
			$a = $scene->uid(0); $b = $scene->uid(1); $f = $scene->seed($a, $b);
			$client = new McpClient(LoginFactory::bearer($b));
			FunctionalDeckScene::run($a, 'native-status', ['card' => $f['card'], 'method' => 'done']);
			$original = FunctionalDeckScene::run($b, 'read', ['card' => $f['card']]);
			$pending = $this->data($client->tool('deck_set_card_done', ['cardId' => $f['nativeCard'], 'done' => true]));
			$column = FunctionalDeckScene::run($a, 'column', $f + ['enabled' => true]);
			if ($column['supported']) {
				$stale = $this->data($client->write('deck_set_card_done', ['cardId' => $f['nativeCard'], 'done' => true,
					'plan_state' => $pending['plan_state'], 'confirm_shared' => true]));
				self::assertTrue($stale['requiresConfirmation']);
				self::assertNull(FunctionalDeckScene::run($b, 'read', ['card' => $f['nativeCard']])['done']);
			}
			$planReply = $client->tool('deck_set_card_done', ['cardId' => $f['card'], 'done' => true]);
			$plan = $this->data($planReply);
			if ($column['supported']) {
				self::assertStringContainsString('does not move completed cards or retry a partial move', $planReply->text());
				self::assertStringContainsString('Todo', $planReply->text()); self::assertStringContainsString('Completed', $planReply->text());
			}
			$noop = $this->write($client, 'deck_set_card_done', ['cardId' => $f['card'], 'done' => true]);
			self::assertFalse($noop['changed']); self::assertSame($original['raw'], FunctionalDeckScene::run($b, 'read', ['card' => $f['card']])['raw']);
			$outsider = new McpClient(LoginFactory::bearer($scene->uid(3)));
			$denied = $outsider->tool('deck_set_card_archived', ['cardId' => $f['card'], 'archived' => true]);
			self::assertFalse($denied->ok()); self::assertStringNotContainsString('Synthetic card', $denied->text());
		} finally { $scene->close(); }
	}
}
