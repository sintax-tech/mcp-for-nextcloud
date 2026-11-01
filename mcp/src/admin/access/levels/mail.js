/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * What the Mail operations let the AI do, for the column tooltips of src/admin/admin-grants.js: `read` lists accounts and
 * folders, `messages` (off by default) lists, searches and reads the messages themselves, `attachments` (off by default)
 * reads the text of an attachment, and the Mail writes, off by default: `flags` marks a message read or unread and
 * flagged, `move` moves it to another folder of its account, `draft` saves a new draft, never sent, `send` sends a new message. Granting a Mail write, in the matrix, its popover, a bulk
 * change or a group policy, first shows the warning of {@see writeWarning}.
 */
(function (root) {
	'use strict'

	/** The Mail operations that change a mailbox. */
	const WRITE_OPERATIONS = ['flags', 'move', 'draft', 'send']

	/**
	 * The tooltip of a Mail operation.
	 *
	 * @param {string} module module id
	 * @param {string} operation operation id
	 * @return {string|null} the description, or null for any other module or operation
	 */
	function operationHint(module, operation) {
		if (module !== 'mail') return null
		if (operation === 'read') return t('mcp', 'List mail accounts and folders, never messages')
		if (operation === 'messages') return t('mcp', 'List, search and read messages, without marking them read; starts off')
		if (operation === 'attachments') return t('mcp', 'Read the text of attachments, never the files, without marking messages read; starts off')
		if (operation === 'flags') return t('mcp', 'Mark messages read or unread and flag them, on the mail server, after a plan the user confirms; starts off')
		if (operation === 'move') return t('mcp', 'Move messages to another folder of the same account, on the mail server, after a plan the user confirms; starts off')
		if (operation === 'draft') return t('mcp', 'Create new drafts in the Drafts folder, never sent, after a plan the user confirms; starts off')
		if (operation === 'send') return t('mcp', 'Send new messages through the mail account, which cannot be undone, after a plan the user confirms; starts off')
		return null
	}

	/**
	 * The warning to read before granting Mail writes, or null when the grants name none.
	 *
	 * @param {Array<{module: string, operation: string}>} granted operations about to be allowed
	 * @return {string|null} the warning
	 */
	function writeWarning(granted) {
		if (!granted.some((grant) => grant.module === 'mail' && WRITE_OPERATIONS.includes(grant.operation))) return null
		return t('mcp', 'This lets the AI change mailboxes on the mail server: mark messages read or unread, flag them, move them to other folders, save new drafts or send messages, which cannot be undone. A message can carry hidden instructions that ask the AI to do so; every change still shows a plan the user has to confirm. Grant it only to people who want the AI to act on their mail.')
	}

	/**
	 * Asks the administrator before granting Mail writes.
	 *
	 * @param {Array<{module: string, operation: string}>} granted operations about to be allowed
	 * @return {boolean} true when no Mail write is granted, or the administrator agreed
	 */
	function confirmWrites(granted) {
		const warning = writeWarning(granted)
		return warning === null || root.confirm(warning)
	}

	/*
	 * The Mail tiers for src/admin/admin-levels.js. Mail only reads at first: its first tier lists accounts and folders,
	 * its second opens the messages, its third adds the text of attachments, and the next ones add the Mail writes, so
	 * none of its levels is called Full. src/admin/admin-grants.js translates the names.
	 */
	const catalog = root.OCA.Mcp.Admin.levelCatalog = root.OCA.Mcp.Admin.levelCatalog || { tiers: {}, tierNames: {} }
	catalog.tiers.mail = [['read'], ['messages'], ['attachments'], ['flags'], ['move'], ['draft'], ['send']]
	catalog.tierNames.mail = ['folders', 'messages', 'attachments', 'flags', 'move', 'draft', 'send']

	root.OCA.Mcp.Admin.mailGrants = { WRITE_OPERATIONS, operationHint, writeWarning, confirmWrites }
})(typeof window !== 'undefined' ? window : globalThis)
