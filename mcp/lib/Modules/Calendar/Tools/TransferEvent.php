<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Tools;

use OCA\Mcp\Mcp\Tools\DavCollectionSchema;
final class TransferEvent implements \OCA\Mcp\Mcp\Tools\WriteTool
{
    public function __construct(private \OCA\Mcp\Modules\Calendar\Relocation\EventTransfer $operation, private \OCA\Mcp\Modules\Calendar\Execution\EventApproval $approval)
    {
    }
    public static function definition(): \OCA\Mcp\Mcp\Tools\ToolDefinition
    {
        return new \OCA\Mcp\Mcp\Tools\ToolDefinition(
            name: 'calendar_transfer_event',
            module: 'calendar',
            operation: 'transfer',
            description: 'Transfers an event to a calendar owned by another user and shared with write permission. Requires confirm: true. Events with attendees are rejected: you remain the organizer and Nextcloud does not notify anyone in this operation. Event attendees are not notified, just like when moving in the Calendar app. First returns a detailed plan without writing. Show it to the user and wait for explicit approval; then repeat the same arguments with confirm=true, adding the etag returned in the plan when it has one.',
            inputSchema: [
                'type' => 'object',
                'properties' => (object) [
                    'calendar' => DavCollectionSchema::calendar('source calendar path'),
                    'uid' => DavCollectionSchema::uid(),
                    'targetCalendar' => DavCollectionSchema::calendar('destination calendar path, owned by another user'),
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
                    'targetCalendar',
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
