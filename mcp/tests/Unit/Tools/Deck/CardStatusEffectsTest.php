<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use OCA\Mcp\Tests\Unit\Contract\Oracle\GoldenFile;
use OCA\Mcp\Modules\Deck\Editing\{CardStatusPlan, CardStatusResult};
use OCA\Mcp\Modules\Deck\Presentation\DeckPlans\CardStatusText;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/** New status effects have their own approved golden; prior Deck effect contracts are left unchanged. */
final class CardStatusEffectsTest extends TestCase {
	/** Compare all four directions, no-op outside a done column and native partial persistence. */
	public function testStatusPlansAndOutcomes(): void {
		$config = $this->createMock(IConfig::class); $config->method('getSystemValueString')->willReturn('fixed-status-oracle');
		$signer = new PlanState($config); $cases = [];
		foreach (['done', 'archived'] as $field) {
			foreach ([true, false] as $desired) {
				$before = CardStatusFixture::state();
				if (!$desired) { $before['card'][$field] = $field === 'done' ? 123 : true; }
				$args = ['cardId' => 7, $field => $desired];
				$plan = CardStatusPlan::of('deck_set_card_' . $field, 'actor', $field, $args, $before, $signer);
				$after = $before; $after['card'][$field] = $field === 'done' ? ($desired ? 124 : null) : $desired;
				if ($field === 'done' && $desired) { $after['card']['stackId'] = 4; }
				$cases[] = ['intent' => $args, 'plan' => $plan, 'text' => (new CardStatusText())->render($plan),
					'observed' => $after['card'], 'result' => CardStatusResult::of($before, $after, $field, $desired, null)];
			}
		}
		$before = CardStatusFixture::state(); $partial = $before; $partial['card']['done'] = 124;
		$cases[] = ['partial' => CardStatusResult::of($before, $partial, 'done', true, new \RuntimeException('Not public'))];
		$cases[] = ['noop' => CardStatusPlan::of('deck_set_card_done', 'actor', 'done', ['cardId' => 7, 'done' => true], $partial, $signer)];
		GoldenFile::assertMatches($this, dirname(__DIR__, 2) . '/Contract/effects/deck.card-status.json', $cases);
	}
}
