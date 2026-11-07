<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

/** Independent scalar states for completion, archive, same-second conflicts and native partial persistence. */
final class CardStatusFixture {
	/** @return array<string,mixed> Active shared card, with a native done destination on Deck1.19. */
	public static function state(): array {
		return ['card' => ['id' => 7, 'boardId' => 2, 'title' => 'Status <card>', 'stackId' => 3,
			'done' => null, 'archived' => false, 'deletedAt' => 0, 'lastModified' => 12],
			'board' => ['id' => 2, 'name' => 'Board', 'owner' => 'owner', 'ownerDisplayName' => 'Owner', 'archived' => false],
			'list' => ['id' => 3, 'title' => 'Todo', 'isDoneColumn' => false],
			'autoMove' => ['supported' => true, 'destination' => ['id' => 4, 'title' => 'Completed']], 'version' => '1.19.0'];
	}

	/** @param array<string,mixed> $state Scalar state. @return \OCA\Deck\Db\Card Native-shaped result for the existing formatter. */
	public static function card(array $state): \OCA\Deck\Db\Card {
		$fields = $state['card'];
		$fields['done'] = $fields['done'] === null ? null : new \DateTime('@' . $fields['done']);
		return new \OCA\Deck\Db\Card($fields + ['type' => 'note', 'owner' => 'owner', 'description' => 'Kept', 'order' => 0]);
	}
	/** @return array<string,array<string,mixed>> The two real signed readable plans. */
	public static function plans(\OCA\Mcp\Mcp\Confirmation\PlanState $signer): array {
		$plans = [];
		foreach (['done', 'archived'] as $field) {
			$tool = 'deck_set_card_' . $field;
			$plans[$tool] = \OCA\Mcp\Modules\Deck\Editing\CardStatusPlan::of($tool, 'alice', $field,
				['cardId' => 7, $field => true], self::state(), $signer);
		}
		return $plans;
	}

}
