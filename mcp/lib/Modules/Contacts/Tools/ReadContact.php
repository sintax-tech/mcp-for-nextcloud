<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Contacts\Tools;

use OCA\Mcp\Mcp\Tools\DavCollectionSchema;
final class ReadContact implements \OCA\Mcp\Mcp\Tools\Tool
{
    public function __construct(private \OCA\Mcp\Modules\Contacts\Reading\ContactReader $operation)
    {
    }
    public static function definition(): \OCA\Mcp\Mcp\Tools\ToolDefinition
    {
        return new \OCA\Mcp\Mcp\Tools\ToolDefinition(
            name: 'contacts_read_contact',
            module: 'contacts',
            operation: 'read',
            description: 'Read a contact including all vCard properties and its ETag. A contact of the accounts catalog returns only its public fields and accountId, without vCard or ETag.',
            inputSchema: [
                'type' => 'object',
                'properties' => (object) [
                    'addressbook' => DavCollectionSchema::text('Address book path returned by List address books.', 1, 1024),
                    'uri' => DavCollectionSchema::text('Contact object URI returned by Search contacts.', 1, 255),
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
        return $this->operation->execute($arguments, $context->userId);
    }
}
