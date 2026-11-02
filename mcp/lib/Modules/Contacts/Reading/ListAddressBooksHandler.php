<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Reading;

use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Contacts\Accounts\SystemContacts;
use OCA\Mcp\Modules\Contacts\Locating\ContactAccess;

/** `contacts_list_addressbooks`: the own and shared address books, then the accounts catalog when the admin exposes it. */
final class ListAddressBooksHandler {
	/**
	 * @param ContactAccess $access collection visibility resolver
	 * @param SystemContacts $system read-only account catalog ("Accounts") governed by the admin enumeration rules
	 */
	public function __construct(private ContactAccess $access, private SystemContacts $system) {}

	/**
	 * @param string $userId authenticated user UID
	 * @param array<string, mixed> $arguments validated tool arguments; the listing takes none
	 * @return array{content:list<array{type:string, text:string}>, isError?:bool} MCP result
	 */
	public function handle(string $userId, array $arguments): array {
		$books = array_map(static fn ($book) => (array) $book, $this->access->visible($userId));
		$catalog = $this->system->bookName($userId);
		if ($catalog !== null) {
			$books[] = [
				'id' => 0,
				'uri' => ContactAccess::SYSTEM_URI,
				'name' => $catalog,
				'ownerId' => 'system',
				'ownerPrincipal' => 'principals/system/system',
				'writable' => false,
				'path' => ContactAccess::systemPath($userId),
				'system' => true,
				'readOnly' => true,
			];
		}
		return ToolResult::json($books);
	}
}
