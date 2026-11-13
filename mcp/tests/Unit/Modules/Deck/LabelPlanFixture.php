<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Deck;

/** Scalar state and readable examples shared by the focused label contracts. */
final class LabelPlanFixture {
	/** @return array Board readable by its owner and carrying one label. */
	public static function state(): array {
		return ['boardId' => 20, 'board' => ['owner' => 'alice', 'ownerDisplayName' => 'Alice', 'name' => 'Board'],
			'labels' => [['id' => 1, 'boardId' => 20, 'title' => 'bug', 'color' => 'ff0000']],
			'usage' => [1 => 2], 'label' => ['id' => 1, 'boardId' => 20, 'title' => 'bug', 'color' => 'ff0000'],
			'cards' => 2, 'cardIds' => [7, 8]];
	}
	/** @return array Three readable plans, including irreversible deletion. */
	public static function plans(): array {
		$base = ['board' => ['board' => 'Board', 'shared' => false], 'label' => ['title' => 'bug', 'color' => 'ff0000'], 'cards' => 2];
		return ['deck_create_label' => $base, 'deck_update_label' => $base + ['after' => ['title' => 'defect', 'color' => '00ff00']],
			'deck_delete_label' => $base + ['consequence' => 'Permanent removal from all cards.']];
	}
	/** Static fixture. */
	private function __construct() {
	}
}
