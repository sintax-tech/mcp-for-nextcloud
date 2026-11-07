<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Grants;

/** Declares the ordered grant operations without changing their policy defaults. */
final class GrantCatalog {
	/** @var array<string,list<string>> operations an administrator can grant per module */
	public const CATALOG = [
		// 'restore' rolls a file back to a stored version and 'create' adds a folder, a copy or a new file;
		// like every operation but read, both start denied. 'share' shares an own file with a person or a
		// group and 'link' creates a public link; they are separate so an admin can allow one without the
		// other, and removing a share asks for the operation of its type. Listing shares stays a 'read'.
		'files' => ['read', 'edit', 'create', 'move', 'restore', 'share', 'link', 'delete'],
		'notes' => ['read', 'create', 'edit', 'move', 'delete'],
		'deck' => ['read', 'create', 'edit', 'move', 'delete'],
		'contacts' => ['read', 'create', 'edit', 'delete'],
		'tasks' => ['read', 'create', 'edit', 'delete'],
		'calendar' => ['read', 'create', 'edit', 'move', 'delete', 'transfer'],
		// 'create' is what talk_create_group asks for: opening a conversation invites people, so it is denied by
		// default like every other operation that is not a read.
		// Account search; read only, so the model can find who to invite or message.
		'people' => ['read'],
		'talk' => ['read', 'reply', 'attach', 'quote', 'create'],
		// Reading the server log; read only, and the one read that starts denied (see DENIED_READS).
		'logs' => ['read'],
		// Nextcloud Mail: 'read' lists accounts and cached folders; 'messages' lists, searches and reads the messages
		// themselves; 'attachments' reads the text of one attachment; 'flags' marks a message read/unread and
		// flagged, 'move' moves it to another folder of its account, 'draft' saves a new draft, never sent, and 'send'
		// sends a new message, four writes. All but 'read' start denied, as every operation but 'read' does.
		'mail' => ['read', 'messages', 'attachments', 'flags', 'move', 'draft', 'send'],
		// Nextcloud Tables: 'read' lists the tables, views, columns and rows the user can open; 'create' adds one row and
		// 'edit' changes cells of one row. 'schema' manages structure and 'share' manages access; these four writes start denied.
		'tables' => ['read', 'create', 'edit', 'schema', 'share'],
		'forms' => ['read', 'results', 'create', 'edit'],
		// 'options.remove' is one literal operation, independent of 'options'; all new writes start denied.
		'polls' => ['read', 'results', 'links', 'create', 'configure', 'options', 'options.remove', 'share', 'invite'],
		// Collectives (#22): 'read' lists collectives and pages, reads and searches them; on by default like every read.
		// 'create' adds an empty page, 'edit' changes the Markdown of one and 'move' moves or renames one inside its
		// collective, through Collectives; all three start denied. Deleting pages is not offered. 'collective.create' is
		// one literal operation, not 'create' of pages: it creates a new collective with its own new team, and starts denied.
		'collectives' => ['read', 'create', 'edit', 'move', 'collective.create'],
	];

	/** Prevents instantiation of the immutable catalog. */
	private function __construct() {
	}
}
