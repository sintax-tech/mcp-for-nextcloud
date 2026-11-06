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
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
use OCA\Mcp\Tools\Deck\DeckToolModule;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../../Tools/Deck/Stubs/deck_stubs.php';

/**
 * Effect contract of the Deck card and list writes over the REAL gateway (RE-01): the module builds its own
 * {@see \OCA\Mcp\Modules\Deck\Integration\DeckServiceGateway} exactly as the container registers it (container, clock, the
 * caller's timezone, session), and the container hands it native Deck services over an in-memory database
 * ({@see NativeDeck}, {@see NativeDeckServices}). The effect is read on the native side: the rows and every native
 * write with its arguments, so a gateway that called Deck with another id, list or order is caught here.
 */
final class DeckGatewayEffectsContractTest extends TestCase {
	/** Why no Deck card or list flow can be hidden from MCP. */
	private const NO_VISIBILITY = 'A Deck card or list has no exclusion of its own: the hidden system tag and the excluded folders apply '
		. 'to Files nodes, and this tool touches no file; access is the Deck ACL, which the native services check.';
	/** Why these writes answer no PlanChanged. */
	private const NO_PLAN_STATE = 'This Deck write carries no plan_state: the confirmed call re-reads the board, its owner and the '
		. 'Deck ACL at execution (a board that became shared answers the confirm_shared payload) instead of comparing a plan fingerprint.';

	private EffectsGate $gate;
	private NativeDeck $deck;
	private IUserManager $users;

	/** @return void builds the Deck database, the accounts and the endpoint */
	protected function setUp(): void {
		$this->gate = new EffectsGate($this);
		$this->gate->data()->user[EffectsGate::USER]['core']['timezone'] = 'America/Sao_Paulo';
		$this->deck = new NativeDeck();
		$this->users = FakeUsers::manager($this, FakeUsers::DEFAULTS);
		$this->gate->serve(fn (): array => [$this->module()]);
	}

