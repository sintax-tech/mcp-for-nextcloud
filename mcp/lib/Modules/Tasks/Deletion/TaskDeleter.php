<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Tasks\Deletion;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Platform\Dav\Collections\Collection as Calendar;
use OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess as CalendarAccess;
use OCA\Mcp\Platform\Dav\CalDav\CalDavWriter as CalendarDav;
use OCA\Mcp\Platform\Dav\CalDav\ObjectStore as CalendarStore;
use OCA\Mcp\Platform\Dav\CalDav\TrashedObject;
use OCA\Mcp\Platform\Dav\CalDav\TrashPolicy;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\AppFramework\Utility\ITimeFactory;
use RuntimeException;
use OCA\Mcp\Modules\Tasks\Content\TaskPolicy;
use OCA\Mcp\Modules\Tasks\Reading\TaskRepository;
use OCA\Mcp\Modules\Tasks\Execution\TaskPlan;
final class TaskDeleter
{
    public function __construct(
        private CalendarAccess $access,
        private TaskPlan $plans,
        private ITimeFactory $time,
        private TaskRepository $repository,
        private \OCA\Mcp\Modules\Tasks\Presentation\TaskOutput $output,
        private TaskPolicy $policy,
        private TrashPolicy $trash,
        private CalendarDav $dav,
        private CalendarStore $store,
    )
    {
    }
    private function prepare(array $arguments, string $userId): array
    {
        $calendar = $this->access->resolveWritable($userId, $arguments['calendar'], 'VTODO');
        if (!$this->trash->recoverable()) {
            throw new ToolFailure(Translator::t('Calendar trash is disabled; the task cannot be deleted safely.'));
        }
        $row = null;
        $before = null;
        $now = $this->time->now();
        $row = $this->repository->row($calendar->id, $arguments['uid']);
        $old = $this->repository->readable($row, $calendar->ownedByOther($userId));
        $before = $this->output->item($userId, $old);
        $this->policy->assertSimple($old);
        if (isset($arguments['etag']) && trim($arguments['etag'], '"') !== trim($row['etag'], '"')) {
            throw new ToolFailure(FileAccessMessages::conflict());
        }
        $after = null;
        $plan = $this->plans->build(
            'tasks_delete_task',
            $calendar,
            $row,
            $before,
            $after,
            $userId,
            false,
            true
        );
        return compact(
            'calendar',
            'row',
            'after',
            'plan'
        );
    }
    public function preview(array $arguments, string $userId): array
    {
        return $this->prepare($arguments, $userId)['plan'];
    }
    public function execute(array $arguments, string $userId): array
    {
        $prepared = $this->prepare($arguments, $userId);
        $this->plans->assertAcknowledged($prepared['plan'], $arguments);
        $calendar = $prepared['calendar'];
        $row = $prepared['row'];
        // Check again immediately before dispatch; no permanent deletion is allowed for tasks.
        if (!$this->trash->recoverable()) {
            throw new ToolFailure(Translator::t('Calendar trash is disabled; the task cannot be deleted safely.'));
        }
        $this->dav->delete(
            $userId,
            $calendar->uri,
            $row['uri'],
            $row['etag'],
            false
        );
        if (!TrashedObject::exists($this->store, $calendar->id, $row['uri'])) {
            throw new RuntimeException('Task trash verification failed');
        }
        return ToolResult::json(
            [
                'uid' => $arguments['uid'],
                'calendar' => $calendar->path,
                'deleted' => true,
                'recoverable' => true,
            ]
        );
    }
}
