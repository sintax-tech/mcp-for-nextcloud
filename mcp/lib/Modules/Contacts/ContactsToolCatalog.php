<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts;

use OCA\Mcp\Modules\Contacts\Tools\CreateContact;
use OCA\Mcp\Modules\Contacts\Tools\DeleteContact;
use OCA\Mcp\Modules\Contacts\Tools\EditContact;
use OCA\Mcp\Modules\Contacts\Tools\ListAddressBooks;
use OCA\Mcp\Modules\Contacts\Tools\ReadContact;
use OCA\Mcp\Modules\Contacts\Tools\SearchContacts;

/** The Contacts tools in tools/list order. A new tool goes at the end. */
final class ContactsToolCatalog {
	/** @var list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> tool classes, in tools/list order */
	public const TOOLS = [
		ListAddressBooks::class,
		SearchContacts::class,
		ReadContact::class,
		CreateContact::class,
		EditContact::class,
		DeleteContact::class,
	];

	/** Constant holder only; never instantiated. */
	private function __construct() {
	}
}
