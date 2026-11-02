<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Platform\Dav\DavResult;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Tools\Contacts\ContactDav;
use OCA\Mcp\Tools\Contacts\ContactStore;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * Address books in memory for the effect contract of Contacts: the read port the module reads through and the
 * CardDAV write port it writes through, over the same cards, so a confirmed write is visible to the next read and to
 * the observed state. The ETag of a card is the hash of its text; a write whose If-Match is stale, or a create over
 * an existing card, is refused the way the embedded CardDAV dispatcher refuses a 412. Every write request is logged.
 */
final class MemoryContacts implements ContactStore, ContactDav {
	/** @var list<array{id:int, uri:string, displayName:string, ownerPrincipal:string, readOnly:bool}> address books */
	public array $books = [];
	/** @var array<int, array<string, array{uri:string, etag:string, data:string}>> cards by book id and URI */
	public array $cards = [];
	/** @var list<string> write requests as "<operation> <book>/<card>", in order */
	public array $log = [];

	/**
	 * @param int $id book id
	 * @param string $uri book URI
	 * @param string $name display name
	 * @param string $owner owner principal
	 * @return self this store
	 */
	public function addBook(int $id, string $uri, string $name, string $owner): self {
		$this->books[] = ['id' => $id, 'uri' => $uri, 'displayName' => $name, 'ownerPrincipal' => $owner, 'readOnly' => false];
		$this->cards[$id] ??= [];
		return $this;
	}

	/**
	 * @param int $bookId book id
	 * @param string $uri card URI
	 * @param string $data vCard text
	 * @return string the ETag of the stored card
	 */
	public function plant(int $bookId, string $uri, string $data): string {
		$this->cards[$bookId][$uri] = ['uri' => $uri, 'etag' => '"' . md5($data) . '"', 'data' => $data];
		return $this->cards[$bookId][$uri]['etag'];
	}

	/** {@inheritDoc} */
	public function books(string $principal): array {
		return $this->books;
	}

	/** {@inheritDoc} */
	public function cards(int $bookId): array {
		return array_values($this->cards[$bookId] ?? []);
	}

	/** {@inheritDoc} */
	public function card(int $bookId, string $uri): ?array {
		return $this->cards[$bookId][$uri] ?? null;
	}

	/** {@inheritDoc} */
	public function put(string $userId, string $bookUri, string $uri, string $vcard): DavResult {
		$this->log[] = 'put ' . $bookUri . '/' . $uri;
		$id = $this->bookId($bookUri);
		if (isset($this->cards[$id][$uri])) {
			throw new ToolFailure(CommonMessages::conflict(), 412);
		}
		return new DavResult(201, $this->plant($id, $uri, $vcard));
	}

	/** {@inheritDoc} */
	public function update(string $userId, string $bookUri, string $uri, string $etag, string $vcard): DavResult {
		$this->log[] = 'update ' . $bookUri . '/' . $uri;
		$id = $this->bookId($bookUri);
		if (($this->cards[$id][$uri]['etag'] ?? null) !== $etag) {
			throw new ToolFailure(CommonMessages::conflict(), 412);
		}
		return new DavResult(204, $this->plant($id, $uri, $vcard));
	}

	/** {@inheritDoc} */
	public function delete(string $userId, string $bookUri, string $uri, string $etag): DavResult {
		$this->log[] = 'delete ' . $bookUri . '/' . $uri;
		$id = $this->bookId($bookUri);
		if (($this->cards[$id][$uri]['etag'] ?? null) !== $etag) {
			throw new ToolFailure(CommonMessages::conflict(), 412);
		}
		unset($this->cards[$id][$uri]);
		return new DavResult(204);
	}

	/**
	 * @return array{cards: array<int, array<string, string>>, dav: list<string>} the text of every card by book and
	 *     URI, and the write requests
	 */
	public function state(): array {
		return ['cards' => array_map(static fn (array $rows): array => array_map(static fn (array $row): string => $row['data'], $rows),
			$this->cards), 'dav' => $this->log];
	}

	/**
	 * @param string $bookUri address-book URI
	 * @return int its id
	 * @throws ToolFailure when no such book exists, as the dispatcher answers a 404
	 */
	private function bookId(string $bookUri): int {
		foreach ($this->books as $book) {
			if ($book['uri'] === $bookUri) {
				return $book['id'];
			}
		}
		throw new ToolFailure(CommonMessages::notFound(), 404);
	}
}
