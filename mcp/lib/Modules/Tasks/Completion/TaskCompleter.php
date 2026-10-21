<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Tasks\Completion;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess as CalendarAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\Mcp\Modules\Tasks\Content\TaskPolicy;
use OCA\Mcp\Modules\Tasks\Content\TaskPatch;
use OCA\Mcp\Modules\Tasks\Reading\TaskRepository;
use OCA\Mcp\Modules\Tasks\Execution\TaskPlan;
use OCA\Mcp\Modules\Tasks\Execution\TaskWriter;
final class TaskCompleter
{
    public function __construct(
        private CalendarAccess $access,
        private TaskPlan $plans,
        private ITimeFactory $time,
        private TaskRepository $repository,
        private \OCA\Mcp\Modules\Tasks\Presentation\TaskOutput $output,
        private TaskPolicy $policy,
        private TaskPatch $patch,
        private TaskWriter $writer,
    )
    {
    }
    private function prepare(array $arguments, string $userId): array
    {
        $calendar = $this->access->resolveWritable($userId, $arguments['calendar'], 'VTODO');
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
        $after = $this->patch->patch(
            $old,
            $arguments,
            $now,
            true
        );
        $plan = $this->plans->build(
            'tasks_complete_task',
            $calendar,
            $row,
            $before,
            $after,
            $userId,
            false,
            false
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
        return $this->writer->save($prepared, $userId);
    }
}
