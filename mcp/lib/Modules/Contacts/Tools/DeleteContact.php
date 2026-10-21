<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Contacts\Tools;

use OCA\Mcp\Mcp\Tools\DavCollectionSchema;
final class DeleteContact implements \OCA\Mcp\Mcp\Tools\WriteTool
{
    public function __construct(private \OCA\Mcp\Modules\Contacts\Deletion\ContactDeleter $operation)
    {
    }
    public static function definition(): \OCA\Mcp\Mcp\Tools\ToolDefinition
    {
        return new \OCA\Mcp\Mcp\Tools\ToolDefinition(
            name: 'contacts_delete_contact',
            module: 'contacts',
            operation: 'delete',
            description: 'Permanently delete a contact after saving a verified vCard backup in your Files. Nextcloud has no contact trash. Returns a detailed plan without writing. Show it to the user, wait for explicit approval, then repeat with confirm=true and the optional etag from the plan. Shared collections also require confirm_shared=true.',
            inputSchema: [
                'type' => 'object',
                'properties' => (object) [
                    'addressbook' => DavCollectionSchema::text('Address book path returned by List address books.', 1, 1024),
                    'uri' => DavCollectionSchema::text('Contact object URI returned by Search contacts.', 1, 255),
                    'etag' => DavCollectionSchema::etag(),
                    'confirm_shared' => [
                        'type' => 'boolean',
                        'description' => 'Acknowledge changing a collection owned by another person after showing the shared notice.',
                    ],
                ],
                'additionalProperties' => false,
                'required' => [
                    'addressbook',
                    'uri',
                ],
            ],
            app: 'contacts'
        );
    }
    public function execute(array $arguments, \OCA\Mcp\Mcp\Tools\ToolContext $context): array
    {
        if (!\OCA\Mcp\Mcp\Confirmation\WriteGate::confirmed($arguments)) {
            return \OCA\Mcp\Mcp\Tools\Results\ToolResult::json($this->preview($arguments, $context));
        }
        return $this->operation->execute($arguments, $context->userId);
    }
    public function preview(array $arguments, \OCA\Mcp\Mcp\Tools\ToolContext $context): array
    {
        return $this->operation->preview($arguments, $context->userId);
    }
}
