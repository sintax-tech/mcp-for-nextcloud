/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Shared names for selectable and derived access levels, translated when requested. */
(function (admin) {
	'use strict'

	/** @return {Object<string, string>} translated names used by every access level presentation */
	admin.levelLabels = function () {
		return {
			...window.OCA.Mcp.Admin.pollsGrants?.levelLabels(),
			prepare: t('mcp', 'Prepare meetings'), none: t('mcp', 'None'),
			read: t('mcp', 'Read'),
			edit: t('mcp', 'Edit'),
			full: t('mcp', 'Full'),
			forms_write: t('mcp', 'Read and write forms'),
			forms_write_results: t('mcp', 'Read and write forms and read responses'),
			folders: t('mcp', 'Accounts and folders'),
			messages: t('mcp', 'Messages'),
			attachments: t('mcp', 'Messages and attachments'),
			flags: t('mcp', 'Messages, attachments and flags'),
			move: t('mcp', 'Messages, attachments, flags and moving'),
			draft: t('mcp', 'Messages, attachments, flags, moving and drafts'),
			send: t('mcp', 'Messages, attachments, flags, moving, drafts and sending'),
			save: t('mcp', 'Messages, attachments, mailbox changes and saving attachments to Files'),
			custom: t('mcp', 'Custom'),
			unknown: t('mcp', 'Unknown'),
		}
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