	/** @return DeckToolModule the module of one request, as the container autowires it; it builds the real gateway itself */
	private function module(): DeckToolModule {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(NativeDeck::NOW);
		$time->method('getDateTime')->willReturnCallback(static fn (): \DateTime => new \DateTime('@' . NativeDeck::NOW));
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://cloud.example.com/apps/deck/');
		$session = $this->createMock(ISession::class);
		$session->method('get')->willReturnCallback(static fn (string $key): ?string => $key === 'user_id' ? EffectsGate::USER : null);
		$services = NativeDeckServices::of($this->deck, $this->users);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $services[$id]
			?? throw new \RuntimeException('Unexpected service ' . $id));
		return new DeckToolModule($container, $apps, $this->createMock(LoggerInterface::class), $urls, $time, $this->users,
			$this->gate->config(), $session);
	}

	/** @return void deck_create_card on a list of alice: plan, strict confirm, the native create, every refusal */
	public function testCreationOfACard(): void {
		$flow = (new CriticalFlow('create-card', 'deck_create_card', 'deck', 'create',
			['stackId' => 2, 'title' => 'Nova proposta', 'description' => 'Escopo e prazo', 'duedate' => '2026-02-10'],
			fn (): array => $this->deck->state()))
			->withoutPlanChanged(self::NO_PLAN_STATE)->withoutVisibility(self::NO_VISIBILITY);
		(new FlowRunner($this, $this->gate, 'deck'))->run($flow);

		// One native create, as alice, on list 2 at the bottom, the day turned into the UTC instant of São Paulo midnight.
		$this->assertSame([['call' => 'CardService::create', 'as' => 'alice',
			'args' => ['Nova proposta', 2, 'note', 99999, 'alice', 'Escopo e prazo', '2026-02-10T03:00:00Z']]], $this->deck->writes);
		$this->assertSame(['stackId' => 2, 'title' => 'Nova proposta', 'owner' => 'alice', 'deletedAt' => 0],
			array_intersect_key($this->deck->cards[100], array_flip(['stackId', 'title', 'owner', 'deletedAt'])));
	}

	/** @return void deck_move_card to another list: plan, strict confirm, the native reorder, every refusal */
	public function testMoveOfACard(): void {
		// The next card still in list 2, so each refusal is asked with a move that would succeed right now.
		$flow = (new CriticalFlow('move-card', 'deck_move_card', 'deck', 'move',
			fn (): array => ['cardId' => $this->deck->cardsOf(2)[0]->getId(), 'stackId' => 3],
			fn (): array => $this->deck->state()))
			->withoutPlanChanged(self::NO_PLAN_STATE)->withoutVisibility(self::NO_VISIBILITY);
		(new FlowRunner($this, $this->gate, 'deck'))->run($flow);

		// Card 7 went to list 3, after its one card, and nothing else moved.
		$this->assertSame([['call' => 'CardService::reorder', 'as' => 'alice', 'args' => [7, 3, 1]]], $this->deck->writes);
		$this->assertSame([3, 1], [$this->deck->cards[7]['stackId'], $this->deck->cards[7]['order']]);
		$this->assertSame([2, 2], [$this->deck->cards[8]['stackId'], $this->deck->cards[9]['stackId']]);
	}

	/** @return void deck_delete_card: plan, strict confirm, the native soft delete of that card only, every refusal */
	public function testDeletionOfACard(): void {
		$flow = (new CriticalFlow('delete-card', 'deck_delete_card', 'deck', 'delete',
			fn (): array => ['cardId' => $this->deck->cardsOf(2)[0]->getId()],
			fn (): array => $this->deck->state()))
			->withoutPlanChanged(self::NO_PLAN_STATE)->withoutVisibility(self::NO_VISIBILITY);
		(new FlowRunner($this, $this->gate, 'deck'))->run($flow);

		// Deck's own delete of card 7, as alice; card 7 is in the trash and every other card is untouched.
		$this->assertSame([['call' => 'CardService::delete', 'as' => 'alice', 'args' => [7]]], $this->deck->writes);
		$this->assertSame(NativeDeck::NOW, $this->deck->cards[7]['deletedAt']);
		foreach ([8, 9, 10, 12] as $other) {
			$this->assertSame(0, $this->deck->cards[$other]['deletedAt'], 'card ' . $other . ' must stay');
		}
	}

	/** @return void deck_delete_stack of an empty list: plan, strict confirm, the native delete, every refusal */
	public function testDeletionOfAnEmptyList(): void {
		$flow = (new CriticalFlow('delete-stack', 'deck_delete_stack', 'deck', 'delete',
			fn (): array => ['stackId' => $this->emptyList()],
			fn (): array => $this->deck->state()))
			->withoutPlanChanged(self::NO_PLAN_STATE)->withoutVisibility(self::NO_VISIBILITY);
		(new FlowRunner($this, $this->gate, 'deck'))->run($flow);

		// Counted empty twice (before and after), so the list stays in the trash and is not restored.
		$this->assertSame([['call' => 'StackService::delete', 'as' => 'alice', 'args' => [4]]], $this->deck->writes);
		$this->assertSame([NativeDeck::NOW, 0], [$this->deck->stacks[4]['deletedAt'], $this->deck->stacks[5]['deletedAt']]);
	}

	/**
	 * deck_create_card on the board pedro shares with alice: with confirm_shared the flow writes on his list; the
	 * refusals the module owns (no confirm_shared, a list with cards, a card that is not there) write nothing.
	 *
	 * @return void
	 */
	public function testWriteOnASharedBoardNeedsTheSharedConfirmation(): void {
		$flow = (new CriticalFlow('create-card-shared', 'deck_create_card', 'deck', 'create',
			['stackId' => 6, 'title' => 'Pauta da reunião', 'confirm_shared' => true],
			fn (): array => $this->deck->state()))
			->withoutPlanChanged(self::NO_PLAN_STATE)->withoutVisibility(self::NO_VISIBILITY);
		(new FlowRunner($this, $this->gate, 'deck'))->run($flow);
		$this->assertSame([['call' => 'CardService::create', 'as' => 'alice',
			'args' => ['Pauta da reunião', 6, 'note', 99999, 'alice', '', null]]], $this->deck->writes);

		$refusals = new RefusalTranscript($this, $this->gate, fn (): array => $this->deck->state());
		$shared = "The board 'Diretoria' belongs to Paulo Exemplo and is shared with you.";
		$answer = $refusals->refuse('shared-board-without-confirm-shared', 'deck_create_card',
			['stackId' => 6, 'title' => 'Pauta da reunião', 'confirm' => true], $shared);
		$this->assertSame('shared', json_decode($answer->result->content[0]->text, true)['scope'] ?? null);
		$refusals->refuse('move-to-shared-board-without-confirm-shared', 'deck_move_card',
			['cardId' => 8, 'stackId' => 6, 'confirm' => true], $shared);
		$refusals->refuse('delete-list-with-cards', 'deck_delete_stack', ['stackId' => 2, 'confirm' => true],
			'The list has 3 cards; move or delete them first.');
		$refusals->refuse('delete-list-without-manage-on-shared-board', 'deck_delete_stack',
			['stackId' => 6, 'confirm' => true, 'confirm_shared' => true], 'Card, list or board not found, or no permission.');
		$refusals->refuse('delete-missing-card', 'deck_delete_card', ['cardId' => 999, 'confirm' => true],
			'Card, list or board not found, or no permission.');
		$refusals->assertGolden('deck.gateway-refusals');
	}

	/** @return int the first list of alice's board that is not in the trash and holds no card */
	private function emptyList(): int {
		foreach ($this->deck->stacksOf(NativeDeck::OWN_BOARD) as $stack) {
			if ($this->deck->cardsOf($stack->getId()) === [] && $this->deck->cardsOf($stack->getId(), true) === []) {
				return $stack->getId();
			}
		}
		throw new \LogicException('no empty list left');
	}
}
