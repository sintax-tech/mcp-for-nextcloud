<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\Label;
use OCA\Deck\Db\Stack;
use OCA\Deck\NoPermissionException;
use OCP\AppFramework\Db\DoesNotExistException;

require_once __DIR__ . '/../../Tools/Deck/Stubs/deck_stubs.php';

/**
 * The database and the permission rules of a Deck app in memory, behind the native services of
 * {@see NativeDeckServices}: two boards (Comercial of alice, Diretoria of pedro shared with alice for reading and
 * editing), their lists and cards, held as plain rows. Every native write (create, reorder, delete, restore) is
 * recorded with its arguments and the account the services were bound to: the observable effect of the real
 * {@see \OCA\Mcp\Modules\Deck\Integration\DeckServiceGateway}. Ids of created cards are sequential and every stamp comes from the
 * fixed {@see self::NOW}, so two runs store the same rows.
 */
final class NativeDeck {
	/** The clock of the fake database: creation, modification and deletion stamps. */
	public const NOW = 1_767_323_045;
	/** Board of alice. */
	public const OWN_BOARD = 20;
	/** Board of pedro, shared with alice. */
	public const SHARED_BOARD = 30;

	/** @var array<int, array{title:string, owner:string, deletedAt:int}> boards by id */
	public array $boards = [
		self::OWN_BOARD => ['title' => 'Comercial', 'owner' => 'alice', 'deletedAt' => 0],
		self::SHARED_BOARD => ['title' => 'Diretoria', 'owner' => 'pedro', 'deletedAt' => 0],
	];
	/** @var array<int, array<string, list<int>>> ACL entries by board: permission levels granted per account */
	public array $acl = [self::SHARED_BOARD => ['alice' => [Acl::PERMISSION_READ, Acl::PERMISSION_EDIT]]];
	/** @var array<int, array{boardId:int, title:string, order:int, deletedAt:int}> lists by id */
	public array $stacks = [
		2 => ['boardId' => self::OWN_BOARD, 'title' => 'A fazer', 'order' => 0, 'deletedAt' => 0],
		3 => ['boardId' => self::OWN_BOARD, 'title' => 'Feito', 'order' => 1, 'deletedAt' => 0],
		4 => ['boardId' => self::OWN_BOARD, 'title' => 'Arquivo', 'order' => 2, 'deletedAt' => 0],
		5 => ['boardId' => self::OWN_BOARD, 'title' => 'Rascunhos', 'order' => 3, 'deletedAt' => 0],
		6 => ['boardId' => self::SHARED_BOARD, 'title' => 'Pauta', 'order' => 0, 'deletedAt' => 0],
	];
	/** @var array<int, array{stackId:int, title:string, description:string, duedate:string|null, owner:string, order:int, archived:bool, deletedAt:int, createdAt:int, lastModified:int}> cards by id */
	public array $cards = [];
	/** @var array<int, array{boardId:int, title:string, color:string}> labels by id; none until a contract seeds them */
	public array $labels = [];
	/** @var array<int, list<int>> label ids assigned to each card, by card id */
	public array $cardLabels = [];
	/** @var array<int, list<int>> native outgoing dependency ids */
	public array $edges = [];
	/** @var list<array{call:string, as:string|null, args:list<mixed>}> native writes, in order */
	public array $writes = [];

	/** Account the services are bound to (BoardService::setUserId), as the Deck DI container reads it. */
	private ?string $user = null;
	/** Id of the next card the fake database inserts. */
	private int $nextCard = 100;

	/** @return void seeds the cards of the lists */
	public function __construct() {
		foreach ([7 => [2, 'Proposta comercial'], 8 => [2, 'Contrato'], 9 => [2, 'Reunião de kickoff'], 10 => [3, 'Entregue'],
			12 => [6, 'Orçamento anual']] as $id => [$stackId, $title]) {
			$this->cards[$id] = ['stackId' => $stackId, 'title' => $title, 'description' => '', 'duedate' => null, 'owner' => 'alice',
				'order' => count(array_filter($this->cards, static fn (array $card): bool => $card['stackId'] === $stackId)),
				'archived' => false, 'deletedAt' => 0, 'createdAt' => 1_700_000_000, 'lastModified' => 1_700_000_000];
		}
	}

	/**
	 * @param string $userId account the services act for from now on
	 * @return void
	 */
	public function bind(string $userId): void {
		$this->user = $userId;
	}

	/**
	 * Deck's `PermissionService::checkPermission()`: the entity is resolved to its board, a deleted card is refused
	 * unless allowed, a deleted board always, and the bound account needs the level on the board.
	 *
	 * @param string|null $kind 'card', 'stack' or null when the id is a board id
	 * @param int $id entity id
	 * @param int $permission one of the Acl::PERMISSION_* levels
	 * @param bool $allowDeletedCard whether a card in the trash may be read
	 * @return void
	 * @throws NoPermissionException as Deck answers a missing or denied entity
	 */
	public function check(?string $kind, int $id, int $permission, bool $allowDeletedCard = false): void {
		$boardId = match ($kind) {
			'card' => $this->boardOfCard($id),
			'stack' => $this->boardOfStack($id),
			default => isset($this->boards[$id]) ? $id : null,
		};
		if ($boardId === null || ($kind === 'card' && !$allowDeletedCard && $this->cards[$id]['deletedAt'] !== 0)
			|| $this->boards[$boardId]['deletedAt'] !== 0 || !$this->permits($boardId, (string)$this->user, $permission)) {
			throw new NoPermissionException('Permission denied');
		}
	}

