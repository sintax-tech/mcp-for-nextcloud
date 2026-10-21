<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Tasks;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Mcp\Tools\ToolContext;
final class TasksModule implements \OCA\Mcp\Mcp\Tools\ToolModule, \OCA\Mcp\Mcp\Confirmation\PreviewsWrites, \OCA\Mcp\Mcp\Confirmation\RendersPlans, \OCA\Mcp\Mcp\Guide\ToolGuideNotes
{
    /** @param array<string, \Closure(): Tool> $factories */
    public function __construct(private array $factories, private \Closure $assertReferencesAvailable)
    {
        $expected = array_keys(TasksToolCatalog::definitions());
        if (array_diff($expected, array_keys($factories)) !== [] || array_diff(array_keys($factories), $expected) !== []) {
            throw new \LogicException('Factories must match the catalog exactly');
        }
        foreach ($factories as $factory) {
            if (!$factory instanceof \Closure) {
                throw new \LogicException('Factories must be closures');
            }
        }
    }
    public function definitions(): array
    {
        return array_values(TasksToolCatalog::definitions());
    }
    private function resolve(string $name): Tool
    {
        $tool = $this->factories[$name]();
        if (!$tool instanceof Tool || $tool::definition()->name !== $name) {
            throw new \LogicException('Factory returned a different tool');
        }
        if (TasksToolCatalog::definitions()[$name]['operation'] !== 'read' && !$tool instanceof WriteTool) {
            throw new \LogicException('Writes must implement WriteTool');
        }
        return $tool;
    }
    private function known(string $name): void
    {
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException('Unknown tool');
        }
    }
    public function preview(
        string $name,
        array $arguments,
        string $userId,
    ): array
    {
        ($this->assertReferencesAvailable)($userId);
        $this->known($name);
        if (TasksToolCatalog::definitions()[$name]['operation'] === 'read') {
            throw new \InvalidArgumentException('Unknown tool');
        }
        return $this->resolve($name)->preview($arguments, new ToolContext($userId));
    }
    public function call(
        string $name,
        array $arguments,
        string $userId,
    ): array
    {
        ($this->assertReferencesAvailable)($userId);
        $this->known($name);
        return $this->resolve($name)->execute($arguments, new ToolContext($userId));
    }
    public function guideNotes(): array
    {
        return [
            'Task calendars support VTODO. These tools use core CalDAV and work even without the ' . 'Tasks app; list task calendars before addressing a task by UID.',
            'Undated tasks are included. Completed tasks are hidden by default in listings. ' . 'Recurring tasks can be read; changing recurring tasks or tasks with participants is ' . 'refused to protect their series and scheduling.',
            'Task categories are free text, as in the Tasks app. On create and edit, a categories list ' . 'replaces all existing categories, [] clears them and omitting the field preserves them. ' . 'tasks_list_tasks can filter by one category, ignoring case.',
            'Private and confidential tasks in shared calendars are hidden. Writable shares ' . 'require confirm_shared=true after showing the owner notice.',
            'Every write starts with a before/after plan. Wait for explicit approval before ' . 'confirm=true; the optional etag checks for concurrent edits. Writing grants start ' . 'disabled.',
            'Deletion uses the native calendar trash and is refused when calendar retention is ' . 'zero. The result confirms recovery through the calendar trash.',
        ];
    }
    public function renderPlan(string $name, array $plan): ?string
    {
        return (new Presentation\TasksPlanRenderer())->render($name, $plan);
    }
}
