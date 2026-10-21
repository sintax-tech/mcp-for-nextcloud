<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Tools;

use OCA\Mcp\Mcp\Tools\DavCollectionSchema;
final class CreateEvent implements \OCA\Mcp\Mcp\Tools\WriteTool
{
    public function __construct(private \OCA\Mcp\Modules\Calendar\Creation\EventCreator $operation, private \OCA\Mcp\Modules\Calendar\Execution\EventApproval $approval)
    {
    }
    public static function definition(): \OCA\Mcp\Mcp\Tools\ToolDefinition
    {
        return new \OCA\Mcp\Mcp\Tools\ToolDefinition(
            name: 'calendar_create_event',
            module: 'calendar',
            operation: 'create',
            description: 'Creates a simple event (no recurrence) in a writable calendar. Attendees are Nextcloud account IDs; call users_search first if you are not sure of someone\'s ID. This call may send invitations to other people. If the resource belongs to someone else, the agent MUST ask the user before sending confirm_shared: true. First returns a detailed plan without writing. Show it to the user and wait for explicit approval; then repeat the same arguments with confirm=true, adding the etag returned in the plan when it has one.',
            inputSchema: [
                'type' => 'object',
                'properties' => (object) [
                    'calendar' => DavCollectionSchema::calendar('calendar path, as returned by calendar_list_calendars'),
                    'summary' => DavCollectionSchema::text('event title', 1, 255),
                    'start' => DavCollectionSchema::text('start: YYYY-MM-DD (all-day) or ISO with Z/offset', 10, 40),
                    'end' => DavCollectionSchema::text('exclusive end, in the same format as start', 10, 40),
                    'allDay' => [
                        'type' => 'boolean',
                        'default' => false,
                        'description' => 'all-day event',
                    ],
                    'timeZone' => DavCollectionSchema::text('IANA time zone, e.g. America/Sao_Paulo', 1, 64),
                    'location' => DavCollectionSchema::text('location', 0, 255),
                    'description' => DavCollectionSchema::text('description', 0, 65536),
                    'attendees' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'string',
                            'minLength' => 1,
                            'maxLength' => 64,
                        ],
                        'minItems' => 1,
                        'maxItems' => 50,
                        'uniqueItems' => true,
                        'description' => 'Nextcloud account IDs (not emails); if you are not sure of someone\'s ID, call users_search first. Replaces the attendee list.',
                    ],
                    'send_invitations' => [
                        'type' => 'boolean',
                        'default' => false,
                        'description' => 'true delivers the invitation to Nextcloud scheduling; email depends on server settings and does not confirm receipt',
                    ],
                    'confirm_shared' => [
                        'type' => 'boolean',
                        'description' => 'set to true only after the user confirms changing a calendar that belongs to someone else',
                    ],
                ],
                'additionalProperties' => false,
                'required' => [
                    'calendar',
                    'summary',
                    'start',
                    'end',
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
