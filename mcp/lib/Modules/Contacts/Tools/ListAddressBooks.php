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
use OCA\Mcp\Modules\Contacts\Reading\ListAddressBooksHandler;

/** `contacts_list_addressbooks`: the own and shared address books, then the accounts catalog when the admin exposes it. */
final class ListAddressBooks implements Tool {
	/**
	 * @param ListAddressBooksHandler $lists the read
	 */
	public function __construct(
		private ListAddressBooksHandler $lists,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of contacts_list_addressbooks
	 */
	public static function definition(): ToolDefinition {
		return ContactsSchema::definition(
			'contacts_list_addressbooks',
			'read',
			'List your own and shared address books plus, when the admin exposes it, the read-only '
				. 'accounts catalog of the instance.',
			[],
		);
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> list of address books, the accounts catalog last
	 * @throws ToolFailure when the address book or the contact is not visible, or the catalog needs a search term
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->lists->handle($context->userId, $arguments);
	}
}
