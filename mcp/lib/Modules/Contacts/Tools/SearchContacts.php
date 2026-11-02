<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Tools;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Schema\DavCollectionSchema;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Contacts\Presentation\ContactsSchema;
use OCA\Mcp\Modules\Contacts\Reading\SearchContactsHandler;

/** `contacts_search_contacts`: personal and shared contacts first, then the accounts of the instance. */
final class SearchContacts implements Tool {
	/**
	 * @param SearchContactsHandler $searches the read
	 */
	public function __construct(
		private SearchContactsHandler $searches,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of contacts_search_contacts
	 */
	public static function definition(): ToolDefinition {
		return ContactsSchema::definition(
			'contacts_search_contacts',
			'read',
			'Search contacts by name, email, phone or organization in one address book or, when '
				. '"addressbook" is omitted, in all of them including the read-only accounts of the instance '
				. '(colleagues). Account results carry accountId, the user ID to use for invitations, '
				. 'attendees, Deck and Talk. An empty query lists personal contacts only; accounts need a '
				. 'search term of at least 2 characters.',
			[
				'addressbook' => DavCollectionSchema::text(
					'Address book path returned by List address books. Omit to search all of them.',
					1,
					1024
				),
				'query' => DavCollectionSchema::text('Case-insensitive search text.', 0, 1024),
				'limit' => DavCollectionSchema::limit(),
				'offset' => DavCollectionSchema::offset(),
			],
		);
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> {contacts, hasMore, nextOffset}
	 * @throws ToolFailure when the address book or the contact is not visible, or the catalog needs a search term
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->searches->handle($context->userId, $arguments);
	}
}
