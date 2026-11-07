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
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

/**
 * Effect contract of the Deck label change, file link and board deletion over the REAL gateway (RE-01), the
 * counterpart of {@see DeckEffectsContractTest}, whose label and link effects are observed on a gateway double: here
 * the module builds {@see \OCA\Mcp\Modules\Deck\Integration\DeckServiceGateway} itself ({@see DeckGatewayWiring}) and the effect is
 * read on the native side, the label links and every native write of {@see NativeDeck}, and the Deck shares of
 * {@see RecordingShares}.
 */
final class DeckGatewayBoardEffectsContractTest extends TestCase {
	private EffectsGate $gate;
	private DeckGatewayWiring $wiring;
	private NativeDeck $deck;

	/** @return void builds the Deck database, the tree, the share ledger and the endpoint */
	protected function setUp(): void {
		$this->gate = new EffectsGate($this);
		$this->gate->data()->user[EffectsGate::USER]['core']['timezone'] = 'UTC';
		$this->wiring = new DeckGatewayWiring($this, $this->gate);
		$this->deck = $this->wiring->deck;
		$this->gate->serve(fn (): array => [$this->wiring->module()]);
	}

	/** @return void deck_edit_card_labels: plan, strict confirm, PlanChanged, the native assign and remove, every refusal */
	public function testLabelChangeOfACard(): void {
		$this->deck->labels = [
			1 => ['boardId' => NativeDeck::OWN_BOARD, 'title' => 'Urgente', 'color' => 'ff0000'],
			2 => ['boardId' => NativeDeck::OWN_BOARD, 'title' => 'Revisão', 'color' => '0082c9'],
			3 => ['boardId' => NativeDeck::OWN_BOARD, 'title' => 'Cliente', 'color' => '00aa00'],
		];
		$this->deck->cardLabels = [7 => [1, 3], 8 => [1]];
		$flow = (new CriticalFlow('gateway-edit-card-labels', 'deck_edit_card_labels', 'deck', 'edit',
			['cardId' => 7, 'add' => [2], 'remove' => [1]],
			fn (): array => ['labels' => $this->deck->labels, 'cardLabels' => $this->deck->cardLabels, 'writes' => $this->deck->writes]))
			// Another member renames a requested label after the plan: the label the user approved is not the one now.
			->changing(function (): void {
				$this->deck->labels[2]['title'] = 'Revisão jurídica';
			})
			->withoutVisibility('A Deck card has no exclusion of its own: the hidden system tag applies to Files nodes, and '
				. 'deck_edit_card_labels touches no file; access to the card is the Deck ACL, which the native services check.');
		(new FlowRunner($this, $this->gate, 'deck'))->run($flow);

		// Two native writes on card 7 only, the removal first: label 1 removed, label 2 assigned; label 3 and card 8 untouched.
		$this->assertSame([['call' => 'CardService::removeLabel', 'as' => 'alice', 'args' => [7, 1]],
			['call' => 'CardService::assignLabel', 'as' => 'alice', 'args' => [7, 2]]], $this->deck->writes);
		$this->assertSame([7 => [2, 3], 8 => [1]], $this->deck->cardLabels);
	}

	/** @return void deck_link_card_file of a visible file: plan, strict confirm, PlanChanged, the Deck share, every refusal */
	public function testLinkOfAFileToACard(): void {
		$path = DeckGatewayWiring::FILE;
		$files = $this->wiring->files;
		$id = $files->tree->nodes[$path]['id'];
		// A card holds one link per file, so a later step links the same file to the next card still without it.
		$flow = (new CriticalFlow('gateway-link-card-file', 'deck_link_card_file', 'deck', 'edit',
			fn (): array => ['cardId' => $this->unlinkedCard(), 'fileId' => $id],
			fn (): array => ['shares' => $this->wiring->shares->view(), 'writes' => $this->deck->writes]))
			// The file is changed by another client after the plan: its new ETag is not the version the user approved.
			->changing(function () use ($files, $path): void {
				$files->tree->nodes[$path]['etag'] = 'e-changed';
				$files->tree->nodes[$path]['content'] .= ' rev. 2';
			})
			->hiding(function () use ($files, $id): void {
				$files->hidden = [$id];
			}, function () use ($files): void {
				$files->hidden = [];
			});
		(new FlowRunner($this, $this->gate, 'deck'))->run($flow);

		// One Deck share of that file with card 7, the card the confirmed plan named; no native Deck write.
		$linked = $this->wiring->shares->shares(IShare::TYPE_DECK);
		$this->assertCount(1, $linked);
		$this->assertSame(['7', $id], [$linked[0]->getSharedWith(), $linked[0]->getNodeId()]);
		$this->assertSame([], $this->deck->writes);
	}

