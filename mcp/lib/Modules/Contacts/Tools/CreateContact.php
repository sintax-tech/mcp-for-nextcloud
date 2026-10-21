<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Contacts\Tools;

use OCA\Mcp\Mcp\Tools\DavCollectionSchema;
final class CreateContact implements \OCA\Mcp\Mcp\Tools\WriteTool
{
    public function __construct(private \OCA\Mcp\Modules\Contacts\Creation\ContactCreator $operation)
    {
    }
    public static function definition(): \OCA\Mcp\Mcp\Tools\ToolDefinition
    {
        return new \OCA\Mcp\Mcp\Tools\ToolDefinition(
            name: 'contacts_create_contact',
            module: 'contacts',
            operation: 'create',
            description: 'Create a contact in a writable personal or shared address book. Returns a detailed plan without writing. Show it to the user, wait for explicit approval, then repeat with confirm=true and the optional etag from the plan. Shared collections also require confirm_shared=true.',
            inputSchema: [
                'type' => 'object',
                'properties' => (object) [
                    'addressbook' => DavCollectionSchema::text('Address book path returned by List address books.', 1, 1024),
                    'name' => DavCollectionSchema::text('Contact name. Omit to preserve its existing value.', 1, 1024),
                    'organization' => DavCollectionSchema::text('Organization company name (first ORG component); existing departments and units are preserved.', 0, 1024),
                    'title' => DavCollectionSchema::text('Contact title. Omit to preserve its existing value.', 0, 1024),
                    'note' => DavCollectionSchema::text('Contact note. Omit to preserve its existing value.', 0, 65536),
                    'url' => DavCollectionSchema::text('Contact url. Omit to preserve its existing value.', 0, 1024),
                    'emails' => [
                        'type' => 'array',
                        'maxItems' => 50,
                        'items' => [
                            'type' => 'string',
                            'minLength' => 1,
                            'maxLength' => 1024,
                            'description' => 'Value.',
                        ],
                        'description' => 'Replace the emails in order; existing parameters are preserved. Omit to keep all values, [] to clear them.',
                    ],
                    'phones' => [
                        'type' => 'array',
                        'maxItems' => 50,
                        'items' => [
                            'type' => 'string',
                            'minLength' => 1,
                            'maxLength' => 1024,
                            'description' => 'Value.',
                        ],
                        'description' => 'Replace the phones in order; existing parameters are preserved. Omit to keep all values, [] to clear them.',
                    ],
                    'confirm_shared' => [
                        'type' => 'boolean',
                        'description' => 'Acknowledge changing a collection owned by another person after showing the shared notice.',
                    ],
                ],
                'additionalProperties' => false,
                'required' => [
                    'addressbook',
                    'name',
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
