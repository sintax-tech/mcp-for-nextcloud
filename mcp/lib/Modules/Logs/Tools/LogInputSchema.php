<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Logs\Tools;

use OCA\Mcp\Modules\Logs\Reading\{LogFilter, LogScanner, LogQuery};

/** Shared filter metadata; no execution dependencies are resolved here. */
final class LogInputSchema {
    public static function forList(): array { return self::schema(true); }
    public static function forAnalysis(): array { return self::schema(false); }

    /**
     * @param bool $paged whether the tool takes a page size (logs_list)
     * @return array<string, mixed> input schema of the filters, the offset and, for logs_list, the limit
     */
    private static function schema(bool $paged): array {
        $properties = [
            'min_level' => ['type' => 'integer', 'minimum' => 0, 'maximum' => LogFilter::MAX_LEVEL,
                'description' => 'Lowest level to include: 0 debug, 1 info, 2 warning, 3 error, 4 fatal.'],
            'app' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100,
                'description' => 'Exact app id that wrote the entry, for example webdav, core or workflow_ocr.'],
            'user' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64,
                'description' => 'Exact account ID of the entry; "--" matches the entries written without a user.'],
            'since' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 40,
                'description' => 'Oldest time to include, ISO 8601 (for example 2026-10-07T18:00:00-03:00; without an offset, UTC).'],
            'until' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 40,
                'description' => 'Newest time to include, ISO 8601.'],
            'contains' => ['type' => 'string', 'minLength' => 1, 'maxLength' => LogFilter::MAX_CONTAINS,
                'description' => 'Text the message must contain, case-insensitive; plain text, not a pattern.'],
            'req_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64,
                'description' => 'Request id (reqId), to read every entry of one request.'],
        ];
        if ($paged) {
            $properties['limit'] = ['type' => 'integer', 'minimum' => 1, 'maximum' => LogQuery::MAX_LIMIT, 'default' => LogQuery::DEFAULT_LIMIT,
                'description' => 'Entries returned at most (default 50, at most 100).'];
        }
        $properties['offset'] = ['type' => 'integer', 'minimum' => 0, 'maximum' => LogScanner::MAX_OFFSET, 'default' => 0,
            'description' => 'Entries to skip from the end of the log: pass scan.nextOffset of the previous answer to go further back. '
                . 'Nothing deeper than the last ' . LogScanner::READ_BUDGET . ' entries is read.'];
        return ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
    }
}
