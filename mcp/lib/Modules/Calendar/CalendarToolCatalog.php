<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\WriteTool;
final class CalendarToolCatalog
{
    /** @return list<class-string<Tool>> */
    public static function classes(): array
    {
        return [
            Tools\ListCalendars::class,
            Tools\ListEvents::class,
            Tools\CreateEvent::class,
            Tools\UpdateEvent::class,
            Tools\MoveEvent::class,
            Tools\DeleteEvent::class,
            Tools\TransferEvent::class,
        ];
    }
    /** @return array<string, array<string, mixed>> */
    public static function definitions(?array $classes = null): array
    {
        $result = [];
        foreach ($classes ?? self::classes() as $class) {
            if (!is_a($class, Tool::class, true)) {
                throw new \LogicException('Catalog entries must implement Tool');
            }
            $d = $class::definition();
            if ($d->operation !== 'read' && !is_a($class, WriteTool::class, true)) {
                throw new \LogicException('Writes must implement WriteTool');
            }
            if (isset($result[$d->name])) {
                throw new \LogicException('Duplicate tool: ' . $d->name);
            }
            $raw = [
                'name' => $d->name,
                'description' => $d->description,
                'inputSchema' => $d->inputSchema,
                'module' => $d->module,
                'operation' => $d->operation,
                'app' => $d->app,
            ];
            if ($d->destructiveHint !== null) {
                $raw['destructiveHint'] = $d->destructiveHint;
            }
            $result[$d->name] = $raw;
        }
        return $result;
    }
}
