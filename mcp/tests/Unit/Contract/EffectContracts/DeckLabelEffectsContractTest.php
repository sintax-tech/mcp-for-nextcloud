<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 Roman Matkivskyy <roman91@live.it>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Unit\Contract\Oracle\{CriticalFlow, EffectsGate, FlowRunner};
use OCA\Mcp\Tests\Unit\Tools\Deck\DeckTestModule;
use PHPUnit\Framework\TestCase;

/** New label writes recorded through the existing protocol and CriticalFlow gates. */
final class DeckLabelEffectsContractTest extends TestCase {
	private EffectsGate $gate;
	private DeckBoardDouble $board;
	/** @return void Fresh policy, board and lazily built module per recorded request. */
	protected function setUp(): void {
		$this->gate = new EffectsGate($this);
		$this->board = new DeckBoardDouble($this);
		$this->gate->serve(function (): array {
			$native = $this->createMock(\Psr\Container\ContainerInterface::class);
			$native->method('get')->willReturnCallback(fn (string $id): mixed => $id === GrantPolicy::class ? $this->gate->policy() : null);
			$apps = $this->createMock(\OCP\App\IAppManager::class);
			$apps->method('isEnabledForUser')->willReturn(true);
			$module = DeckTestModule::build($native, $apps, $this->createMock(\Psr\Log\LoggerInterface::class),
				$this->createMock(\OCP\IURLGenerator::class), $this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class), config: $this->gate->config());
			DeckTestModule::setGateway($module, $this->board->gateway());
			return [$module];
		});
	}
	/** Why the board label tools have no visibility step. */
	private const LABELS_WITHOUT_VISIBILITY = 'A Deck label has no exclusion of its own: the hidden system tag applies to Files nodes, and '
		. 'the board label tools touch no file; access to the board is the Deck ACL (MANAGE), read by the gateway.';

	/** @return void deck_create_label: plan, strict confirm, effect and every refusal */
	public function testCreationOfABoardLabel(): void {
		// The refusal steps after the write ask for a title the board does not have yet, so they could still succeed.
		$flow = (new CriticalFlow('create-label', 'deck_create_label', 'deck', 'labels',
			fn (): array => ['boardId' => DeckBoardDouble::BOARD, 'title' => in_array('Prioridade', array_column($this->board->catalog(), 'title'), true)
				? 'Prioridade alta' : 'Prioridade', 'color' => '#AA00FF'],
			fn (): array => ['catalog' => $this->board->catalog(), 'writes' => $this->board->writes]))
			->changing(function (): void { $this->board->name = 'Renamed board'; })
			->withoutVisibility(self::LABELS_WITHOUT_VISIBILITY);
		(new FlowRunner($this, $this->gate, 'deck'))->run($flow);
	}

	/** @return void deck_update_label: plan, strict confirm, PlanChanged, effect and every refusal */
	public function testChangeOfABoardLabel(): void {
		$flow = (new CriticalFlow('update-label', 'deck_update_label', 'deck', 'labels',
			['labelId' => 1, 'title' => 'Crítico', 'color' => 'cc0000'],
			fn (): array => ['catalog' => $this->board->catalog(), 'writes' => $this->board->writes]))
			// Another manager recolours the label after the plan: the label the user approved is not the one now.
			->changing(function (): void {
				$this->board->catalog[1]['color'] = '00ff00';
			})
			->withoutVisibility(self::LABELS_WITHOUT_VISIBILITY);
		(new FlowRunner($this, $this->gate, 'deck'))->run($flow);
	}

	/** @return void deck_delete_label: plan, strict confirm, PlanChanged on a new card using it, effect and every refusal */
	public function testDeletionOfABoardLabel(): void {
		// The refusal steps after the write name the first label still on the board, so they could still succeed.
		$flow = (new CriticalFlow('delete-label', 'deck_delete_label', 'deck', 'labels', fn (): array => ['labelId' => min(array_keys($this->board->catalog))],
			fn (): array => ['catalog' => $this->board->catalog(), 'labels' => $this->board->labels(), 'writes' => $this->board->writes]))
			// Another member puts the label on a second card after the plan: the deletion would now reach more cards.
			->changing(function (): void {
				$this->board->cards[8]['labels'] = [1];
			})
			->withoutVisibility(self::LABELS_WITHOUT_VISIBILITY);
		(new FlowRunner($this, $this->gate, 'deck'))->run($flow);
	}

}