	/** @return void deck_delete_board of an empty board of alice: plan, strict confirm, the native delete, every refusal */
	public function testDeletionOfAnEmptyBoard(): void {
		$this->deck->boards += [40 => ['title' => 'Arquivo 2025', 'owner' => 'alice', 'deletedAt' => 0],
			41 => ['title' => 'Rascunho', 'owner' => 'alice', 'deletedAt' => 0]];
		$this->deck->stacks += [50 => ['boardId' => 40, 'title' => 'Encerrados', 'order' => 0, 'deletedAt' => 0]];
		$flow = (new CriticalFlow('delete-board', 'deck_delete_board', 'deck', 'delete',
			fn (): array => ['boardId' => $this->emptyBoard()],
			fn (): array => $this->deck->state()))
			->withoutPlanChanged('deck_delete_board carries no plan_state: the confirmed call takes the owner and counts the '
				. 'cards again at execution, and counts them once more after the delete, instead of comparing a plan fingerprint.')
			->withoutVisibility('A Deck board has no exclusion of its own: the hidden system tag and the excluded folders apply '
				. 'to Files nodes, and this tool touches no file; access is the Deck ACL, which the native services check.');
		(new FlowRunner($this, $this->gate, 'deck'))->run($flow);

		// Board 40 is in the trash, counted empty again after the delete, so it is not brought back.
		$this->assertSame([['call' => 'BoardService::delete', 'as' => 'alice', 'args' => [40]]], $this->deck->writes);
		$this->assertSame([NativeDeck::NOW, 0, 0], [$this->deck->boards[40]['deletedAt'], $this->deck->boards[41]['deletedAt'],
			$this->deck->boards[NativeDeck::OWN_BOARD]['deletedAt']]);

		$refusals = new RefusalTranscript($this, $this->gate, fn (): array => $this->deck->state());
		$refusals->refuse('delete-board-with-cards', 'deck_delete_board', ['boardId' => NativeDeck::OWN_BOARD, 'confirm' => true],
			'The board has 4 cards');
		$refusals->refuse('delete-board-of-somebody-else', 'deck_delete_board', ['boardId' => NativeDeck::SHARED_BOARD, 'confirm' => true],
			'Card, list or board not found, or no permission.');
		$refusals->assertGolden('deck.gateway-board-refusals');
	}

	/** @return int the first card of alice's board the file is not linked to yet */
	private function unlinkedCard(): int {
		$linked = array_map(static fn (IShare $share): int => (int)$share->getSharedWith(),
			$this->wiring->shares->shares(IShare::TYPE_DECK));
		foreach (array_keys($this->deck->cards) as $cardId) {
			if ($this->deck->boardOfCard($cardId) === NativeDeck::OWN_BOARD && !in_array($cardId, $linked, true)) {
				return $cardId;
			}
		}
		throw new \LogicException('every card is linked');
	}

	/** @return int the first board of alice that is not in the trash and holds no card */
	private function emptyBoard(): int {
		foreach ($this->deck->boards as $boardId => $board) {
			$cards = array_sum(array_map(fn ($stack): int => count($this->deck->cardsOf($stack->getId())),
				$this->deck->stacksOf($boardId)));
			if ($board['owner'] === EffectsGate::USER && $board['deletedAt'] === 0 && $cards === 0) {
				return $boardId;
			}
		}
		throw new \LogicException('no empty board left');
	}
}
