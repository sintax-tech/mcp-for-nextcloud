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
use Psr\Container\ContainerInterface;

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
		return new TasksModule(self::container([
			\OCA\Mcp\Modules\Tasks\Tools\ListTaskCalendars::class => new \OCA\Mcp\Modules\Tasks\Tools\ListTaskCalendars(new \OCA\Mcp\Modules\Tasks\Reading\ListTaskCalendarsHandler($access, $references)),
			\OCA\Mcp\Modules\Tasks\Tools\ListTasks::class => new \OCA\Mcp\Modules\Tasks\Tools\ListTasks(new \OCA\Mcp\Modules\Tasks\Reading\ListTasksHandler($access, $store, $tasks, $data, new \OCA\Mcp\Modules\Tasks\Locating\TaskRowReader($data), $references)),
			\OCA\Mcp\Modules\Tasks\Tools\ReadTask::class => new \OCA\Mcp\Modules\Tasks\Tools\ReadTask(new \OCA\Mcp\Modules\Tasks\Reading\ReadTaskHandler($access, $objects, $references)),
			\OCA\Mcp\Modules\Tasks\Tools\CreateTask::class => new \OCA\Mcp\Modules\Tasks\Tools\CreateTask(new \OCA\Mcp\Modules\Tasks\Creation\CreateTaskHandler($parent, $references), new \OCA\Mcp\Modules\Tasks\Creation\CreateTaskPreview($parent, $references)),
			\OCA\Mcp\Modules\Tasks\Tools\EditTask::class => new \OCA\Mcp\Modules\Tasks\Tools\EditTask(new \OCA\Mcp\Modules\Tasks\Editing\EditTaskHandler($parent, $references), new \OCA\Mcp\Modules\Tasks\Editing\EditTaskPreview($parent, $references)),
			\OCA\Mcp\Modules\Tasks\Tools\CompleteTask::class => new \OCA\Mcp\Modules\Tasks\Tools\CompleteTask(new \OCA\Mcp\Modules\Tasks\Completion\CompleteTaskHandler($flow, $references), new \OCA\Mcp\Modules\Tasks\Completion\CompleteTaskPreview($flow, $references)),
			\OCA\Mcp\Modules\Tasks\Tools\DeleteTask::class => new \OCA\Mcp\Modules\Tasks\Tools\DeleteTask(new \OCA\Mcp\Modules\Tasks\Deletion\DeleteTaskHandler($flow, $references), new \OCA\Mcp\Modules\Tasks\Deletion\DeleteTaskPreview($flow, $references)),
		]));
	}
	/**
	 * @param array<string, object> $services instances by id
	 * @return ContainerInterface a container that only answers those instances
	 */
	public static function container(array $services): ContainerInterface {
		return new class($services) implements ContainerInterface {
			/** @param array<string, object> $services instances by id */
			public function __construct(private array $services) {
			}

			/** {@inheritDoc} */
			public function get(string $id): mixed {
				return $this->services[$id] ?? throw new \LogicException("no test service $id");
			}

			/** {@inheritDoc} */
			public function has(string $id): bool {
				return isset($this->services[$id]);
			}
		};
	}
}
