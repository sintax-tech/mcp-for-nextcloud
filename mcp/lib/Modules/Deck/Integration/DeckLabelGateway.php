<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\LabelMapper;
use OCA\Deck\Db\Stack;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\PermissionService;
use OCA\Mcp\Modules\Deck\Editing\CardLabelChanges;
use OCA\Mcp\Modules\Deck\Failures\DeckConflictException;
use OCA\Mcp\Modules\Deck\Presentation\DeckMessages;
use OCA\Mcp\Modules\Deck\Failures\DeckRefusalException;

/** Native board-label reads and incremental card-label associations. */
final class DeckLabelGateway {
	/**
	 * Share the caller-bound native service context and reference boundary of one facade.
	 *
	 * @param DeckServiceContext $services Lazily resolved services from the injected container.
	 * @param CardReferenceFilter $references Fresh READ boundary with operation-local parent listings.
	 */
	public function __construct(private DeckServiceContext $services, private CardReferenceFilter $references) {
	}

	/**
	 * Labels of a readable board, with pagination applied after the READ check.
	 *
	 * @param string $userId Authenticated caller.
	 * @param int $boardId Board requiring READ and a fresh active-parent lookup.
	 * @param int $limit Maximum labels returned by the native mapper.
	 * @param int $offset Native mapper pagination offset.
	 * @return list<\OCA\Deck\Db\Label> Labels in the native mapper order.
	 * @throws \Throwable On denied/deleted board or native query failure.
	 */
	public function listLabels(string $userId, int $boardId, int $limit, int $offset): array {
		$boards = $this->services->bindUser($userId);
		$this->services->get(PermissionService::class)->checkPermission(null, $boardId, Acl::PERMISSION_READ);
		// BoardService::find() and PermissionService retain board entities in the request. The listing queries anew.
		if (!in_array($boardId, array_map(static fn (Board $b): int => (int)$b->getId(), array_filter(
			$boards->findAll(CardReferenceFilter::FRESH_BOARD_SINCE, false, true),
			static fn (Board $b): bool => $b->getDeletedAt() === 0,
		)), true)) {
			throw new NoPermissionException('Permission denied');
		}
		return array_values($this->services->get(LabelMapper::class)->findAll($boardId, $limit, $offset));
	}

	/**
	 * Fresh editable card, active parents and board-local labels. Inherited foreign links remain internal.
	 * @param string $userId Authenticated caller.
	 * @param int $cardId Card to read after READ+EDIT checks.
	 * @param list<int> $requested Label IDs, including removals currently absent from the card.
	 * @return array{card: Card, boardId: int, board: array, labels: list<array>, requested: list<array>, foreignAssigned: list<int>}
	 * @throws \Throwable When the caller, card, parents or requested labels are unavailable.
	 */
	public function cardLabelState(string $userId, int $cardId, array $requested): array {
		$boards = $this->services->bindUser($userId);
		$mapper = $this->services->get(CardMapper::class);
		$permissions = $this->services->get(PermissionService::class);
		$permissions->checkPermission($mapper, $cardId, Acl::PERMISSION_READ);
		$permissions->checkPermission($mapper, $cardId, Acl::PERMISSION_EDIT);
		$card = $mapper->find($cardId);
		$boardId = (int)$mapper->findBoardId($cardId);
		$board = null;
		foreach ($boards->findAll(CardReferenceFilter::FRESH_BOARD_SINCE, false, true) as $candidate) {
			if ((int)$candidate->getId() === $boardId && (int)$candidate->getDeletedAt() === 0) {
				$board = $candidate;
				break;
			}
		}
		if ($board === null || (int)$card->getDeletedAt() !== 0 || $card->getArchived() || $board->getArchived()) {
			throw new DeckRefusalException(DeckMessages::errorLabelsUnavailable());
		}
		$stackIds = array_map(static fn (Stack $s): int => (int)$s->getId(),
			$this->services->get(StackMapper::class)->findAll($boardId));
		if (!in_array((int)$card->getStackId(), $stackIds, true)) {
			throw new DeckRefusalException(DeckMessages::errorLabelsUnavailable());
		}
		$catalog = [];
		foreach ($this->services->get(LabelMapper::class)->findAll($boardId) as $label) {
			if ((int)$label->getBoardId() === $boardId) {
				$catalog[(int)$label->getId()] = ['id' => (int)$label->getId(), 'boardId' => $boardId,
					'title' => (string)$label->getTitle(), 'color' => (string)$label->getColor()];
			}
		}
		$assigned = array_map(static fn ($label): int => (int)$label->getId(), $card->getLabels() ?? []);
		sort($assigned);
		// A native cross-board move may leave old links. Preserve them without exposing their IDs or metadata.
		// Only explicitly requested labels must belong to this board; foreign links remain HMAC-only state.
		foreach ($requested as $id) {
			if (!isset($catalog[$id])) {
				throw new DeckRefusalException(DeckMessages::errorLabelsUnavailable());
			}
		}
		$select = static function (array $ids) use ($catalog): array {
			$ids = array_values(array_unique($ids));
			sort($ids);
			return array_map(static fn (int $id): array => $catalog[$id], $ids);
		};
		$owner = (string)$board->getOwner();
		return ['card' => $this->references->filter($userId, $card), 'boardId' => $boardId,
			'board' => ['owner' => $owner, 'ownerDisplayName' => $this->services->displayName($owner), 'name' => (string)$board->getTitle()],
			'labels' => $select(array_values(array_intersect($assigned, array_keys($catalog)))),
			'foreignAssigned' => array_values(array_diff($assigned, array_keys($catalog))),
			'requested' => $select($requested)];
	}

	/**
	 * Rechecks the snapshot and ACL before one native incremental association write.
	 * @param string $userId Authenticated caller.
	 * @param int $cardId Card to edit.
	 * @param int $labelId One requested board-local label.
	 * @param bool $assign True to assign; false to remove only this link.
	 * @param list<int> $requested All requested IDs to revalidate.
	 * @param array<string, mixed> $expected Last observed canonical snapshot.
	 * @return void
	 * @throws DeckConflictException Before any native write when the snapshot differs.
	 * @throws \Throwable When validation or the native operation fails; the handler reads state again.
	 */
	public function changeCardLabel(string $userId, int $cardId, int $labelId, bool $assign,
		array $requested, array $expected): void {
		$state = $this->cardLabelState($userId, $cardId, $requested);
		if (!in_array($labelId, $requested, true) || CardLabelChanges::snapshot($state) !== $expected) {
			throw new DeckConflictException(DeckMessages::labelPlanChanged());
		}
		$cards = $this->services->get(CardService::class);
		// Deck v1.16.8–v1.19.0: each call changes one link; removeLabel itself lacks the foreign-board guard above.
		if ($assign) {
			$cards->assignLabel($cardId, $labelId);
		} else {
			$cards->removeLabel($cardId, $labelId);
		}
	}
}
