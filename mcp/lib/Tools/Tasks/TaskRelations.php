<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tasks;

use InvalidArgumentException;
use Sabre\VObject\Component\VTodo;
use Sabre\VObject\Property;

/**
 * The parent link of a task, as the Tasks app writes it: `RELATED-TO;RELTYPE=PARENT:<UID of the parent>` on the
 * subtask. A RELATED-TO without RELTYPE is a parent link too (PARENT is the default of RFC 5545 §3.2.15); CHILD,
 * SIBLING and any other type are other relations, read as no parent and always kept when the parent changes.
 *
 * Contributed by Marc-Olivier Turgeon-Ferland (@mturgeonferland) in PR #18; moved into this class unchanged in
 * behaviour.
 */
final class TaskRelations {
	/**
	 * The UID of the parent task: the first parent relation with a value.
	 *
	 * @param VTodo $task task master
	 * @return string|null UID of the parent task, or null for a top-level task
	 */
	public function parent(VTodo $task): ?string {
		foreach ($task->select('RELATED-TO') as $relation) {
			$uid = trim((string)$relation);
			if ($uid !== '' && $this->isParentRelation($relation)) {
				return $uid;
			}
		}
		return null;
	}

	/**
	 * Applies the `parent` argument of a write: a UID replaces only the parent relations, null removes them, and an
	 * omitted argument leaves the task as it is.
	 *
	 * @param VTodo $task task master being written, changed in place
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @return void
	 * @throws InvalidArgumentException when the task would be its own parent
	 */
	public function apply(VTodo $task, array $arguments): void {
		if (!array_key_exists('parent', $arguments)) {
			return;
		}
		$parent = $arguments['parent'];
		if ($parent !== null && $parent === (string)$task->UID) {
			throw new InvalidArgumentException(TaskHierarchyMessages::loop());
		}
		foreach ($task->select('RELATED-TO') as $relation) {
			if ($this->isParentRelation($relation)) {
				$task->remove($relation);
			}
		}
		if ($parent !== null) {
			$task->add('RELATED-TO', $parent, ['RELTYPE' => 'PARENT']);
		}
	}

	/**
	 * Whether a RELATED-TO line links the task to its parent.
	 *
	 * @param Property $relation RELATED-TO line of the task
	 * @return bool true for RELTYPE=PARENT or no RELTYPE
	 */
	private function isParentRelation(Property $relation): bool {
		return !isset($relation['RELTYPE']) || strtoupper((string)$relation['RELTYPE']) === 'PARENT';
	}
}
