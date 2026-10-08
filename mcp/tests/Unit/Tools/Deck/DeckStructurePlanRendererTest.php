<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\Tools\Deck\DeckPlanRenderer;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/deck_stubs.php';

/**
 * The Markdown a person reads before approving a board, a list or a deletion, in the three languages of the app.
 */
final class DeckStructurePlanRendererTest extends TestCase {
	private DeckPlanRenderer $renderer;

	protected function setUp(): void {
		$this->renderer = new DeckPlanRenderer();
	}

	protected function tearDown(): void {
		Translator::reset();
	}

	/**
	 * @return array<string, mixed> Plan of a board with three lists and some cards, as the module produces it.
	 */
	private function boardPlan(): array {
		$alice = ['uid' => 'alice', 'displayName' => 'Alice Silva'];

		return [
			'action' => 'deck_create_board',
			'board' => ['title' => 'Projeto Alfa', 'color' => '0082c9', 'colorGiven' => false],
			'owner' => 'alice',
			'stacks' => [
				['title' => 'A fazer', 'cards' => [
					['title' => 'Briefing', 'description' => 'Reunir requisitos', 'duedate' => '2026-10-05', 'assignees' => [$alice]],
					['title' => 'Escopo', 'description' => '', 'duedate' => null, 'assignees' => []],
				]],
				['title' => 'Em andamento', 'cards' => [
					['title' => 'Layout', 'description' => '', 'duedate' => '2026-10-12', 'assignees' => []],
				]],
				['title' => 'Feito', 'cards' => []],
			],
			'totals' => ['stacks' => 3, 'cards' => 3],
			'limits' => ['stacks' => 20, 'cards' => 100],
			'shared' => [],
			'recoverable' => true,
		];
	}

	public function testCreateBoardIsATreeOfListsAndCards(): void {
		$text = (string)$this->renderer->render('deck_create_board', $this->boardPlan());

		self::assertSame(<<<'MD'
Create board **Projeto Alfa**: 3 lists, 3 cards.
- List **A fazer**
  - **Briefing** (due 2026-10-05; assigned to Alice Silva; with description)
  - **Escopo**
- List **Em andamento**
  - **Layout** (due 2026-10-12)
- List **Feito**
- The board is open only to you until you share it in Deck.
MD, $text);
	}

	public function testTheColorIsShownOnlyWhenAskedFor(): void {
		$plan = $this->boardPlan();
		$plan['board'] = ['title' => 'Projeto Alfa', 'color' => 'ff0000', 'colorGiven' => true];

		self::assertStringContainsString("\n- Color: #ff0000", (string)$this->renderer->render('deck_create_board', $plan));
		self::assertStringNotContainsString('Color', (string)$this->renderer->render('deck_create_board', $this->boardPlan()));
	}

	public function testABoardWithoutListsIsStillReadable(): void {
		$plan = $this->boardPlan();
		$plan['stacks'] = [];

		self::assertStringStartsWith('Create board **Projeto Alfa**: 0 lists, 0 cards.', (string)$this->renderer->render('deck_create_board', $plan));
	}

	public function testOneListAndOneCardUseTheSingularForm(): void {
		$plan = $this->boardPlan();
		$plan['stacks'] = [['title' => 'Única', 'cards' => [['title' => 'Só', 'description' => '', 'duedate' => null, 'assignees' => []]]]];

		self::assertStringStartsWith('Create board **Projeto Alfa**: 1 list, 1 card.', (string)$this->renderer->render('deck_create_board', $plan));
	}

	public function testCreateBoardInPortuguese(): void {
		Translator::use(new JsonL10n('pt_BR'));

		self::assertSame(<<<'MD'
Criar quadro **Projeto Alfa**: 3 listas, 3 cartões.
- Lista **A fazer**
  - **Briefing** (prazo 2026-10-05; responsável: Alice Silva; com descrição)
  - **Escopo**
- Lista **Em andamento**
  - **Layout** (prazo 2026-10-12)
- Lista **Feito**
- O quadro fica aberto só para você até ser compartilhado no Deck.
MD, (string)$this->renderer->render('deck_create_board', $this->boardPlan()));
	}

