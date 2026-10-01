<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck\Handler;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\Deck\BoardBlueprint;
use OCA\Mcp\Tools\Deck\DeckErrors;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use Psr\Log\LoggerInterface;

/**
 * Backs `deck_create_board`: a board, its lists and its first cards, built from one confirmed call.
 *
 * Everything that can be refused is refused first, by {@see BoardBlueprint}, so a bad structure writes nothing.
 * Once the board exists the handler never answers with an error: each list and card is its own step, a step
 * that fails is reported in `failed` and the others go on, and nothing already written is undone. An error
 * after a write is what made the client retry and build the same card twice in 0.9.0.
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
			// The one step whose failure is a plain error: until it succeeds nothing exists.
			$board = $this->gateway->createBoard($userId, $blueprint['title'], $blueprint['color']);

			return $this->build((int)$board->getId(), (string)$board->getTitle(), $blueprint, $userId);
		});
	}

	/**
	 * {@inheritDoc}
	 */
	protected function toolName(): string {
		return self::TOOL;
	}

	/**
	 * Creates the lists and the cards of an existing board, collecting what could not be created.
	 *
	 * @param int $boardId Board just created.
	 * @param string $boardTitle Its title as Deck stored it.
	 * @param array{stacks: list<array{title: string, cards: list<array{title: string, description: string, duedate: string|null, assignees: list<string>}>}>} $blueprint Validated structure.
	 * @param string $userId UID of the authenticated caller.
	 * @return array<string, mixed> Payload that says what exists and what does not.
	 */
	private function build(int $boardId, string $boardTitle, array $blueprint, string $userId): array {
		$stacks = [];
		$failed = [];
		$warnings = [];
		$cardTotal = 0;

		foreach ($blueprint['stacks'] as $position => $wanted) {
			try {
				$stack = $this->gateway->createStack($userId, $boardId, $wanted['title'], $position);
			} catch (\Throwable $e) {
				$failed[] = [
					'kind' => 'list',
					'title' => $wanted['title'],
					'reason' => $this->reason($e),
					'cardsNotCreated' => count($wanted['cards']),
				];
				continue;
			}

			$cards = [];
			foreach ($wanted['cards'] as $order => $card) {
				try {
					$created = $this->gateway->createCard($userId, (int)$stack->getId(), $card['title'], $card['description'], $card['duedate'], $order);
				} catch (\Throwable $e) {
					$failed[] = ['kind' => 'card', 'list' => $wanted['title'], 'title' => $card['title'], 'reason' => $this->reason($e)];
					continue;
				}
				$cards[] = ['id' => (int)$created->getId(), 'title' => $card['title']];

				foreach ($card['assignees'] as $assignee) {
					try {
						$this->gateway->assignCardUser($userId, (int)$created->getId(), $assignee);
					} catch (\Throwable $e) {
						// The card exists: never turn this into something that invites a second attempt.
						$this->logger->warning('MCP Deck assignment failed after creation ({exception})', ['exception' => $e::class]);
						$warnings[] = Translator::t('The card "%s" was created, but an assignee could not be assigned. Read the card before retrying in Deck.', [$card['title']]);
					}
				}
			}
			$cardTotal += count($cards);
			$stacks[] = ['id' => (int)$stack->getId(), 'title' => $wanted['title'], 'cards' => $cards];
		}

		return [
			'created' => ['board' => ['id' => $boardId, 'title' => $boardTitle], 'stacks' => $stacks],
			'failed' => $failed,
			'warnings' => $warnings,
			'complete' => $failed === [],
			'summary' => $this->summary($boardTitle, count($stacks), $cardTotal, count($failed)),
		];
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
			return Translator::t('The board "%s" was created with %s.', [$board, $made]);
		}

		return Translator::t('The board "%s" exists with %s, but %s could not be created. Nothing was undone; see "failed" before retrying.', [
			$board,
			$made,
			Translator::n('%n item', '%n items', $failed),
		]);
	}
}
