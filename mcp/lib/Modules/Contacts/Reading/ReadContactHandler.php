<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Reading;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Contacts\Accounts\SystemContacts;
use OCA\Mcp\Modules\Contacts\Cards\ContactCard;
use OCA\Mcp\Modules\Contacts\Locating\ContactAccess;
use OCA\Mcp\Platform\FileAccess\CommonMessages;

/** `contacts_read_contact`: every vCard property and the ETag, or only the public fields of an account. */
final class ReadContactHandler {
	/**
	 * @param ContactAccess $access collection visibility resolver and validated contact reader
	 * @param ContactCard $cards vCard parser
	 * @param SystemContacts $system read-only account catalog ("Accounts") governed by the admin enumeration rules
	 */
	public function __construct(private ContactAccess $access, private ContactCard $cards, private SystemContacts $system) {}

	/**
	 * @param string $userId authenticated user UID
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @return array{content:list<array{type:string, text:string}>, isError?:bool} MCP result
	 * @throws ToolFailure when the book is foreign or hidden, or the contact is missing, unreadable or not visible
	 */
	public function handle(string $userId, array $arguments): array {
		if ($this->access->isSystemPath($userId, $arguments['addressbook'])) {
			$account = $this->system->find($userId, $arguments['uri']) ?? throw new ToolFailure(CommonMessages::notFound());
			return ToolResult::json($account);
		}
		$book = $this->access->resolve($userId, $arguments['addressbook']);
		$row = $this->access->row($book->id, $arguments['uri']);
		return ToolResult::json(
			$this->cards->item($this->cards->parse($row['data'])) + [
				'uri' => $row['uri'],
				'etag' => $row['etag'],
				'addressbook' => $book->path,
			]
		);
	}
}
