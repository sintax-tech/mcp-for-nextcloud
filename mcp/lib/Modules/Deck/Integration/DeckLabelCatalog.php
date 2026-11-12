<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

use OCA\Mcp\Modules\Deck\Failures\DeckConflictException;

/** The label catalog of a board: read, created, changed and deleted; and labels put on a card just created. */
interface DeckLabelCatalog {
	/**
	 * Catalog of a board the caller manages, with the number of cards carrying each label.
	 * @param string $userId Authenticated caller.
	 * @param int $boardId Board requiring MANAGE, active and not archived.
	 * @return array{boardId: int, board: array{owner: string, ownerDisplayName: string, name: string}, labels: list<array{id: int, boardId: int, title: string, color: string}>, usage: array<int, int>}
	 * @throws \Throwable When the board is not active, archived or not managed by the caller.
	 */
	public function boardLabelState(string $userId, int $boardId): array;

	/**
	 * One label of a board the caller manages: the board state plus `label` and `cards`.
	 * @param string $userId Authenticated caller.
	 * @param int $labelId Label to read; a missing one and a hidden one look alike.
	 * @return array<string, mixed>
	 * @throws \Throwable When the label is unavailable.
	 */
	public function labelState(string $userId, int $labelId): array;

	/**
	 * Labels of the board of an editable list, and whether the caller may add labels to that board.
	 * @param string $userId Authenticated caller.
	 * @param int $stackId List requiring EDIT, as card creation does.
	 * @return array{boardId: int, labels: list<array{id: int, boardId: int, title: string, color: string}>, manage: bool}
	 * @throws \Throwable When the list is unavailable.
	 */
	public function stackLabels(string $userId, int $stackId): array;

	/**
	 * Creates a label on a board the caller manages.
	 * @param string $userId Authenticated caller.
	 * @param int $boardId Board that receives the label.
	 * @param string $title Validated title, unique on the board.
	 * @param string $color Six lowercase hexadecimal digits.
	 * @return array{id: int, boardId: int, title: string, color: string} Created label.
	 * @throws \Throwable Any Deck failure, possibly after the insert; the handler reads the catalog again.
	 */
	public function createLabel(string $userId, int $boardId, string $title, string $color): array;

	/**
	 * Renames or recolours a label after rechecking the state the plan showed.
	 * @param string $userId Authenticated caller.
	 * @param int $labelId Label to change.
	 * @param string $title New title.
	 * @param string $color New colour.
	 * @param array<string, mixed> $expected Snapshot of the approved plan.
	 * @return array{id: int, boardId: int, title: string, color: string} Updated label.
	 * @throws DeckConflictException Before writing when the label or its board changed.
	 * @throws \Throwable Any Deck failure; the handler reads the label again.
	 */
	public function updateLabel(string $userId, int $labelId, string $title, string $color, array $expected): array;

	/**
	 * Deletes a label from its board and every card, after rechecking the state the plan showed.
	 * @param string $userId Authenticated caller.
	 * @param int $labelId Label to delete.
	 * @param array<string, mixed> $expected Snapshot of the approved plan, card count included.
	 * @return void
	 * @throws DeckConflictException Before writing when the label, its board or its use changed.
	 * @throws \Throwable Any Deck failure; the handler reads the catalog again.
	 */
	public function deleteLabel(string $userId, int $labelId, array $expected): void;

	/**
	 * Assigns one label of its board to a card the caller has just created.
	 * @param string $userId Authenticated caller.
	 * @param int $cardId Card created in this call.
	 * @param int $labelId Label of the same board.
	 * @return void
	 * @throws \Throwable Any Deck failure; the handler reads the card labels again.
	 */
	public function assignCardLabel(string $userId, int $cardId, int $labelId): void;
}
