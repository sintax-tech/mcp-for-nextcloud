<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Deck;

use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

/** MCP plans, grants and native LabelService/CardService read-backs in the existing NC34/35 cells. */
#[Group('deck')]
final class McpDeckBoardLabelsTest extends IntegrationTestCase {
	/** @param array $out Actor output. @return array Successful MCP payload. */
	private function payload(array $out): array {
		self::assertFalse($out['result']['isError'] ?? false, json_encode($out));
		return $out['result']['structuredContent'] ?? json_decode($out['result']['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
	}
	/** @param string $tool Tool. @param array $args Arguments. @param string $actor Disposable actor. @param bool $grant Explicit opt-in. @return array Actor output. */
	private function call(string $tool, array $args, string $actor = DeckFixture::OWNER, bool $grant = true): array {
		return DeckFixture::run($actor, 'board-label-call', ['tool' => $tool, 'arguments' => $args, 'grant' => $grant]);
	}
	/** @param string $tool Tool. @param array $args Arguments. @return array Confirmed payload after a real preview. */
	private function write(string $tool, array $args): array {
		$plan = $this->payload($this->call($tool, $args));
		self::assertTrue($plan['requiresConfirmation']);
		return $this->payload($this->call($tool, $args + ['confirm' => true, 'plan_state' => $plan['plan_state']]));
	}
	/** @param array $fixture Owned ledger. @param array $ids Cards to inspect. @return array Native state in a fresh process. */
	private function inspect(array $fixture, array $ids = []): array {
		return DeckFixture::run(DeckFixture::OWNER, 'board-label-inspect', $fixture + ['cardIds' => $ids]);
	}
	/** @return void CRUD, both card paths, denial without effects, pure previews and preserved cards on deletion. */
	public function testNativeLabelLifecycleThroughMcp(): void {
		$f = DeckFixture::run(DeckFixture::OWNER, 'board-label-seed');
		try {
			$before = $this->inspect($f);
			$args = ['boardId' => $f['boardId'], 'title' => 'Synthetic label', 'color' => '123abc'];
			$denied = $this->call('deck_create_label', $args, grant: false);
			self::assertTrue($denied['refused']);
			self::assertNotContains('deck_create_label', $denied['tools']);
			self::assertSame($before, $this->inspect($f));
			$plan = $this->payload($this->call('deck_create_label', $args));
			self::assertTrue($plan['requiresConfirmation']);
			self::assertSame($before, $this->inspect($f));
			$created = $this->write('deck_create_label', $args);
			$id = $created['label']['id'];
			self::assertContains($id, array_column($this->inspect($f)['labels'], 'id'));
			$this->write('deck_update_label', ['labelId' => $id, 'title' => 'Renamed synthetic', 'color' => 'aabbcc']);
			$native = array_column($this->inspect($f)['labels'], null, 'id')[$id];
			self::assertSame('Renamed synthetic', $native['title']);
			self::assertSame('aabbcc', strtolower($native['color']));
			foreach ([DeckFixture::READER, DeckFixture::EDITOR] as $actor) {
				$refused = $this->call('deck_update_label', ['labelId' => $id, 'title' => 'Forbidden'], $actor);
				self::assertTrue($refused['result']['isError']);
			}
			$cardArgs = ['stackId' => $f['stackId'], 'title' => 'Synthetic card', 'labels' => [$id, 'New synthetic label']];
			$denied = $this->call('deck_create_card', $cardArgs + ['confirm' => true], grant: false);
			self::assertTrue($denied['result']['isError']);
			self::assertSame(0, $this->inspect($f)['cardCount']);
			$plan = $this->payload($this->call('deck_create_card', $cardArgs));
			self::assertTrue($plan['requiresConfirmation']);
			self::assertSame(0, $this->inspect($f)['cardCount']);
			self::assertNotContains('New synthetic label', array_column($this->inspect($f)['labels'], 'title'));
			$card = $this->write('deck_create_card', $cardArgs);
			$cardId = $card['id'];
			$newId = $card['labelsCreated'][0]['id'];
			$expected = [$id, $newId]; sort($expected);
			self::assertSame($expected, $this->inspect($f, [$cardId])['cards'][$cardId]['labels']);
			$second = $this->write('deck_create_card', ['stackId' => $f['stackId'], 'title' => 'Existing only', 'labels' => [$id, $id]]);
			self::assertSame([$id], $this->inspect($f, [$second['id']])['cards'][$second['id']]['labels']);
			$this->write('deck_delete_label', ['labelId' => $id]);
			$after = $this->inspect($f, [$cardId, $second['id']]);
			self::assertNotContains($id, array_column($after['labels'], 'id'));
			self::assertSame([$newId], $after['cards'][$cardId]['labels']);
			self::assertSame([], $after['cards'][$second['id']]['labels']);
			self::assertSame(2, $after['cardCount']);
		} finally {
			self::assertTrue(DeckFixture::run(DeckFixture::OWNER, 'board-label-cleanup', $f)['cleaned']);
			self::assertSame($f['grants'], DeckFixture::run(DeckFixture::OWNER, 'board-label-grants'));
		}
	}
}
