<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Reading;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Contacts\Accounts\SystemContacts;
use OCA\Mcp\Modules\Contacts\Cards\ContactCard;
use OCA\Mcp\Modules\Contacts\Locating\AddressBook;
use OCA\Mcp\Modules\Contacts\Locating\ContactAccess;
use OCA\Mcp\Modules\Contacts\Locating\ContactStore;
use OCA\Mcp\Platform\FileAccess\CommonMessages;

/**
 * `contacts_search_contacts`: personal and shared books and, without a book or with the catalog path, the read-only
 * accounts.
 *
 * Personal matches come first. An account whose e-mail equals one of a personal match is not repeated; the personal
 * contact gains its accountId instead. Offset and limit apply to the combined list.
 */
final class SearchContactsHandler {
	/**
	 * @param ContactAccess $access collection visibility resolver
	 * @param ContactStore $store read-only storage port
	 * @param ContactCard $cards vCard parser
	 * @param SystemContacts $system read-only account catalog ("Accounts") governed by the admin enumeration rules
	 */
	public function __construct(
		private ContactAccess $access,
		private ContactStore $store,
		private ContactCard $cards,
		private SystemContacts $system,
	) {}

	/**
	 * @param string $userId authenticated user UID
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @return array{content:list<array{type:string, text:string}>, isError?:bool} MCP result
	 * @throws ToolFailure when the book is foreign, hidden or the catalog needs a search term
	 */
	public function handle(string $userId, array $arguments): array {
		$path = $arguments['addressbook'] ?? null;
		$term = trim((string) ($arguments['query'] ?? ''));
		$books = [];
		$accounts = [];
		if ($path !== null && $this->access->isSystemPath($userId, $path)) {
			if ($this->system->bookName($userId) === null) {
				throw new ToolFailure(CommonMessages::notFound());
			}
			if (mb_strlen($term) < SystemContacts::MIN_QUERY) {
				throw new ToolFailure(
					Translator::t('Enter at least 2 characters to search the accounts catalog.')
				);
			}
			$accounts = $this->system->search($userId, $term);
		} elseif ($path !== null) {
			$books = [$this->access->resolve($userId, $path)];
		} else {
			$books = $this->access->visible($userId);
			if (mb_strlen($term) >= SystemContacts::MIN_QUERY) {
				$accounts = $this->system->search($userId, $term);
			}
		}
		$accountIds = [];
		foreach ($accounts as $account) {
			foreach ($account['emails'] as $email) {
				$accountIds[mb_strtolower($email)] ??= $account['accountId'];
			}
		}
		$limit = $arguments['limit'] ?? 50;
		$offset = $arguments['offset'] ?? 0;
		$items = [];
		$more = false;
		$matched = 0;
		foreach ($this->candidates($books, mb_strtolower($term), $accounts, $accountIds) as $item) {
			if ($matched++ < $offset) {
				continue;
			}
			if (count($items) >= $limit) {
				$more = true;
				break;
			}
			$items[] = $item;
		}
		return ToolResult::json(
			[
				'contacts' => $items,
				'hasMore' => $more,
				'nextOffset' => $more ? $offset + count($items) : null,
			]
		);
	}

	/**
	 * Yields matching personal contacts, then the account contacts that no personal contact already covers.
	 *
	 * @param list<AddressBook> $books authorized personal and shared address books
	 * @param string $query lower-case search text, empty to list everything
	 * @param list<array<string, mixed>> $accounts account entries already filtered by the admin rules
	 * @param array<string, string> $accountIds accountId by lower-case e-mail, to annotate personal contacts
	 * @return \Generator<int, array<string, mixed>>
	 */
	private function candidates(array $books, string $query, array $accounts, array $accountIds): \Generator {
		$seen = [];
		foreach ($books as $book) {
			foreach ($this->store->cards($book->id) as $row) {
				try {
					$item = $this->cards->item($this->cards->parse($row['data']));
				} catch (ToolFailure) {
					continue;
				}
				$search = implode(' ', [$item['name'], $item['organization'], ...$item['emails'], ...$item['phones']]);
				if ($query !== '' && !str_contains(mb_strtolower($search), $query)) {
					continue;
				}
				unset($item['vcard'], $item['properties']);
				$item += ['uri' => $row['uri'], 'etag' => $row['etag'], 'addressbook' => $book->path];
				foreach ($item['emails'] as $email) {
					$email = mb_strtolower($email);
					$seen[$email] = true;
					if (isset($accountIds[$email])) {
						$item['accountId'] ??= $accountIds[$email];
					}
				}
				yield $item;
			}
		}
		foreach ($accounts as $account) {
			foreach ($account['emails'] as $email) {
				if (isset($seen[mb_strtolower($email)])) {
					continue 2;
				}
			}
			yield $account;
		}
	}
}
