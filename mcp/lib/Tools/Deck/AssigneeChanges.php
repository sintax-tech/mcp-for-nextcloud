<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\ArgumentValidationException;

/**
 * The `assign` / `unassign` lists of `deck_edit_card`: what JSON Schema cannot check about them, and
 * what each account in them means for the card as it is today.
 *
 * The handler and the plan both go through here, so the plan shows exactly what the write will do.
 */
final class AssigneeChanges {
	private function __construct() {
	}

	/**
	 * Reads and validates both lists, without echoing an account ID on failure.
	 *
	 * @param array<string, mixed> $arguments Arguments of the call; a missing list means no change.
	 * @return array{assign: list<string>, unassign: list<string>} Unique account IDs of each list.
	 * @throws ArgumentValidationException When a list is malformed or an account sits in both.
	 */
	public static function fromArguments(array $arguments): array {
		$assign = CardInput::assignees($arguments['assign'] ?? [], 'assign');
		$unassign = CardInput::assignees($arguments['unassign'] ?? [], 'unassign');
		if (array_intersect($assign, $unassign) !== []) {
			throw new ArgumentValidationException('Invalid argument: assign', 'assign',
				Translator::t('an account ID cannot be in both assign and unassign'));
		}

		return ['assign' => $assign, 'unassign' => $unassign];
	}

	/**
	 * Sorts the requested accounts by what the write would do with them.
	 *
	 * @param list<string> $assign Accounts to assign.
	 * @param list<string> $unassign Accounts to unassign.
	 * @param list<string> $current Accounts assigned to the card today.
	 * @return array{add: list<string>, remove: list<string>, alreadyAssigned: list<string>, notAssigned: list<string>}
	 *     `add` and `remove` are written; the other two are ignored and reported as warnings.
	 */
	public static function resolve(array $assign, array $unassign, array $current): array {
		return [
			'add' => array_values(array_diff($assign, $current)),
			'remove' => array_values(array_intersect($unassign, $current)),
			'alreadyAssigned' => array_values(array_intersect($assign, $current)),
			'notAssigned' => array_values(array_diff($unassign, $current)),
		];
	}
}
