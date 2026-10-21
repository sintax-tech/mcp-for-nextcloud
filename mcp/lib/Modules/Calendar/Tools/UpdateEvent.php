<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Tools;

use OCA\Mcp\Mcp\Tools\DavCollectionSchema;
final class UpdateEvent implements \OCA\Mcp\Mcp\Tools\WriteTool
{
    public function __construct(private \OCA\Mcp\Modules\Calendar\Editing\EventEditor $operation, private \OCA\Mcp\Modules\Calendar\Execution\EventApproval $approval)
    {
    }
    public static function definition(): \OCA\Mcp\Mcp\Tools\ToolDefinition
    {
        return new \OCA\Mcp\Mcp\Tools\ToolDefinition(
            name: 'calendar_update_event',
            module: 'calendar',
            operation: 'edit',
            description: 'Updates title, location, description, dates, or attendees (account IDs; call users_search first if unsure) of an event. Recurrent series dates cannot be changed. This call may send invitations to other people. Attendees are only notified with send_invitations: true, depending on server configuration; sending does not confirm receipt. If the resource belongs to someone else, the agent MUST ask the user before sending confirm_shared: true. First returns a detailed plan without writing. Show it to the user and wait for explicit approval; then repeat the same arguments with confirm=true, adding the etag returned in the plan when it has one.',
            inputSchema: [
                'type' => 'object',
                'properties' => (object) [
                    'calendar' => DavCollectionSchema::calendar('calendar path, as returned by calendar_list_calendars'),
                    'uid' => DavCollectionSchema::uid(),
                    'summary' => DavCollectionSchema::text('new title', 1, 255),
                    'start' => DavCollectionSchema::text('new start: YYYY-MM-DD (all-day) or ISO with Z/offset', 10, 40),
                    'end' => DavCollectionSchema::text('new exclusive end', 10, 40),
                    'allDay' => [
                        'type' => 'boolean',
                        'description' => 'all-day event',
                    ],
                    'timeZone' => DavCollectionSchema::text('IANA time zone, e.g. America/Sao_Paulo', 1, 64),
                    'location' => DavCollectionSchema::text('new location; empty to clear', 0, 255),
                    'description' => DavCollectionSchema::text('new description; empty to clear', 0, 65536),
                    'attendees' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'string',
                            'minLength' => 1,
                            'maxLength' => 64,
                        ],
                        'minItems' => 0,
                        'maxItems' => 50,
                        'uniqueItems' => true,
                        'description' => 'Nextcloud account IDs (not emails); if you are not sure of someone\'s ID, call users_search first. Replaces the attendee list.',
                    ],
                    'send_invitations' => [
                        'type' => 'boolean',
                        'default' => false,
                        'description' => 'true delivers the invitation to Nextcloud scheduling; email depends on server settings and does not confirm receipt',
                    ],
                    'etag' => DavCollectionSchema::etag(),
                    'confirm_shared' => [
                        'type' => 'boolean',
                        'description' => 'set to true only after the user confirms changing a calendar that belongs to someone else',
                    ],
                ],
                'additionalProperties' => false,
                'required' => [
                    'calendar',
                    'uid',
                ],
            ],
            app: 'calendar',
            destructiveHint: true
        );
    }
    public function execute(array $arguments, \OCA\Mcp\Mcp\Tools\ToolContext $context): array
    {
        return $this->approval->execute($this->operation, $arguments, $context->userId);
    }
    public function preview(array $arguments, \OCA\Mcp\Mcp\Tools\ToolContext $context): array
    {
        return $this->approval->preview(
            self::definition()->name,
            $this->operation,
            $arguments,
            $context->userId
        );
    }
}
