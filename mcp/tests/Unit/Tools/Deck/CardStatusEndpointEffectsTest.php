<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use OCA\Mcp\Modules\Deck\Integration\CardStatusGatewayInterface;
use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use OCA\Mcp\Tests\Unit\Contract\Oracle\CriticalFlow;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\FlowRunner;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/deck_stubs.php';

/** Each new setter traverses the public registry and confirmation refusals with observed state. */
final class CardStatusEndpointEffectsTest extends TestCase {
	/** Both setters retain their own endpoint transcript. */
	public function testTheTwoStatusEffects(): void {
		foreach (['done', 'archived'] as $field) {
			$gate = new EffectsGate($this);
			$state = CardStatusFixture::state();
			$port = $this->createMock(CardStatusGatewayInterface::class);
			$port->method('state')->willReturnCallback(static function () use (&$state): array { return $state; });
			$port->method($field === 'done' ? 'setDone' : 'setArchived')->willReturnCallback(
				static function (string $uid, int $id, bool $desired) use (&$state, $field): void {
					$state['card'][$field] = $field === 'done' ? ($desired ? 124 : null) : $desired;
					if ($field === 'done' && $desired) { $state['card']['stackId'] = 4; $state['list'] = ['id' => 4, 'title' => 'Completed', 'isDoneColumn' => true]; }
				});
			$reads = $this->createMock(DeckGatewayInterface::class);
			$reads->method('findCard')->willReturnCallback(static function () use (&$state) { return CardStatusFixture::card($state); });
			$native = $this->createMock(\Psr\Container\ContainerInterface::class);
			$native->method('get')->with(CardStatusGatewayInterface::class)->willReturn($port);
			$apps = $this->createMock(\OCP\App\IAppManager::class);
			$apps->method('isEnabledForUser')->willReturn(true);
			$gate->serve(function () use ($native, $apps, $gate, $reads): array {
				$module = DeckTestModule::build($native, $apps, new \Psr\Log\NullLogger(),
					$this->createMock(\OCP\IURLGenerator::class), $this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class),
					null, $gate->config());
				DeckTestModule::setGateway($module, $reads);
				return [$module];
			});
			$tool = 'deck_set_card_' . $field;
			// Capture by reference so refusal steps request the opposite of the newly persisted state.
			$flow = new CriticalFlow('set-card-' . $field, $tool, 'deck', 'edit',
				static function () use (&$state, $field): array { return ['cardId' => 7, $field => $field === 'done' ? $state['card']['done'] === null : !$state['card']['archived']]; },
				static function () use (&$state): array { return $state; });
			$flow->confirmingWith(static fn ($plan, array $args): array => $args + ['confirm' => true, 'confirm_shared' => true,
				'plan_state' => $plan->result->structuredContent->plan_state]);
			$flow->changing(static function () use (&$state): void { $state['card']['title'] = 'Changed after preview'; });
			$flow->withoutVisibility('Deck has native READ/EDIT permissions; it has no MCP file visibility filter. Native denial is covered by CardStatusGatewayTest.');
			(new FlowRunner($this, $gate, 'deck'))->run($flow);
		}
	}
}