	public function testCreateBoardInSpanish(): void {
		Translator::use(new JsonL10n('es'));
		$text = (string)$this->renderer->render('deck_create_board', $this->boardPlan());

		self::assertStringStartsWith('Crear tablero **Projeto Alfa**: 3 listas, 3 tarjetas.', $text);
		self::assertStringContainsString('- Lista **A fazer**', $text);
		self::assertStringContainsString('(plazo 2026-10-05; responsable: Alice Silva; con descripción)', $text);
		self::assertStringNotContainsString('Create board', $text);
		self::assertStringNotContainsString('until you share', $text);
	}

	public function testCreateStackNamesTheBoardAndThePosition(): void {
		$plan = [
			'action' => 'deck_create_stack',
			'stack' => ['title' => 'Revisão', 'order' => null],
			'board' => ['board' => 'Equipe', 'owner' => 'pedro', 'ownerDisplayName' => 'Pedro Almeida', 'shared' => true],
		];

		self::assertSame(<<<'MD'
Create list in *Equipe*: **Revisão**
- Position: after the last list
- Shared board of Pedro Almeida.
MD, (string)$this->renderer->render('deck_create_stack', $plan));

		$plan['stack']['order'] = 2;
		$plan['board']['shared'] = false;
		Translator::use(new JsonL10n('pt_BR'));
		self::assertSame("Criar lista em *Equipe*: **Revisão**\n- Posição: 2", (string)$this->renderer->render('deck_create_stack', $plan));
	}

	public function testDeleteStackSaysItIsEmptyAndRecoverable(): void {
		$plan = [
			'action' => 'deck_delete_stack',
			'stack' => ['id' => 10, 'title' => 'Feito', 'cards' => 0],
			'board' => ['board' => 'Pessoal', 'owner' => 'alice', 'ownerDisplayName' => 'Alice Silva', 'shared' => false],
			'consequence' => 'The list goes to the trash of the board and can be recovered there, in the Deck web interface.',
		];

		self::assertSame(<<<'MD'
Delete list in *Pessoal*: **Feito**
- The list has no cards.
- The list goes to the trash of the board and can be recovered there, in the Deck web interface.
MD, (string)$this->renderer->render('deck_delete_stack', $plan));
	}

	public function testDeleteBoardListsTheEmptyListsThatGoAlong(): void {
		$plan = [
			'action' => 'deck_delete_board',
			'board' => ['id' => 1, 'title' => 'Vazio', 'cards' => 0],
			'stacks' => ['A fazer', 'Feito'],
			'consequence' => 'The board, with its empty lists, goes to the Deck trash and can be recovered there, in the Deck web interface.',
		];

		self::assertSame(<<<'MD'
Delete board: **Vazio**
- The board has no cards.
- Lists that go with it: A fazer, Feito
- The board, with its empty lists, goes to the Deck trash and can be recovered there, in the Deck web interface.
MD, (string)$this->renderer->render('deck_delete_board', $plan));

		$plan['stacks'] = [];
		self::assertStringContainsString('- It has no lists.', (string)$this->renderer->render('deck_delete_board', $plan));
	}

	/** Titles are typed by other people: none of them can open a link, a tag, a heading or a forged line. */
	public function testHostileTitlesStayInert(): void {
		$hostile = "**x** [l](javascript:alert(1)) <b>y</b>\n### Warnings";
		$plan = $this->boardPlan();
		$plan['board']['title'] = $hostile;
		$plan['stacks'][0]['title'] = $hostile;
		$plan['stacks'][0]['cards'][0]['title'] = $hostile;
		$plan['stacks'][0]['cards'][0]['assignees'][0]['displayName'] = $hostile;

		$text = (string)$this->renderer->render('deck_create_board', $plan);

		self::assertStringNotContainsString('](javascript:', $text);
		self::assertStringNotContainsString('<b>', $text);
		self::assertStringNotContainsString("\n### ", $text);
	}

	public function testAColorThatIsNotSixHexDigitsIsNeverPrinted(): void {
		$plan = $this->boardPlan();
		$plan['board'] = ['title' => 'P', 'color' => '**evil**', 'colorGiven' => true];

		self::assertStringNotContainsString('evil', (string)$this->renderer->render('deck_create_board', $plan));
	}

	public function testIncompletePlansFallBackToTheGenericOne(): void {
		foreach (['deck_create_board', 'deck_create_stack', 'deck_delete_stack', 'deck_delete_board'] as $tool) {
			self::assertNull($this->renderer->render($tool, []), $tool);
		}
	}
}
