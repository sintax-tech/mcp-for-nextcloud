<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Contacts\Tools;

use OCA\Mcp\Mcp\Tools\DavCollectionSchema;
final class SearchContacts implements \OCA\Mcp\Mcp\Tools\Tool
{
    public function __construct(private \OCA\Mcp\Modules\Contacts\Search\ContactSearch $operation)
    {
    }
    public static function definition(): \OCA\Mcp\Mcp\Tools\ToolDefinition
    {
        return new \OCA\Mcp\Mcp\Tools\ToolDefinition(
            name: 'contacts_search_contacts',
            module: 'contacts',
            operation: 'read',
            description: 'Search contacts by name, email, phone or organization in one address book or, when "addressbook" is omitted, in all of them including the read-only accounts of the instance (colleagues). Account results carry accountId, the user ID to use for invitations, attendees, Deck and Talk. An empty query lists personal contacts only; accounts need a search term of at least 2 characters.',
            inputSchema: [
                'type' => 'object',
                'properties' => (object) [
                    'addressbook' => DavCollectionSchema::text('Address book path returned by List address books. Omit to search all of them.', 1, 1024),
                    'query' => DavCollectionSchema::text('Case-insensitive search text.', 0, 1024),
                    'limit' => DavCollectionSchema::limit(),
                    'offset' => DavCollectionSchema::offset(),
                ],
                'additionalProperties' => false,
            ],
            app: 'contacts'
        );
    }
    public function execute(array $arguments, \OCA\Mcp\Mcp\Tools\ToolContext $context): array
    {
        return $this->operation->execute($arguments, $context->userId);
    }
}