	/**
	 * @param int $boardId board
	 * @param string $userId account
	 * @param int $permission Acl level
	 * @return bool whether the account holds it: the owner holds every level
	 */
	public function permits(int $boardId, string $userId, int $permission): bool {
		return $this->boards[$boardId]['owner'] === $userId || in_array($permission, $this->acl[$boardId][$userId] ?? [], true);
	}

	/**
	 * @param int $cardId card
	 * @return int|null its board, as CardMapper::findBoardId() answers
	 */
	public function boardOfCard(int $cardId): ?int {
		return isset($this->cards[$cardId]) ? $this->boardOfStack($this->cards[$cardId]['stackId']) : null;
	}

	/**
	 * @param int $stackId list
	 * @return int|null its board, as StackMapper::findBoardId() answers
	 */
	public function boardOfStack(int $stackId): ?int {
		return $this->stacks[$stackId]['boardId'] ?? null;
	}

	/**
	 * @param int $cardId card
	 * @return Card the entity, read fresh from the row
	 * @throws DoesNotExistException for an unknown id
	 */
	public function card(int $cardId): Card {
		$row = $this->cards[$cardId] ?? throw new DoesNotExistException('card');
		// Deck hands the `datetime` column out as a DateTime; the row keeps the UTC instant the gateway wrote.
		$row['duedate'] = $row['duedate'] === null ? null : new \DateTime($row['duedate']);
		$labels = array_map(fn (int $id): Label => $this->label($id), $this->cardLabels[$cardId] ?? []);
		return new Card(['id' => $cardId, 'type' => 'note', 'labels' => $labels] + $row);
	}

	/**
	 * @param int $labelId label
	 * @return Label the entity
	 */
	public function label(int $labelId): Label {
		return new Label(['id' => $labelId] + $this->labels[$labelId]);
	}

	/**
	 * @param int $stackId list
	 * @param bool $archived archived cards instead of the active ones
	 * @return list<Card> the cards of the list that are not in the trash, by order then id
	 */
	public function cardsOf(int $stackId, bool $archived = false): array {
		$ids = array_keys(array_filter($this->cards, static fn (array $card): bool => $card['stackId'] === $stackId
			&& $card['deletedAt'] === 0 && $card['archived'] === $archived));
		usort($ids, fn (int $a, int $b): int => [$this->cards[$a]['order'], $a] <=> [$this->cards[$b]['order'], $b]);
		return array_map(fn (int $id): Card => $this->card($id), $ids);
	}

	/**
	 * @param int $boardId board
	 * @param bool $deleted the lists in the trash instead of the active ones
	 * @param bool $withCards whether each list carries its active cards (StackService::findAll())
	 * @return list<Stack> the lists of the board, by order
	 */
	public function stacksOf(int $boardId, bool $deleted = false, bool $withCards = false): array {
		$ids = array_keys(array_filter($this->stacks, static fn (array $stack): bool => $stack['boardId'] === $boardId
			&& ($stack['deletedAt'] !== 0) === $deleted));
		usort($ids, fn (int $a, int $b): int => $this->stacks[$a]['order'] <=> $this->stacks[$b]['order']);
		return array_map(fn (int $id): Stack => $this->stack($id, $withCards), $ids);
	}

	/**
	 * @param int $stackId list
	 * @param bool $withCards whether it carries its active cards
	 * @return Stack the entity
	 */
	public function stack(int $stackId, bool $withCards = false): Stack {
		return new Stack(['id' => $stackId] + $this->stacks[$stackId] + ($withCards ? ['cards' => $this->cardsOf($stackId)] : []));
	}

	/**
	 * @param int $boardId board
	 * @return Board the entity
	 */
	public function board(int $boardId): Board {
		return new Board(['id' => $boardId, 'lastModified' => 1_700_000_000] + $this->boards[$boardId]);
	}

	/** @return list<Board> the boards the bound account may read and that are not in the trash */
	public function readableBoards(): array {
		$ids = array_keys(array_filter($this->boards, fn (array $board, int $id): bool => $board['deletedAt'] === 0
			&& $this->permits($id, (string)$this->user, Acl::PERMISSION_READ), ARRAY_FILTER_USE_BOTH));
		return array_map(fn (int $id): Board => $this->board($id), $ids);
	}

	/**
	 * Records one native write.
	 *
	 * @param string $call "Service::method"
	 * @param list<mixed> $args its arguments, as the gateway passed them
	 * @return void
	 */
	public function record(string $call, array $args): void {
		$this->writes[] = ['call' => $call, 'as' => $this->user, 'args' => $args];
	}

	/**
	 * CardService::create() after its EDIT check: inserts the card with the next id, stamped now.
	 *
	 * @param string $title title
	 * @param int $stackId list
	 * @param int $order position
	 * @param string $owner owner
	 * @param string $description description
	 * @param string|null $duedate due instant as the gateway sent it
	 * @return Card the inserted card
	 */
	public function insertCard(string $title, int $stackId, int $order, string $owner, string $description, ?string $duedate): Card {
		$id = $this->nextCard++;
		$this->cards[$id] = ['stackId' => $stackId, 'title' => $title, 'description' => $description, 'duedate' => $duedate, 'owner' => $owner,
			'order' => $order, 'archived' => false, 'deletedAt' => 0, 'createdAt' => self::NOW, 'lastModified' => self::NOW];
		return $this->card($id);
	}

	/** @return array{boards: array<int, array<string, mixed>>, stacks: array<int, array<string, mixed>>, cards: array<int, array<string, mixed>>, writes: list<array<string, mixed>>} every row and every native write */
	public function state(): array {
		return ['boards' => $this->boards, 'stacks' => $this->stacks, 'cards' => $this->cards, 'writes' => $this->writes];
	}
}
