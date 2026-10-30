<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tables\Write;

use OCA\Mcp\Tools\PlanState;
use OCA\Mcp\Tools\Tables\Links\TablesLinks;
use OCA\Mcp\Tools\Tables\TablesMessages;
use OCA\Mcp\Tools\Tables\TablesText;

/**
 * The plan of a row write and its {@see PlanState}: the target and its link, the row of an update, each written cell
 * with its column, the value before (update) and the value after as the Tables parser will keep it, and fixed notes.
 * Only what the caller may see is in it: the accepted columns, the cells being written and the row's visible values.
 * Hidden columns, saved rules, the hidden values a view adds and relation targets only enter the plan state
 * ({@see RowWriteState}). The notes never tell whether a view has hidden rules: creating through a view always carries
 * the same note.
 */
class RowWritePlan {
	/** A creation through a view, whatever the view's rules. */
	public const NOTE_VIEW_CREATE = 'viewCreate';

	/** An update of a column the view's saved filter uses. */
	public const NOTE_LEAVES_VIEW = 'leavesView';

	/** A written column that must hold unique values. */
	public const NOTE_UNIQUE = 'unique';

	/** A table owned by somebody else. */
	public const NOTE_SHARED = 'shared';

	/**
	 * Receives the plan states and the links.
	 *
	 * @param PlanState $states the instance's plan states
	 * @param TablesLinks $links direct links into the Tables app
	 * @return void
	 */
	public function __construct(
		private PlanState $states,
		private TablesLinks $links,
	) {
	}

	/**
	 * The plan.
	 *
	 * @param string $tool the tool
	 * @param RowWritePrepared $prepared the checked write
	 * @param string $userId authenticated user UID
	 * @param bool $changed whether a confirmed call found this instead of the approved plan: a warning says so first
	 * @return array<string, mixed> the plan
	 */
	public function plan(string $tool, RowWritePrepared $prepared, string $userId, bool $changed = false): array {
		$target = $prepared->target;
		$notes = self::notes($prepared);
		$cells = [];
		foreach ($prepared->cells as $cell) {
			$column = $target->columns[$cell['columnId']];
			$line = ['columnId' => $cell['columnId'], 'column' => TablesText::clean($column['title'] ?? '')];
			if ($prepared->current !== null) {
				$line['before'] = $prepared->current['values'][$cell['columnId']] ?? null;
			}
			$cells[] = $line + ['after' => $cell['effect']];
		}
		return [
			'action' => $tool,
			'target' => $this->target($prepared),
			'cells' => $cells,
			'notes' => array_map(self::noteText(...), $notes),
			'message' => $prepared->isUpdate() ? RowWriteMessages::updatePlan() : RowWriteMessages::createPlan(),
			PlanState::ARGUMENT => $this->state($tool, $prepared, $userId),
			'notice' => TablesMessages::UNTRUSTED_NOTICE,
		] + (in_array(self::NOTE_SHARED, $notes, true) ? ['shared' => true] : [])
			+ ($changed ? ['warnings' => [['message' => RowWriteMessages::planChanged()]]] : []);
	}

	/**
	 * The plan state of a checked write.
	 *
	 * @param string $tool the tool
	 * @param RowWritePrepared $prepared the checked write
	 * @param string $userId authenticated user UID
	 * @return string the state
	 */
	public function state(string $tool, RowWritePrepared $prepared, string $userId): string {
		return $this->states->of($tool, $userId, RowWriteState::shown($prepared, self::notes($prepared)));
	}

	/**
	 * The target as a plan or a receipt shows it.
	 *
	 * @param RowWritePrepared $prepared the checked write
	 * @return array<string, mixed> its id, link and the row of an update
	 */
	public function target(RowWritePrepared $prepared): array {
		$target = $prepared->target;
		$shown = $target->type === 'view'
			? ['viewId' => $target->id, 'url' => $this->links->view($target->id)]
			: ['tableId' => $target->id, 'url' => $this->links->table($target->id)];
		return $shown + ($prepared->request->rowId !== null ? ['rowId' => $prepared->request->rowId] : []);
	}

	/**
	 * The keys of the notes of a write.
	 *
	 * @param RowWritePrepared $prepared the checked write
	 * @return list<string> the keys, in a fixed order
	 */
	public static function notes(RowWritePrepared $prepared): array {
		$target = $prepared->target;
		$written = array_column($prepared->cells, 'columnId');
		$notes = [];
		if (!$prepared->isUpdate() && $target->view !== null) {
			$notes[] = self::NOTE_VIEW_CREATE;
		}
		$filtered = [];
		foreach ($target->view === null ? [] : (array)$target->view->getFilterArray() as $group) {
			foreach (is_array($group) ? $group : [] as $rule) {
				if (is_array($rule) && isset($rule['columnId'])) {
					$filtered[] = (int)$rule['columnId'];
				}
			}
		}
		if ($prepared->isUpdate() && array_intersect($written, $filtered) !== []) {
			$notes[] = self::NOTE_LEAVES_VIEW;
		}
		foreach ($written as $columnId) {
			if ((bool)($target->columns[$columnId]['textUnique'] ?? false)) {
				$notes[] = self::NOTE_UNIQUE;
				break;
			}
		}
		if (!$target->ownedByCaller) {
			$notes[] = self::NOTE_SHARED;
		}
		return $notes;
	}

	/**
	 * The text of a note.
	 *
	 * @param string $note the key
	 * @return string the note in the language of the current user
	 */
	public static function noteText(string $note): string {
		return match ($note) {
			self::NOTE_VIEW_CREATE => RowWriteMessages::viewCreateNote(),
			self::NOTE_LEAVES_VIEW => RowWriteMessages::leavesViewNote(),
			self::NOTE_UNIQUE => RowWriteMessages::uniqueNote(),
			default => RowWriteMessages::shared(),
		};
	}
}
