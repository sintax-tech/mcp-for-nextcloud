<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

use OCA\Deck\Db\Assignment;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\Stack;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\ISession;
use OCP\Share\IShare;
use Psr\Container\ContainerInterface;

/**
 * Facade for Deck access, sharing native services with focused label, reference, file and dependency gateways.
 *
 * Deck exposes no OCP API, so the services of `OCA\Deck\*` are resolved lazily through the
 * container: injecting them in a constructor would break the `mcp` container on instances where
 * the Deck app is disabled. Class names are strings so this file can be autoloaded with the Deck
 * absent. Base signatures come from Deck v1.17.5 (NC33); label queries and conditional dependency
 * fields are also checked against the NC32–35 releases pinned in the integration harness.
 *
 * Board, stack, card, assignment and undo work is delegated to focused area classes. This facade does
 * not bind the caller again before that delegation.
 */
final class DeckServiceGateway implements DeckGatewayInterface {
	/** Lazy Deck services shared by every area of this facade. */
	private DeckServiceContext $services;
	/** Reference boundary constructed once and shared by the areas that filter a card. */
	private CardReferenceFilter $references;
	/** Card totals with no ACL check. */
	private DeckCardCounts $counts;
	/** Owner of a board, a list or a card. */
	private DeckOwnership $ownership;
	/** Validation and assignment of accounts on a card. */
	private DeckAssignments $assignments;
	/** Board listing, creation, managed count and owned boards. */
	private DeckBoards $boards;
	/** Stack listing, creation, managed count and trash reread. */
	private DeckStacks $stacks;
	/** Card reads, assignments and the card a failed creation saved. */
	private DeckCardQuery $query;
	/** Follow-up scan of boards the caller may manage. */
	private DeckFollowup $followup;
	/** Card creation, update, move and delete. */
	private DeckCardChanges $changes;
	/** Empty delete and the restore that follows a card arriving mid-delete. */
	private DeckUndo $undo;
	/** Board labels and card-label associations. */
	private DeckLabelGateway $labels;
	/** Native file shares attached to a card. */
	private DeckCardFileGateway $files;
	/** Dependency edges of a card. */
	private DeckDependencyGateway $dependencies;

	/**
	 * Compose focused gateways over one injected, lazy native service context.
	 *
	 * @param ContainerInterface $container Nextcloud server container, resolves Deck services by FQCN.
	 * @param ITimeFactory $time Clock the follow-up reads `overdue` from, so it can be pinned in a test.
	 * @param UserTimezone|null $zones Timezone of the caller, deciding which day a due date falls on;
	 *     PHP's default when absent.
	 * @param ISession|null $session Session the DI container reads the Deck services' `userId` from; checked
	 *     against the caller before every Deck call, skipped when absent.
	 */
	public function __construct(
		ContainerInterface $container,
		private ITimeFactory $time,
		private ?UserTimezone $zones = null,
		?ISession $session = null,
	) {
		$this->services = new DeckServiceContext($container, $session);
		$this->references = new CardReferenceFilter($this->services);
		$this->counts = new DeckCardCounts($this->services);
		$this->ownership = new DeckOwnership($this->services);
		$this->assignments = new DeckAssignments($this->services);
		$this->boards = new DeckBoards($this->services, $this->counts);
		$this->stacks = new DeckStacks($this->services, $this->references, $this->counts);
		$this->query = new DeckCardQuery($this->services, $this->references, $this->time);
		$this->followup = new DeckFollowup($this->services, $this->references, $this->zones, $this->time, $this->boards, $this->query);
		$this->changes = new DeckCardChanges($this->services, $this->references, $this->zones);
		$this->undo = new DeckUndo($this->services, $this->counts, $this->ownership, $this->boards, $this->stacks);
		$this->labels = new DeckLabelGateway($this->services, $this->references);
		$this->files = new DeckCardFileGateway($this->services, $this->references);
		$this->dependencies = new DeckDependencyGateway($this->services, $this->references);
	}

