<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 Roman Matkivskyy <roman91@live.it>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\LabelMapper;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\LabelService;
use OCA\Deck\Service\PermissionService;
use OCA\Mcp\Modules\Deck\Failures\DeckConflictException;
use OCA\Mcp\Modules\Deck\Failures\DeckRefusalException;
use OCA\Mcp\Modules\Deck\Labels\LabelSnapshot;
use OCA\Mcp\Modules\Deck\Presentation\DeckLabelMessages;

/**
 * The labels of a board as a catalog: read, created, renamed, recoloured and deleted through Deck's LabelService.
 *
 * LabelService::create(title, color, boardId), update(id, title, color) and delete(id) have the same positional
 * parameters in the pinned Deck versions for NC34 and NC35; each checks MANAGE on the board, refuses an archived board and a title the
 * board already has. Deleting a label also removes it from every card of the board, with no trash.
 */
final class DeckBoardLabels implements DeckLabelCatalog {
	/** @param DeckServiceContext $services Lazily resolved services bound to the caller. */
	public function __construct(private DeckServiceContext $services) {
	}

	/**
	 * The catalog of a board the caller manages, with how many cards carry each label.
	 *
	 * @param string $userId Authenticated caller.
	 * @param int $boardId Board requiring MANAGE, active and not archived.
	 * @return array{boardId: int, board: array{owner: string, ownerDisplayName: string, name: string}, labels: list<array{id: int, boardId: int, title: string, color: string}>, usage: array<int, int>}
	 * @throws \Throwable When the board is unavailable to manage or a native query fails.
	 */
	public function boardLabelState(string $userId, int $boardId): array {
		$boards = $this->services->bindUser($userId);
		$this->requireApi();
		$this->services->get(PermissionService::class)->checkPermission(null, $boardId, Acl::PERMISSION_MANAGE);
		$board = null;
		// BoardService::find() answers from the entity cache of the request; the listing queries anew.
		foreach ($boards->findAll(CardReferenceFilter::FRESH_BOARD_SINCE, false, true) as $candidate) {
			if ((int)$candidate->getId() === $boardId && (int)$candidate->getDeletedAt() === 0) {
				$board = $candidate;
				break;
			}
		}
		if (!$board instanceof Board || $board->getArchived()) {
			throw new DeckRefusalException(DeckLabelMessages::boardUnavailable());
		}
		$mapper = $this->services->get(LabelMapper::class);
		$usage = [];
		$associations = [];
		// One row per card association, deleted and archived cards of the board included.
		foreach ($mapper->findAssignedLabelsForBoard($boardId) as $assigned) {
			$associations[(int)$assigned->getId()][] = (int)$assigned->getCardId();
			$usage[(int)$assigned->getId()] = ($usage[(int)$assigned->getId()] ?? 0) + 1;
		}
		ksort($usage);
		foreach ($associations as &$ids) { sort($ids); }
		unset($ids);
		$owner = (string)$board->getOwner();
		return ['boardId' => $boardId,
			'board' => ['owner' => $owner, 'ownerDisplayName' => $this->services->displayName($owner), 'name' => (string)$board->getTitle()],
			'labels' => $this->catalog($boardId), 'usage' => $usage, 'associations' => $associations];
	}

	/**
	 * One label of a board the caller manages, with its catalog and the number of cards carrying it.
	 *
	 * @param string $userId Authenticated caller.
	 * @param int $labelId Label to read; a missing one and one of a board the caller cannot manage look alike.
	 * @return array<string, mixed> The board state of {@see self::boardLabelState()} plus `label` and `cards`.
	 * @throws \Throwable When the label is unavailable or a native query fails.
	 */
	public function labelState(string $userId, int $labelId): array {
		$this->services->bindUser($userId);
		$boardId = $this->services->get(LabelMapper::class)->findBoardId($labelId);
		if ($boardId === null) {
			throw new NoPermissionException('Permission denied');
		}
		$state = $this->boardLabelState($userId, (int)$boardId);
		foreach ($state['labels'] as $label) {
			if ($label['id'] === $labelId) {
				return $state + ['label' => $label, 'cards' => $state['usage'][$labelId] ?? 0, 'cardIds' => $state['associations'][$labelId] ?? []];
			}
		}
		throw new NoPermissionException('Permission denied');
	}

