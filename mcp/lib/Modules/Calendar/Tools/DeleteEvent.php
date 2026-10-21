<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Tools;

use OCA\Mcp\Mcp\Tools\DavCollectionSchema;
final class DeleteEvent implements \OCA\Mcp\Mcp\Tools\WriteTool
{
    public function __construct(private \OCA\Mcp\Modules\Calendar\Deletion\EventDeleter $operation, private \OCA\Mcp\Modules\Calendar\Execution\EventApproval $approval)
    {
    }
    public static function definition(): \OCA\Mcp\Mcp\Tools\ToolDefinition
    {
        return new \OCA\Mcp\Mcp\Tools\ToolDefinition(
            name: 'calendar_delete_event',
            module: 'calendar',
            operation: 'delete',
            description: 'Deletes an event (the entire series), moving it to the calendar trash bin. Requires confirm: true. Attendees are only notified with send_invitations: true, depending on server configuration; sending does not confirm receipt. If the resource belongs to someone else, the agent MUST ask the user before sending confirm_shared: true. First returns a detailed plan without writing. Show it to the user and wait for explicit approval; then repeat the same arguments with confirm=true, adding the etag returned in the plan when it has one.',
            inputSchema: [
                'type' => 'object',
                'properties' => (object) [
                    'calendar' => DavCollectionSchema::calendar('calendar path, as returned by calendar_list_calendars'),
                    'uid' => DavCollectionSchema::uid(),
                    'etag' => DavCollectionSchema::etag(),
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
                    'uid',
                ],
            ],
            app: 'calendar'
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