	/** {@inheritDoc} */
	public function listBoards(string $userId): array {
		return $this->boards->listBoards($userId);
	}

	/** {@inheritDoc} */
	public function listLabels(string $userId, int $boardId, int $limit, int $offset): array {
		return $this->labels->listLabels($userId, $boardId, $limit, $offset);
	}

	/** {@inheritDoc} */
	public function cardLabelState(string $userId, int $cardId, array $requested): array {
		return $this->labels->cardLabelState($userId, $cardId, $requested);
	}

	/** {@inheritDoc} */
	public function changeCardLabel(string $userId, int $cardId, int $labelId, bool $assign,
		array $requested, array $expected): void {
		$this->labels->changeCardLabel($userId, $cardId, $labelId, $assign, $requested, $expected);
	}

	/** {@inheritDoc} */
	public function listStacks(string $userId, int $boardId): array {
		return $this->stacks->listStacks($userId, $boardId);
	}

	/** {@inheritDoc} */
	public function listCards(string $userId, int $stackId, int $limit, int $offset): array {
		return $this->query->listCards($userId, $stackId, $limit, $offset);
	}

	/** {@inheritDoc} */
	public function followupCards(string $userId, string $status, ?int $boardId, ?string $assignee, ?string $dueBefore, int $limit): array {
		return $this->followup->followupCards($userId, $status, $boardId, $assignee, $dueBefore, $limit);
	}

	/** {@inheritDoc} */
	public function findCard(string $userId, int $cardId): Card {
		return $this->query->findCard($userId, $cardId);
	}

	/** {@inheritDoc} */
	public function validateAssignees(string $userId, int $stackId, array $assignees): array {
		return $this->assignments->validateAssignees($userId, $stackId, $assignees);
	}

	/** {@inheritDoc} */
	public function assignCardUser(string $userId, int $cardId, string $assignee, bool $deferDelivery = false): Assignment {
		return $this->assignments->assignCardUser($userId, $cardId, $assignee, $deferDelivery);
	}

	/** {@inheritDoc} */
	public function unassignCardUser(string $userId, int $cardId, string $assignee): Assignment {
		return $this->assignments->unassignCardUser($userId, $cardId, $assignee);
	}

	/** {@inheritDoc} */
	public function createCard(string $userId, int $stackId, string $title, string $description, ?string $duedate, ?int $order = null): Card {
		return $this->changes->createCard($userId, $stackId, $title, $description, $duedate, $order);
	}

	/** {@inheritDoc} */
	public function updateCard(string $userId, Card $card, string $title, string $description, ?string $duedate): Card {
		return $this->changes->updateCard($userId, $card, $title, $description, $duedate);
	}

	/** {@inheritDoc} */
	public function moveCard(string $userId, int $cardId, int $stackId, ?int $order): Card {
		return $this->changes->moveCard($userId, $cardId, $stackId, $order);
	}

	/** {@inheritDoc} */
	public function deleteCard(string $userId, int $cardId): Card {
		return $this->changes->deleteCard($userId, $cardId);
	}

	/** {@inheritDoc} */
	public function stackOwnership(string $userId, int $stackId): array {
		return $this->ownership->stackOwnership($userId, $stackId);
	}

	/** {@inheritDoc} */
	public function cardOwnership(string $userId, int $cardId): array {
		return $this->ownership->cardOwnership($userId, $cardId);
	}

	/** {@inheritDoc} */
	public function cardBoardId(string $userId, int $cardId): int {
		return $this->query->cardBoardId($userId, $cardId);
	}

	/** {@inheritDoc} */
	public function createBoard(string $userId, string $title, string $color): Board {
		return $this->boards->createBoard($userId, $title, $color);
	}

	/** {@inheritDoc} */
	public function createStack(string $userId, int $boardId, string $title, ?int $order): Stack {
		return $this->stacks->createStack($userId, $boardId, $title, $order);
	}

	/** {@inheritDoc} */
	public function boardOwnership(string $userId, int $boardId): array {
		return $this->ownership->boardOwnership($userId, $boardId);
	}