	/**
	 * Labels of the board of a list, for a card about to be created there, and whether the caller may add more.
	 *
	 * @param string $userId Authenticated caller.
	 * @param int $stackId List requiring EDIT, as card creation does.
	 * @return array{boardId: int, labels: list<array{id: int, boardId: int, title: string, color: string}>, manage: bool}
	 * @throws \Throwable When the list is unavailable or a native query fails.
	 */
	public function stackLabels(string $userId, int $stackId): array {
		$this->services->bindUser($userId);
		$this->requireApi();
		$permissions = $this->services->get(PermissionService::class);
		$stacks = $this->services->get(StackMapper::class);
		$permissions->checkPermission($stacks, $stackId, Acl::PERMISSION_EDIT);
		$boardId = $stacks->findBoardId($stackId);
		if ($boardId === null) {
			throw new NoPermissionException('Permission denied');
		}
		return ['boardId' => (int)$boardId, 'labels' => $this->catalog((int)$boardId),
			'manage' => (bool)($permissions->getPermissions((int)$boardId, $userId)[Acl::PERMISSION_MANAGE] ?? false)];
	}

	/**
	 * @param string $userId Authenticated caller, who must manage the board.
	 * @param int $boardId Board that receives the label.
	 * @param string $title Validated title, unique on the board.
	 * @param string $color Six lowercase hexadecimal digits.
	 * @return array{id: int, boardId: int, title: string, color: string} Created label.
	 * @throws \Throwable Any Deck failure; the handler reads the catalog again.
	 */
	public function createLabel(string $userId, int $boardId, string $title, string $color): array {
		$this->services->bindUser($userId);
		return self::row($this->services->get(LabelService::class)->create($title, $color, $boardId));
	}

	/**
	 * Rechecks the label shown in the plan, then renames or recolours it.
	 *
	 * @param string $userId Authenticated caller, who must manage the board.
	 * @param int $labelId Label to change.
	 * @param string $title New title.
	 * @param string $color New colour.
	 * @param array<string, mixed> $expected {@see LabelSnapshot::of()} of the state the plan showed.
	 * @return array{id: int, boardId: int, title: string, color: string} Updated label.
	 * @throws DeckConflictException Before the native write when the label or its board changed.
	 * @throws \Throwable Any Deck failure; the handler reads the label again.
	 */
	public function updateLabel(string $userId, int $labelId, string $title, string $color, array $expected): array {
		if (LabelSnapshot::of($this->labelState($userId, $labelId), false) !== $expected) {
			throw new DeckConflictException(DeckLabelMessages::planChanged());
		}
		return self::row($this->services->get(LabelService::class)->update($labelId, $title, $color));
	}

	/**
	 * Rechecks the label and its card count shown in the plan, then deletes it from the board and every card.
	 *
	 * @param string $userId Authenticated caller, who must manage the board.
	 * @param int $labelId Label to delete.
	 * @param array<string, mixed> $expected {@see LabelSnapshot::of()} with the card count the plan showed.
	 * @return void
	 * @throws DeckConflictException Before the native write when the label, its board or its use changed.
	 * @throws \Throwable Any Deck failure; the handler reads the catalog again.
	 */
	public function deleteLabel(string $userId, int $labelId, array $expected): void {
		if (LabelSnapshot::of($this->labelState($userId, $labelId), true) !== $expected) {
			throw new DeckConflictException(DeckLabelMessages::planChanged());
		}
		$this->services->get(LabelService::class)->delete($labelId);
	}

	/**
	 * Assigns one label of its board to a card just created by the caller.
	 *
	 * @param string $userId Authenticated caller.
	 * @param int $cardId Card created in this call.
	 * @param int $labelId Label of the same board.
	 * @return void
	 * @throws \Throwable Any Deck failure; the handler reads the card labels again.
	 */
	public function assignCardLabel(string $userId, int $cardId, int $labelId): void {
		$this->services->bindUser($userId);
		(new DeckLabelGateway($this->services, new CardReferenceFilter($this->services)))->cardLabelState($userId, $cardId, [$labelId]);
		$this->services->get(CardService::class)->assignLabel($cardId, $labelId);
	}

	/** @return void Refuses missing native label services or methods before any card or label can be written. */
	private function requireApi(): void {
		foreach (['create', 'update', 'delete'] as $method) {
			if (!method_exists($this->services->get(LabelService::class), $method)) {
				throw new NoPermissionException('Permission denied');
			}
		}
		if (!method_exists($this->services->get(CardService::class), 'assignLabel')) {
			throw new NoPermissionException('Permission denied');
		}
	}

	/**
	 * @param int $boardId Board whose own labels are read, sorted by id.
	 * @return list<array{id: int, boardId: int, title: string, color: string}>
	 */
	private function catalog(int $boardId): array {
		$labels = [];
		foreach ($this->services->get(LabelMapper::class)->findAll($boardId) as $label) {
			if ((int)$label->getBoardId() === $boardId) {
				$labels[] = self::row($label);
			}
		}
		usort($labels, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
		return $labels;
	}

	/**
	 * @param object $label Native label entity.
	 * @return array{id: int, boardId: int, title: string, color: string}
	 */
	private static function row(object $label): array {
		return ['id' => (int)$label->getId(), 'boardId' => (int)$label->getBoardId(),
			'title' => (string)$label->getTitle(), 'color' => strtolower((string)$label->getColor())];
	}
}
