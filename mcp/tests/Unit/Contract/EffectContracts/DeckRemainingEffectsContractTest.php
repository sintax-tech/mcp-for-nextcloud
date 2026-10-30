<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Tests\Unit\Contract\Oracle\CriticalFlow;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\FlowRunner;
use OCA\Mcp\Tools\Deck\DeckMessages;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

/** Remaining Deck writes over the real gateway, with native board/list/dependency rows and share deletion observed. */
final class DeckRemainingEffectsContractTest extends TestCase {
	private EffectsGate $gate;
	private DeckGatewayWiring $wiring;

	/** @return void real gateway and supported native dependency API */
	protected function setUp(): void {
		$this->gate = new EffectsGate($this);
		$this->wiring = new DeckGatewayWiring($this, $this->gate);
		$this->wiring->dependencies = true;
		$this->gate->serve(fn (): array => [$this->wiring->module()]);
	}

	/** @return void native board insertion with title/color/owner */
	public function testCreatingABoard(): void {
		$this->runFlow('create-board', 'deck_create_board', 'create', fn (): array => ['title' => 'New board', 'color' => '0082c9']);
		$this->assertCount(3, $this->wiring->deck->boards);
	}

	/** @return void native stack insertion with the next order */
	public function testCreatingAStack(): void {
		$this->runFlow('create-stack', 'deck_create_stack', 'create', fn (): array => ['boardId' => 20, 'title' => 'New stack']);
		$this->assertCount(6, $this->wiring->deck->stacks);
	}

	/** @return void card editing is also a distinct native write, missed by a substring-based tool inventory */
	public function testEditingACard(): void {
		$this->runFlow('edit-card', 'deck_edit_card', 'edit', fn (): array => ['cardId' => 7, 'title' => 'Revised card']);
		$this->assertSame('Revised card', $this->wiring->deck->cards[7]['title']);
	}

	/** @return void native dependency insertion and PlanChanged before execution */
	public function testAddingADependency(): void {
		$this->dependency(true);
	}

	/** @return void a newly introduced reverse edge and a self-relation refuse before any native association write */
	public function testDependencyCyclesKeepTheNativeRows(): void {
		$tool = 'deck_add_card_dependency';
		$args = ['cardId' => 8, 'targetId' => 7];
		$plan = $this->gate->call($tool, $args);
		$this->assertTrue($plan['body']->result->structuredContent->requiresConfirmation ?? false);
		$t = new EffectTranscript($this, 'deck.dependency-cycle');
		$t->record('acyclic-preview', $args, $plan, $this->state());
		$this->wiring->deck->edges = [7 => [8]];
		$before = $this->state();
		foreach (['reverse-confirmed' => $args, 'self-confirmed' => ['cardId' => 7, 'targetId' => 7]] as $step => $request) {
			$request += ['confirm' => true, 'plan_state' => $plan['body']->result->structuredContent->plan_state];
			$out = $this->gate->call($tool, $request);
			$t->record($step, $request, $out, $this->state());
			$this->assertTrue($out['body']->result->isError ?? false, json_encode($out));
			$this->assertSame(DeckMessages::dependencyCycle(), $out['body']->result->content[0]->text);
			$this->assertSame($before, $this->state(), 'cycle refusal keeps all native rows and write records');
		}
		$t->assertGolden();
	}

	/** @return void native dependency deletion and PlanChanged before execution */
	public function testRemovingADependency(): void {
		$this->wiring->deck->edges = [7 => [8, 9]];
		$this->dependency(false);
	}

	/**
	 * @param bool $add add or remove
	 * @return void freeze a still-actionable relation for every refusal
	 */
	private function dependency(bool $add): void {
		$target = fn (): int => in_array(8, $this->wiring->deck->edges[7] ?? [], true) === $add ? 9 : 8;
		$this->runFlow($add ? 'add-dependency' : 'remove-dependency', $add ? 'deck_add_card_dependency' : 'deck_remove_card_dependency', 'edit',
			fn (): array => ['cardId' => 7, 'targetId' => $target()], function () use ($target): void {
				$this->wiring->deck->cards[$target()]['title'] .= ' revised';
			});
		$this->assertSame($add ? [8] : [9], $this->wiring->deck->edges[7]);
	}