	/** {@inheritDoc} */
	public function stackCardCount(string $userId, int $stackId): int {
		return $this->stacks->stackCardCount($userId, $stackId);
	}

	/** {@inheritDoc} */
	public function boardCardCount(string $userId, int $boardId): int {
		return $this->boards->boardCardCount($userId, $boardId);
	}

	/** {@inheritDoc} */
	public function deleteEmptyStack(string $userId, int $stackId): Stack {
		return $this->undo->deleteEmptyStack($userId, $stackId);
	}

	/** {@inheritDoc} */
	public function deleteEmptyBoard(string $userId, int $boardId): Board {
		return $this->undo->deleteEmptyBoard($userId, $boardId);
	}

	/** {@inheritDoc} */
	public function cardState(string $userId, int $cardId): Card {
		return $this->query->cardState($userId, $cardId);
	}

	/** {@inheritDoc} */
	public function findCreatedCard(string $userId, int $stackId, string $title, array $excludeIds = []): ?Card {
		return $this->query->findCreatedCard($userId, $stackId, $title, $excludeIds);
	}

	/** {@inheritDoc} */
	public function cardAssignments(string $userId, int $cardId): array {
		return $this->query->cardAssignments($userId, $cardId);
	}

	/** {@inheritDoc} */
	public function stacksOf(string $userId, int $boardId): array {
		return $this->stacks->stacksOf($userId, $boardId);
	}

	/** {@inheritDoc} */
	public function deletedStack(string $userId, int $stackId): ?Stack {
		return $this->stacks->deletedStack($userId, $stackId);
	}

	/** {@inheritDoc} */
	public function ownedBoards(string $userId): array {
		return $this->boards->ownedBoards($userId);
	}

	/** {@inheritDoc} Parent listings are fresh, scoped only to this call. */
	public function cardFileContext(string $userId, int $cardId, bool $edit): array {
		return $this->files->cardFileContext($userId, $cardId, $edit);
	}

	/** {@inheritDoc} Unlike AttachmentService::findAll, this never loads legacy deck_file attachments. */
	public function cardFileShares(string $userId, int $cardId): array {
		return $this->files->cardFileShares($userId, $cardId);
	}

	/** {@inheritDoc} An unreadable/missing/wrong-card association has no public metadata. */
	public function cardFileShare(string $userId, int $cardId, int $attachmentId): ?IShare {
		return $this->files->cardFileShare($userId, $cardId, $attachmentId);
	}

	/** {@inheritDoc} Only the boolean for the already authorized ID is returned. */
	public function cardFileShareExists(string $userId, int $cardId, int $attachmentId, File $node): bool {
		return $this->files->cardFileShareExists($userId, $cardId, $attachmentId, $node);
	}

	/** {@inheritDoc} The 'file' type is literal; this path cannot dispatch legacy file deletion. */
	public function unlinkCardFile(string $userId, int $cardId, int $attachmentId, array $expected): void {
		$this->files->unlinkCardFile($userId, $cardId, $attachmentId, $expected);
	}

	/** {@inheritDoc} Native adjacency is loaded only after READ, without attachment enrichment. */
	public function dependencyNode(string $userId, int $cardId, ?array &$view = null): array {
		return $this->dependencies->dependencyNode($userId, $cardId, $view);
	}

	/** {@inheritDoc} Hidden associations and lastModified never enter a plan fingerprint. */
	public function dependencyChangeState(string $userId, int $originId, int $targetId, bool $assign,
		bool $checkCycle = true): array {
		return $this->dependencies->dependencyChangeState($userId, $originId, $targetId, $assign, $checkCycle);
	}

	/** {@inheritDoc} Native APIs still leave a check-to-write race, documented in every plan. */
	public function changeDependency(string $userId, int $originId, int $targetId, bool $assign, array $expected): void {
		$this->dependencies->changeDependency($userId, $originId, $targetId, $assign, $expected);
	}
}
