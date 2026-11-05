<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tasks;

use InvalidArgumentException;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCA\Mcp\Access\ResourceScope\ScopedCalendarLookup as ScopedCalendarAccess;
use OCA\Mcp\Platform\Dav\CalDavWrite as CalendarDav;
use OCA\Mcp\Platform\Dav\CalDavObjects as CalendarStore;
use OCA\Mcp\Modules\Tasks\Writing\TaskSharedNotice as SharedGuard;
use OCA\Mcp\Modules\Tasks\Writing\TaskTrashPolicy as TrashPolicy;
use OCA\Mcp\Access\Visibility\OutputReferenceFilter;
use OCA\Mcp\Mcp\Tools\Schema\DavCollectionSchema as Schema;
use OCA\Mcp\Mcp\Tools\PreviewsWrites;
use OCA\Mcp\Mcp\Tools\RendersPlans;
use OCA\Mcp\Modules\Tasks\Creation\CreateTaskHandler;
use OCA\Mcp\Modules\Tasks\Editing\EditTaskHandler;
use OCA\Mcp\Modules\Tasks\Reading\ListTasksHandler;
use OCA\Mcp\Modules\Tasks\Writing\ParentTaskWrite;
use OCA\Mcp\Modules\Tasks\Reading\ReadTaskHandler;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Guide\ToolGuideNotes;
use OCA\Mcp\Mcp\Tools\ToolModule;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Lock\ILockingProvider;
use RuntimeException;
use OCA\Mcp\Modules\Tasks\TasksModule;

/** Builds the module with the same collaborators its tests passed to the legacy constructor. */
final class TasksTestModule {
	public static function build(
		ScopedCalendarAccess $access,
		CalendarStore $store,
		\OCA\Mcp\Modules\Tasks\Locating\TaskStore $tasks,
		CalendarDav $dav,
		\OCA\Mcp\Modules\Tasks\Composing\TaskData $data,
		SharedGuard $guard,
		TrashPolicy $trash,
		ITimeFactory $time,
		OutputReferenceFilter $references,
		?UserTimezone $zones = null,
		?ILockingProvider $locks = null,
	): TasksModule {
		$objects = new \OCA\Mcp\Modules\Tasks\Locating\TaskObjects($store, $data, $references);
		$flow = new \OCA\Mcp\Modules\Tasks\Writing\TaskWriteFlow($access, $store, $dav, $data, $objects, $guard, $trash, $time, $zones);
		$parent = new \OCA\Mcp\Modules\Tasks\Writing\ParentTaskWrite($flow, new \OCA\Mcp\Modules\Tasks\Hierarchy\TaskParentStep(
			new \OCA\Mcp\Modules\Tasks\Hierarchy\TaskHierarchy($store, $tasks, $data),
			new \OCA\Mcp\Modules\Tasks\Hierarchy\ParentTitles($store, $data),
			new \OCA\Mcp\Modules\Tasks\Hierarchy\TaskRelations(), $data,
			$locks === null ? null : new \OCA\Mcp\Modules\Tasks\Hierarchy\TaskHierarchyLock($locks),
		));
		return new TasksModule(
			new \OCA\Mcp\Modules\Tasks\Reading\ListTaskCalendarsHandler($access),
			new \OCA\Mcp\Modules\Tasks\Reading\ListTasksHandler($access, $store, $tasks, $data, new \OCA\Mcp\Modules\Tasks\Locating\TaskRowReader($data)),
			new \OCA\Mcp\Modules\Tasks\Reading\ReadTaskHandler($access, $objects),
			new \OCA\Mcp\Modules\Tasks\Creation\CreateTaskHandler($parent),
			new \OCA\Mcp\Modules\Tasks\Editing\EditTaskHandler($parent),
			new \OCA\Mcp\Modules\Tasks\Completion\CompleteTaskHandler($flow),
			new \OCA\Mcp\Modules\Tasks\Deletion\DeleteTaskHandler($flow),
			new \OCA\Mcp\Modules\Tasks\Creation\CreateTaskPreview($parent),
			new \OCA\Mcp\Modules\Tasks\Editing\EditTaskPreview($parent),
			new \OCA\Mcp\Modules\Tasks\Completion\CompleteTaskPreview($flow),
			new \OCA\Mcp\Modules\Tasks\Deletion\DeleteTaskPreview($flow),
			$references,
		);
	}
}
