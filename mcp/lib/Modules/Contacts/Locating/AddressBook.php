<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Locating;

/**
 * An address book the user can see, as the tools expose it.
 *
 * The seven public properties, in this order, are the keys of the `(array)` cast that the listing and every plan
 * publish; no other property of any visibility may be added.
 */
final class AddressBook {
	/**
	 * @param int $id backend address-book id
	 * @param string $uri URI in the user's address-book home
	 * @param string $name display name
	 * @param string $ownerId UID of the owner
	 * @param string $ownerPrincipal owner principal, e.g. "principals/users/bob"
	 * @param bool $writable whether the viewing user may change its contacts
	 * @param string $path DAV path used by the tools, "/remote.php/dav/addressbooks/users/<uid>/<uri>/"
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $uri,
		public readonly string $name,
		public readonly string $ownerId,
		public readonly string $ownerPrincipal,
		public readonly bool $writable,
		public readonly string $path,
	) {}

	/**
	 * @param string $userId viewing user
	 * @return bool whether the address book belongs to someone else
	 */
	public function ownedByOther(string $userId): bool {
		return $this->ownerId !== $userId;
	}
}
