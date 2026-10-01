<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck\Handler;

use InvalidArgumentException;
use OCA\Mcp\Tools\Deck\CardCriteria;
use OCA\Mcp\Tools\Deck\CardFormatter;
use OCA\Mcp\Tools\Deck\CardInput;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckMessages;
use Psr\Log\LoggerInterface;

/**
 * Backs `deck_followup_cards`: the board-manager view of the Deck, for somebody checking whether
 * the work of their people actually moved.
 *
 * The gateway decides *which* cards belong to the follow-up (managed boards, status, assignee and
 * due-date bound), because that is what it has to filter while it queries; this handler validates
 * the arguments the JSON Schema cannot express and groups the answer by responsible person, which
 * is how a manager reads a follow-up: one group per person, nobody's card left out of every group.
 */
final class FollowupCardsHandler extends AbstractHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'deck_followup_cards';

	/** Cards answered when the caller sends no `limit`. */
	public const DEFAULT_LIMIT = 100;

	/** Hard ceiling on `limit`, so one call cannot ask for the whole instance. */
	public const MAX_LIMIT = 500;

	/**
	 * @param DeckGatewayInterface $gateway Deck access, the only door to the Deck app.
	 * @param LoggerInterface $logger Receives tool name and exception class on failure.
	 * @param CardFormatter $formatter Converts Deck cards into the tool payload.
	 */
	public function __construct(
		DeckGatewayInterface $gateway,
		LoggerInterface $logger,
		private CardFormatter $formatter,
	) {
		parent::__construct($gateway, $logger);
	}

	/**
	 * {@inheritDoc}
	 *
	 * The payload is `{groups: list<{assignee: {uid, displayName}|null, cards: list<card>}>,
	 * truncated: bool}`: one group per responsible person sorted by UID, with the group of cards
	 * nobody is assigned to (`assignee: null`) leading, since it sorts as the empty UID. A card
	 * assigned to several people appears in each of their groups, and `limit` counts cards before
	 * that repetition.
	 *
	 * @param array<string, mixed> $arguments `status`, `assignee`, `boardId`, `dueBefore`, `limit`.
	 * @param string $userId UID of the authenticated caller.
	 * @return array{content: list<array{type: string, text: string}>, isError?: bool} MCP result.
	 * @throws InvalidArgumentException When `status` or `dueBefore` is not a value the tool accepts.
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->run(function () use ($arguments, $userId): array {
			$status = (string)($arguments['status'] ?? CardCriteria::STATUS_OVERDUE);
			if (!in_array($status, CardCriteria::STATUSES, true)) {
				throw new InvalidArgumentException(DeckMessages::errorInvalidStatus());
			}

			$page = $this->gateway->followupCards(
				$userId,
				$status,
				isset($arguments['boardId']) ? (int)$arguments['boardId'] : null,
				isset($arguments['assignee']) ? (string)$arguments['assignee'] : null,
				CardInput::dueBefore($arguments['dueBefore'] ?? null),
				max(
					1,
					min(self::MAX_LIMIT, (int)($arguments['limit'] ?? self::DEFAULT_LIMIT)),
				),
			);

			return [
				'groups' => array_values($this->groupByAssignee($page['items'])),
				'truncated' => $page['truncated'],
			];
		});
	}

	/**
	 * {@inheritDoc}
	 */
	protected function toolName(): string {
		return self::TOOL;
	}

	/**
	 * Buckets the answer by the people responsible for each card, one bucket per person.
	 *
	 * @param list<array{card: \OCA\Deck\Db\Card, boardId: int}> $items Cards the gateway returned.
	 * @return array<string, array{assignee: array{uid: string, displayName: string}|null, cards: list<array<string, mixed>>}>
	 *     Buckets keyed by UID, empty string for the cards nobody is assigned to.
	 */
	private function groupByAssignee(array $items): array {
		$groups = [];

		foreach ($items as $item) {
			$card = $this->formatter->card($item['card'], $item['boardId']);
			$assignees = $card['assignedUsers'];

			if ($assignees === []) {
				$groups[''] ??= ['assignee' => null, 'cards' => []];
				$groups['']['cards'][] = $card;
				continue;
			}

			foreach ($assignees as $assignee) {
				$groups[$assignee['uid']] ??= ['assignee' => $assignee, 'cards' => []];
				$groups[$assignee['uid']]['cards'][] = $card;
			}
		}

		// The empty key of the unassigned group sorts first, and the people follow as plain UIDs.
		ksort($groups, SORT_STRING);

		return $groups;
	}
}
