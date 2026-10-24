<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tasks;

use OCA\Mcp\L10n\Translator;

/**
 * What the person reads about subtasks, in the language of the request. None of these messages names a task: an
 * ancestor the caller may not read can stop a write, but never shows its title, its UID or where it is.
 */
final class TaskHierarchyMessages {
	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * The task would become its own parent, directly or through its subtasks.
	 *
	 * @return string the message
	 */
	public static function loop(): string {
		return Translator::t('A task cannot be its own parent or a subtask of one of its own subtasks.');
	}

	/**
	 * The parent is missing, trashed, hidden by its class, malformed or in another calendar.
	 *
	 * @return string the message
	 */
	public static function parentNotFound(): string {
		return Translator::t('The parent task was not found in this calendar. A subtask must be in the same calendar as its parent.');
	}

	/**
	 * The write would put a task deeper than the ceiling.
	 *
	 * @param int $levels the ceiling, in levels from a top-level task
	 * @return string the message
	 */
	public static function tooDeep(int $levels): string {
		return Translator::t('Subtasks can be nested at most %s levels deep.', [$levels]);
	}

	/**
	 * The tasks above the requested parent already loop or cannot be read, so the nesting cannot be checked.
	 *
	 * @return string the message
	 */
	public static function brokenHierarchy(): string {
		return Translator::t('The tasks above the requested parent form a loop or cannot be read, so the nesting cannot be checked. Fix that hierarchy first, for example by making one of those tasks top-level.');
	}

	/**
	 * Another MCP request is changing the subtasks of the same calendar.
	 *
	 * @return string the message
	 */
	public static function busy(): string {
		return Translator::t('Another change to the subtasks of this calendar is in progress; nothing was changed. Try again.');
	}

	/**
	 * The server's locking cannot serialize the write (file locking is disabled), so a write that sets a parent is
	 * refused.
	 *
	 * @return string the message
	 */
	public static function lockingUnavailable(): string {
		return Translator::t('Setting the parent of a task needs the file locking of the server, which is disabled here; nothing was changed. An administrator can enable it. Making a task top-level still works.');
	}

	/**
	 * The write succeeded, but the parent chain read back afterwards loops through the task.
	 *
	 * @return string the message
	 */
	public static function loopAfterWrite(): string {
		return Translator::t('The task was saved, but its parent chain now loops back to it, probably because another client changed a related task at the same time. Make one of the tasks in that chain top-level (parent null) to fix it.');
	}
}
