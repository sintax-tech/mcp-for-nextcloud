<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck\Handler;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\Deck\BoardBlueprint;
use OCA\Mcp\Tools\Deck\DeckErrors;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckMessages;
use OCA\Mcp\Tools\Deck\DeckUnconfirmedException;
use Psr\Log\LoggerInterface;

/**
 * Backs `deck_create_board`: a board, its lists and its first cards, built from one confirmed call.
 *
 * Everything that can be refused is refused first, by {@see BoardBlueprint}, so a bad structure writes nothing.
 * Once the board exists the handler never answers with an error: each list and card is its own step, a step
 * that fails is reported in `failed` and the others go on, and nothing already written is undone. An error
 * after a write is what made the client retry and build the same card twice in 0.9.0. A step that throws after Deck
 * saved it (the board before its default labels, a list or a card before its activity) is read back and counts as
 * created, with a warning; one whose state cannot be read back is a warning, never an entry of `failed`.
 *
 * A board, list or card found again only by likeness (title, owner and time) may belong to another call: it is
 * reported with `confirmed: "probable"` and nothing is built on it. The lists of a probable board and the cards of a
 * probable list go to `failed` with the reason, and the assignees of a probable card are not assigned.
 */
final class CreateBoardHandler extends AbstractHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'deck_create_board';

	/**
	 * @param DeckGatewayInterface $gateway Deck access, the only door to the Deck app.
	 * @param LoggerInterface $logger Receives tool name and exception class on failure.
	 */
	public function __construct(DeckGatewayInterface $gateway, LoggerInterface $logger) {
		parent::__construct($gateway, $logger);
	}

	/**
	 * {@inheritDoc}
	 *
	 * The payload carries `created` (the ids of the board, lists and cards), `failed` (what was not created and
	 * why), `warnings`, `complete` and a `summary` that says what exists.
	 *
	 * @param array<string, mixed> $arguments Requires `title`; accepts `color` and `stacks`.
	 * @param string $userId UID of the authenticated caller, who owns the board and every card in it.
	 * @return array{content: list<array{type: string, text: string}>, isError?: bool} MCP result.
	 * @throws \InvalidArgumentException When any part of the structure is refused, before anything is written.
	 */
	public function handle(array $arguments, string $userId): array {
		$blueprint = BoardBlueprint::parse($arguments, $userId);

		return $this->run(function () use ($blueprint, $userId): array {
			// The one step whose failure is a plain error: until it succeeds nothing exists. Deck inserts the board
			// before its labels and activity, so the boards of the caller before and after tell whether it does.
			$before = array_map(static fn ($board): int => (int)$board->getId(), $this->gateway->ownedBoards($userId));
			$warnings = [];
			$probable = false;
			$board = $this->afterApproximateWrite(
				fn () => $this->gateway->createBoard($userId, $blueprint['title'], $blueprint['color']),
				fn () => $this->newBoard($userId, $blueprint['title'], $before),
				$warnings,
				$probable,
			);

			return $this->build((int)$board->getId(), (string)$board->getTitle(), $blueprint, $userId, $warnings, $probable);
		});
	}

	/**
	 * {@inheritDoc}
	 *
	 * A new board is found again among the boards of the caller.
	 */
	protected function readWith(): string {
		return 'deck_list_boards';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function toolName(): string {
		return self::TOOL;
	}

	/**
	 * The board a creation that threw saved: the newest active board of the caller with that title that did not exist before.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @param string $title Title asked for.
	 * @param list<int> $before Ids of the caller's boards before the write.
	 * @return \OCA\Deck\Db\Board|null The board, or null when there is none.
	 */
	private function newBoard(string $userId, string $title, array $before): ?\OCA\Deck\Db\Board {
		$found = null;
		foreach ($this->gateway->ownedBoards($userId) as $board) {
			if (!in_array((int)$board->getId(), $before, true) && (string)$board->getTitle() === $title && (int)$board->getDeletedAt() === 0
				&& ($found === null || (int)$board->getId() > (int)$found->getId())) {
				$found = $board;
			}
		}

		return $found;
	}

	/**
	 * Creates the lists and the cards of an existing board, collecting what could not be created.
	 *
	 * @param int $boardId Board just created.
	 * @param string $boardTitle Its title as Deck stored it.
	 * @param array{stacks: list<array{title: string, cards: list<array{title: string, description: string, duedate: string|null, assignees: list<string>}>}>} $blueprint Validated structure.
	 * @param string $userId UID of the authenticated caller.
	 * @param list<string> $warnings Warnings so far, such as a board saved before Deck failed.
	 * @param bool $probableBoard Whether the board was found again only by its title: nothing is built inside it.
	 * @return array<string, mixed> Payload that says what exists and what does not.
	 */
	private function build(int $boardId, string $boardTitle, array $blueprint, string $userId, array $warnings = [], bool $probableBoard = false): array {
		$stacks = [];
		$failed = [];
		$unconfirmed = 0;
		$probables = $probableBoard ? 1 : 0;
		$cardTotal = 0;

		foreach ($blueprint['stacks'] as $position => $wanted) {
			if ($probableBoard) {
				// A board that may be somebody else's call: no list is written in it.
				$failed[] = ['kind' => 'list', 'title' => $wanted['title'], 'reason' => DeckMessages::probableParentNotBuilt(), 'cardsNotCreated' => count($wanted['cards'])];
				continue;
			}
			$probableStack = false;
			try {
				$known = array_column($stacks, 'id');
				$stack = $this->afterApproximateWrite(
					fn () => $this->gateway->createStack($userId, $boardId, $wanted['title'], $position),
					fn () => self::newStack($this->gateway->stacksOf($userId, $boardId), $wanted['title'], $known),
					$warnings,
					$probableStack,
				);
			} catch (DeckUnconfirmedException $e) {
				$warnings[] = $this->unconfirmed('list', $wanted['title'], $e);
				$unconfirmed++;
				continue;
			} catch (\Throwable $e) {
				$failed[] = [
					'kind' => 'list',
					'title' => $wanted['title'],
					'reason' => $this->reason($e),
					'cardsNotCreated' => count($wanted['cards']),
				];
				continue;
			}

			$probables += $probableStack ? 1 : 0;
			$cards = [];
			foreach ($wanted['cards'] as $order => $card) {
				if ($probableStack) {
					// A list that may be somebody else's call: no card is written in it.
					$failed[] = ['kind' => 'card', 'list' => $wanted['title'], 'title' => $card['title'], 'reason' => DeckMessages::probableParentNotBuilt()];
					continue;
				}
				$probableCard = false;
				try {
					$known = array_column($cards, 'id');
					$created = $this->afterApproximateWrite(
						fn () => $this->gateway->createCard($userId, (int)$stack->getId(), $card['title'], $card['description'], $card['duedate'], $order),
						fn () => $this->gateway->findCreatedCard($userId, (int)$stack->getId(), $card['title'], $known),
						$warnings,
						$probableCard,
					);
				} catch (DeckUnconfirmedException $e) {
					$warnings[] = $this->unconfirmed('card', $card['title'], $e);
					$unconfirmed++;
					continue;
				} catch (\Throwable $e) {
					$failed[] = ['kind' => 'card', 'list' => $wanted['title'], 'title' => $card['title'], 'reason' => $this->reason($e)];
					continue;
				}
				$cards[] = ['id' => (int)$created->getId(), 'title' => $card['title']] + ($probableCard ? ['confirmed' => 'probable'] : []);
				if ($probableCard) {
					$probables++;
					// Found by likeness, so maybe somebody else's card: nothing is written on it.
					if ($card['assignees'] !== []) {
						$warnings[] = DeckMessages::probableCardNotAssigned();
					}
					continue;
				}

				foreach ($card['assignees'] as $assignee) {
					try {
						$this->afterWrite(
							fn () => $this->gateway->assignCardUser($userId, (int)$created->getId(), $assignee),
							fn () => $this->assignmentOf($userId, (int)$created->getId(), $assignee),
							$warnings,
						);
					} catch (\Throwable $e) {
						// The card exists: never turn this into something that invites a second attempt.
						$this->logger->warning('MCP Deck assignment failed after creation ({exception})', ['exception' => $e::class]);
						$warnings[] = Translator::t('The card \'%s\' was created, but an assignee could not be assigned. Read the card before retrying in Deck.', [$card['title']]);
					}
				}
			}
			$cardTotal += count($cards);
			$stacks[] = ['id' => (int)$stack->getId(), 'title' => $wanted['title']] + ($probableStack ? ['confirmed' => 'probable'] : []) + ['cards' => $cards];
		}

		$summary = $probableBoard ? DeckMessages::probablyCreated() : $this->summary($boardTitle, count($stacks), $cardTotal, count($failed));

		return [
			'created' => ['board' => ['id' => $boardId, 'title' => $boardTitle] + ($probableBoard ? ['confirmed' => 'probable'] : []), 'stacks' => $stacks],
			'failed' => $failed,
			'warnings' => $warnings,
			'complete' => $failed === [] && $unconfirmed === 0 && $probables === 0,
			'summary' => ($unconfirmed === 0 && $probables === 0) || $probableBoard ? $summary
				: Translator::t('%s Some items could not be confirmed; see \'warnings\' before retrying.', [$summary]),
		];
	}

	/**
	 * Warning for a list or card whose creation threw and could not be read back: maybe created, so not in `failed`.
	 *
	 * @param string $kind `list` or `card`.
	 * @param string $title Its title.
	 * @param DeckUnconfirmedException $e What the read-back raised; only the class of the original exception is logged.
	 * @return string Caller-safe warning.
	 */
	private function unconfirmed(string $kind, string $title, DeckUnconfirmedException $e): string {
		$this->logger->warning('MCP Deck tool step not confirmed: {tool} ({exception})', [
			'tool' => self::TOOL,
			'exception' => $e->getPrevious() === null ? $e::class : $e->getPrevious()::class,
		]);

		return DeckMessages::boardItemUnconfirmed($kind, $title);
	}

	/**
	 * Safe reason of a failed step: logged by class, worded without any Deck detail.
	 *
	 * @param \Throwable $e What the gateway raised.
	 * @return string Caller-safe message.
	 */
	private function reason(\Throwable $e): string {
		$this->logger->error('MCP Deck tool step failed: {tool} ({exception})', ['tool' => self::TOOL, 'exception' => $e::class]);

		return DeckErrors::messageFor($e);
	}

	/**
	 * One sentence that says what exists, so the model does not have to infer it from the lists.
	 *
	 * @param string $board Board title.
	 * @param int $stacks Lists created.
	 * @param int $cards Cards created.
	 * @param int $failed Steps that failed.
	 * @return string The sentence in the language of the user.
	 */
	private function summary(string $board, int $stacks, int $cards, int $failed): string {
		$made = Translator::t('%s and %s', [
			Translator::n('%n list', '%n lists', $stacks),
			Translator::n('%n card', '%n cards', $cards),
		]);
		if ($failed === 0) {
			return Translator::t('The board \'%s\' was created with %s.', [$board, $made]);
		}

		return Translator::t('The board \'%s\' exists with %s, but %s could not be created. Nothing was undone; see \'failed\' before retrying.', [
			$board,
			$made,
			Translator::n('%n item', '%n items', $failed),
		]);
	}
}
