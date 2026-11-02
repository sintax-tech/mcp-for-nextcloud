<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Presentation;

use OCA\Mcp\Modules\Contacts\Backup\ContactBackupText;

/** What the Contacts schemas cannot say, for mcp_guide: the address books and accounts, the plan first, and the permanent deletion. */
final class ContactsGuideNotes {
	/** Static helper only; never instantiated. */
	private function __construct() {
	}

	/**
	 * Describes the confirmation workflow, data protection and recovery limitations.
	 *
	 * @return list<string> behaviour notes of the Contacts tools, in English
	 */
	public static function notes(): array {
		return [
			'List address books first; your own and shared address books are exposed, plus the '
				. 'read-only accounts catalog when the admin allows it. Searching without an address '
				. 'book also covers the accounts of the instance (colleagues, with a term of at least 2 '
				. 'characters, following the admin user-enumeration settings); each account result has '
				. 'accountId, the ID to use for invitations. Use users_search to look for accounts only. '
				. 'Account contacts can never be created, edited or deleted.',
			'Search an address book to obtain contact object URIs, then read a contact before '
				. 'editing it. Unknown vCard properties are preserved; the preview includes every '
				. 'property before and after.',
			'Writes first show a plan without changing anything. Wait for an explicit yes, repeat '
				. 'with confirm=true, and acknowledge another owner with confirm_shared=true. '
				. 'Creating a contact repeats the plan_state of that plan: it carries the UID that is stored. The '
				. 'optional etag prevents overwriting a newer edit.',
			'Contact deletion is PERMANENT in Nextcloud: there is no contact trash. After '
				. ContactBackupText::destination()
				. 'native CardDAV DELETE. Backup failure prevents deletion; the result gives the backup '
				. 'path. Import the .vcf through Contacts to recover it.',
			'These tools are hidden when the Contacts app is disabled. Writing grants start disabled.',
		];
	}
}
