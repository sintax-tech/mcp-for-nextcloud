<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Tools;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Contacts\Presentation\ContactsSchema;
use OCA\Mcp\Modules\Contacts\Reading\ReadContactHandler;

/** `contacts_read_contact`: one contact with every vCard property, or the public fields of one account. */
final class ReadContact implements Tool {
	/**
	 * @param ReadContactHandler $reads the read
	 */
	public function __construct(
		private ReadContactHandler $reads,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of contacts_read_contact
	 */
	public static function definition(): ToolDefinition {
		return ContactsSchema::definition(
			'contacts_read_contact',
			'read',
			'Read a contact including all vCard properties and its ETag. A contact of the accounts '
				. 'catalog returns only its public fields and accountId, without vCard or ETag.',
			['addressbook' => ContactsSchema::book(), 'uri' => ContactsSchema::uri()],
			['addressbook', 'uri'],
		);
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the contact with its ETag, or the public account entry
	 * @throws ToolFailure when the address book or the contact is not visible, or the catalog needs a search term
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->reads->handle($context->userId, $arguments);
	}
}
