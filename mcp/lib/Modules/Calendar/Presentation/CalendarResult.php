<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Presentation;

final class CalendarResult
{
    public static function json(mixed ...$blocks): array
    {
        $content = [];
        foreach ($blocks as $block) {
            $content = array_merge($content, \OCA\Mcp\Mcp\Tools\Results\ToolResult::json($block)['content']);
        }
        return ['content' => $content];
    }
    public static function error(string $message): array
    {
        return \OCA\Mcp\Mcp\Tools\Results\ToolResult::error($message);
    }
}
