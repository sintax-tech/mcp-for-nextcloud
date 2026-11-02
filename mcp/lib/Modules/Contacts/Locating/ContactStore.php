<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Locating;

use Psr\Container\ContainerExceptionInterface;

/** Read-only CardDAV backend port. All writes must use ContactDav. */
interface ContactStore {
	/**
	 * Lists the address books visible to the authenticated user principal.
	 *
	 * @param string $principal authenticated user principal URI
	 * @return list<array{id:int, uri:string, displayName:string, ownerPrincipal:string, readOnly:bool}> the address books of the principal
	 * @throws ContainerExceptionInterface when the core backend cannot be resolved
	 */
	public function books(string $principal): array;

	/**
	 * Lists the contacts stored in one authorized address book.
	 *
	 * @param int $bookId ID of an authorized address book
	 * @return list<array{uri:string, etag:string, data:string}> every contact of the address book
	 * @throws ContainerExceptionInterface when the core backend cannot be resolved
	 */
	public function cards(int $bookId): array;

	/**
	 * Reads one contact object, returning null when it no longer exists.
	 *
	 * @param int $bookId ID of an authorized address book
	 * @param string $uri contact object URI within the address book
	 * @return array{uri:string, etag:string, data:string}|null the contact, or null when it does not exist
	 * @throws ContainerExceptionInterface when the core backend cannot be resolved
	 */
	public function card(int $bookId, string $uri): ?array;
}