	/** @return void the real link handler must ask for shared confirmation and then actually create its share */
	public function testLinkingATeamFileNeedsSharedConfirmation(): void {
		$path = DeckGatewayWiring::FILE;
		$this->wiring->files->tree->nodes[$path]['scope'] = 'team';
		$this->wiring->files->tree->mountPath = '/alice/files/Propostas';
		$id = $this->wiring->files->tree->nodes[$path]['id'];
		$args = ['cardId' => 7, 'fileId' => $id];
		$plan = $this->gate->call('deck_link_card_file', $args)['body'];
		$this->assertTrue($plan->result->structuredContent->requiresConfirmation ?? false, json_encode($plan) . json_encode($this->gate->logged()));
		$args += ['confirm' => true, 'plan_state' => $plan->result->structuredContent->plan_state];
		$t = new EffectTranscript($this, 'deck.link-shared');
		$out = $this->gate->call('deck_link_card_file', $args);
		$t->record('shared-unconfirmed', $args, $out, $this->state());
		$this->assertSame([], $this->wiring->shares->view());
		$this->assertTrue(json_decode($out['body']->result->content[0]->text ?? 'null', true)['requiresConfirmation'] ?? false, json_encode($out));
		$args['confirm_shared'] = true;
		$out = $this->gate->call('deck_link_card_file', $args);
		$t->record('shared-confirmed', $args, $out, $this->state());
		$this->assertCount(1, $this->wiring->shares->view());
		$t->assertGolden();
	}

	/** @return void unlink removes only the selected Deck share, keeping the node and the other card's link */
	public function testUnlinkingACardFile(): void {
		$file = $this->wiring->files->tree->nodes[DeckGatewayWiring::FILE]['id'];
		foreach ([7, 8] as $card) {
			$args = ['cardId' => $card, 'fileId' => $file];
			$plan = $this->gate->call('deck_link_card_file', $args)['body'];
			$this->assertTrue($plan->result->structuredContent->requiresConfirmation ?? false, json_encode($plan) . json_encode($this->gate->logged()));
			$this->gate->call('deck_link_card_file', $args + ['confirm' => true, 'plan_state' => $plan->result->structuredContent->plan_state]);
		}
		$this->runFlow('unlink-card-file', 'deck_unlink_card_file', 'edit', function (): array {
			$share = $this->wiring->shares->shares(IShare::TYPE_DECK)[0];
			return ['cardId' => (int)$share->getSharedWith(), 'attachmentId' => (int)$share->getId()];
		}, null, $file);
		$this->assertCount(1, $this->wiring->shares->view());
		$this->assertSame('%PDF-1.7', $this->wiring->files->tree->nodes[DeckGatewayWiring::FILE]['content']);
	}

	/**
	 * @param string $name suffix
	 * @param string $tool tool
	 * @param string $grant grant
	 * @param \Closure $args arguments
	 * @param \Closure|null $change PlanChanged mutation
	 * @param int|null $file hidden file
	 * @return void
	 */
	private function runFlow(string $name, string $tool, string $grant, \Closure $args, ?\Closure $change = null, ?int $file = null): void {
		$flow = new CriticalFlow($name, $tool, 'deck', $grant, $args, fn (): array => $this->state());
		if ($tool === 'deck_unlink_card_file') {
			$flow->confirmingWith(static fn (mixed $plan, array $args): array => $args + ['confirm' => true,
				'plan_state' => $plan->result->structuredContent->plan_state, 'file_state' => (array)$plan->result->structuredContent->file_state]);
		}
		if ($change !== null) {
			$flow->changing($change);
		} else {
			$flow->withoutPlanChanged('This scene has no mutable preexisting plan input; current native preconditions are rechecked.');
		}
		if ($file !== null) {
			$flow->hiding(fn () => $this->wiring->files->hidden = [$file], fn () => $this->wiring->files->hidden = []);
		} else {
			$flow->withoutVisibility('Deck board/list/card objects use native ACLs, not Files system tags.');
		}
		(new FlowRunner($this, $this->gate, 'deck'))->run($flow);
	}

	/** @return array<string, mixed> native records, dependency rows and Deck share ledger */
	private function state(): array {
		return $this->wiring->deck->state() + ['edges' => $this->wiring->deck->edges, 'shares' => $this->wiring->shares->view()];
	}
}
