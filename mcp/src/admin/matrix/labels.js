/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the matrix labels unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.matrix = admin.modules.matrix || {}
	/** Attach the translated module, operation and level label catalogs to the matrix context.
	 * @param {object} context matrix controls, loaded policy, write locks and rendering dependencies
	 * @return {void}
	 */
	admin.modules.matrix.labels = function (context) {
		/** Module labels.
		 * @return {Object<string, string>} module labels */
		function moduleLabels() {
			return {
				files: t('mcp', 'Files'),
				notes: t('mcp', 'Notes'),
				deck: t('mcp', 'Deck'),
				calendar: t('mcp', 'Calendar'),
				contacts: t('mcp', 'Contacts'),
				tasks: t('mcp', 'Tasks'),
				talk: t('mcp', 'Talk'),
				people: t('mcp', 'People'),
				logs: t('mcp', 'Server log'),
				mail: t('mcp', 'Mail'),
				tables: t('mcp', 'Tables'), forms: t('mcp', 'Forms'), polls: t('mcp', 'Polls'),
				collectives: t('mcp', 'Collectives'),
			}
		}

		/** Operation labels.
		 * @return {Object<string, string>} operation labels */
		function operationLabels() {
			return {
				...window.OCA.Mcp.Admin.pollsGrants?.operationLabels(),
				read: t('mcp', 'read'),
				schema: t('mcp', 'schema'), results: t('mcp', 'results'),
				'collective.create': t('mcp', 'create collectives'),
				members: t('mcp', 'manage members'),
				edit: t('mcp', 'edit'),
				create: t('mcp', 'create'),
				move: t('mcp', 'move'),
				delete: t('mcp', 'delete'),
				restore: t('mcp', 'restore'),
				transfer: t('mcp', 'transfer'),
				reply: t('mcp', 'reply'),
				attach: t('mcp', 'attach'),
				quote: t('mcp', 'quote'),
				share: t('mcp', 'share'),
				link: t('mcp', 'link'),
				messages: t('mcp', 'messages'),
				attachments: t('mcp', 'attachments'),
				flags: t('mcp', 'flags'),
				draft: t('mcp', 'draft'),
				send: t('mcp', 'send'),
			}
		}

		/**
		 * What each operation lets the AI do, shown as the column tooltip.
		 * @param {string} module module id
		 * @param {string} operation operation id
		 * @return {string} description
		 */
		function operationHint(module, operation) {
			if (module === 'talk' && operation === 'create') {
				return t('mcp', 'Open new conversations, which invites people')
			}
			if (module === 'files' && operation === 'create') {
				return t('mcp', 'Create folders, copies and new files')
			}
			if (module === 'logs' && operation === 'read') {
				return t('mcp', 'Read and analyze the server log; starts off')
			}
			if (module === 'collectives' && operation === 'collective.create') {
				return t('mcp', 'Create new collectives, each with its own new team owned by the user; starts off')
			}
			if (module === 'collectives' && operation === 'members') {
				return t('mcp', 'List the members of collectives and, as their admin, add, remove or change the level of members; starts off')
			}
			if (module === 'calendar' && operation === 'transfer') {
				return t('mcp', 'Move an event to another person’s calendar')
			}
			if (module === 'forms' && operation === 'edit') {
				return t('mcp', 'Change existing forms, including adding, reordering and deleting questions and options and changing a question type')
			}
			const mailHint = window.OCA.Mcp.Admin.pollsGrants?.operationHint(module, operation) || (window.OCA.Mcp.Admin.mailGrants ? window.OCA.Mcp.Admin.mailGrants.operationHint(module, operation) : null)
			if (mailHint) return mailHint
			const hints = {
				read: t('mcp', 'List, search and read'),
				results: t('mcp', 'Reserved for reading responses allowed by native Forms permissions; starts off'),
				edit: t('mcp', 'Change existing items'),
				create: t('mcp', 'Create new items'),
				move: t('mcp', 'Move or rename items'),
				delete: t('mcp', 'Delete items'),
				restore: t('mcp', 'Restore a file to a stored version'),
				reply: t('mcp', 'Send messages and replies'),
				attach: t('mcp', 'Share a file into a conversation'),
				quote: t('mcp', 'Quote part of a file in a message'),
				share: module === 'files' ? t('mcp', 'Share own files with people and groups') : '',
				link: t('mcp', 'Create public links to own files'),
			}
			return hints[operation] || ''
		}

		/** Names of the access levels of src/admin/admin-levels.js.
		 * @return {Object<string, string>} names of the access levels of src/admin/admin-levels.js */
		function levelLabels() {
			return {
				...window.OCA.Mcp.Admin.pollsGrants?.levelLabels(),
				none: t('mcp', 'None'),
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
				custom: t('mcp', 'Custom'),
			}
		}

		Object.assign(context, { levelLabels, moduleLabels, operationHint, operationLabels })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
