/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the inspector wording unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	admin.modules.inspector = admin.modules.inspector || {}
	/** Attach translated access decisions, blocking gates and inheritance explanations.
	 * @param {object} context inspector controls, live request sequences and attached presentation operations
	 * @return {void}
	 */
	admin.modules.inspector.wording = function (context) {
		/** Resolve the translated module label, falling back to its catalog ID.
		 * @param {string} module catalog module ID
		 * @return {string}
		 */
		function moduleName(module) {
			return { files: t('mcp', 'Files'), notes: t('mcp', 'Notes'), deck: t('mcp', 'Deck'), calendar: t('mcp', 'Calendar'), contacts: t('mcp', 'Contacts'), tasks: t('mcp', 'Tasks'), talk: t('mcp', 'Talk'), people: t('mcp', 'People'), logs: t('mcp', 'Server log'), mail: t('mcp', 'Mail'), tables: t('mcp', 'Tables'), forms: t('mcp', 'Forms'), polls: t('mcp', 'Polls') }[module] || module
		}

		/** Resolve the translated operation label, falling back to its catalog ID.
		 * @param {string} operation catalog operation ID
		 * @return {string}
		 */
		function operationName(operation) {
			return { ...window.OCA.Mcp.Admin.pollsGrants?.operationLabels(), results: t('mcp', 'results'), read: t('mcp', 'read'), edit: t('mcp', 'edit'), create: t('mcp', 'create'), move: t('mcp', 'move'), delete: t('mcp', 'delete'), transfer: t('mcp', 'transfer'), reply: t('mcp', 'reply'), attach: t('mcp', 'attach'), quote: t('mcp', 'quote'), share: t('mcp', 'share'), link: t('mcp', 'link'), restore: t('mcp', 'restore'), messages: t('mcp', 'messages'), attachments: t('mcp', 'attachments'), flags: t('mcp', 'flags'), draft: t('mcp', 'draft'), send: t('mcp', 'send') }[operation] || operation
		}

		/** The first check that blocks, by the code of the effective access (EffectiveResult::blockingGate).
		 * @param {string} gate effective access blocking gate code
		 * @return {string}
		 */
		function gateText(gate) {
			return {
				account: t('mcp', 'The account does not exist or is disabled.'),
				service: t('mcp', 'The MCP service is switched off.'),
				eligibility: t('mcp', 'The user may not connect to MCP.'),
				eligibility_unknown: t('mcp', 'Unknown: whether the user may connect depends on a group whose policy cannot be confirmed now.'),
				connection: t('mcp', 'The user has not switched on their MCP connection.'),
				app: t('mcp', 'The app of this module is not available to the user.'),
				logs_available: t('mcp', 'The server log cannot be read on this instance.'),
				logs_role: t('mcp', 'The user does not have the role needed to read the server log.'),
				grant: t('mcp', 'Not granted.'),
				grant_unknown: t('mcp', 'Unknown: the grant depends on a group whose policy cannot be confirmed now.'),
				resource_scope: t('mcp', 'Outside the resource scope of this user.'),
				resource_scope_unknown: t('mcp', 'Unknown: the resource scope of this user cannot be read.'),
			}[gate] || gate
		}

		/**
		 * Where one operation's value comes from, in words. An individual value that only repeats what the person inherits
		 * says what it repeats, as the matrix does.
		 * @param {object} row grant row returned by the API
		 * @return {string}
		 */
		function grantText(row) {
			const kind = context.kinds?.get(row.module + '/' + row.operation)
			if (kind?.kind === 'redundant' && kind.after) {
				return context.sourceText(kind.after) + ' — ' + t('mcp', 'also set for this person, with the same value')
			}
			return context.sourceText(row.grant)
		}

		/** Describe a grant's source and contributing group IDs.
		 * @param {object} grant grant source, allowed value and contributing groups
		 * @return {string}
		 */
		function sourceText(grant) {
			const groups = (grant.groupIds || []).join(', ')
			return {
				individual_allow: t('mcp', 'Individual: allowed'),
				individual_deny: t('mcp', 'Individual: denied'),
				group: t('mcp', 'Group: {groups}', { groups }),
				default: t('mcp', 'Default'),
				invalid: t('mcp', 'Invalid stored value (denied)'),
				unknown: t('mcp', 'Group awaiting confirmation: {groups}', { groups }),
			}[grant.source] || grant.source
		}

		/** Translate an allowed, blocked or unknown effective access decision.
		 * @param {boolean|null} allowed effective access decision, null when it cannot be confirmed
		 * @return {string}
		 */
		function resultText(allowed) {
			return allowed === true ? t('mcp', 'Allowed') : allowed === false ? t('mcp', 'Blocked') : t('mcp', 'Unknown')
		}

		/** What a path or calendar answer means, from its codes (AccessInspector, CalendarAccessExplainer).
		 * @param {object} answer resource explanation returned by the inspector
		 * @return {string}
		 */
		function explanation(answer) {
			const checks = { policy: t('mcp', 'the MCP policy'), native_acl: t('mcp', 'the Nextcloud permissions'), exclusion: t('mcp', 'the exclusion tags'), resource_scope_unknown: t('mcp', 'the resource scope'), grant_unknown: t('mcp', 'a group grant'), eligibility_unknown: t('mcp', 'a group eligibility') }
			const exclusions = {
				tag_direct: t('mcp', 'Not reached: it carries an exclusion tag.'),
				tag_folder: t('mcp', 'Not reached: it is inside the excluded folder {folder}.', { folder: answer.folder || '' }),
				tag_share_origin: t('mcp', 'Not reached: the shared item is excluded in the files of its owner.'),
				backup_of_excluded: t('mcp', 'Not reached: it is an MCP backup of an excluded file.'),
				invalid_configuration: t('mcp', 'Not reached: the saved exclusion tags cannot be read, so every file is refused.'),
			}
			switch (answer.reason) {
			case 'allowed': return t('mcp', 'Reached: every check passed.')
			case 'blocked': return t('mcp', 'Not reached: {reason}', { reason: context.gateText(answer.gate) })
			case 'out_of_scope': return t('mcp', 'Not reached: outside the resource scope of this user.')
			case 'not_found': return t('mcp', 'Not reached: the path does not exist in the files of this user.')
			case 'no_native_access': return t('mcp', 'Not reached: the user has no access to it in Nextcloud.')
			case 'read_only': return t('mcp', 'Not reached for this operation: the user can only read it in Nextcloud.')
			case 'excluded': return exclusions[answer.exclusion] || t('mcp', 'Not reached: it is excluded from MCP access.')
			case 'not_evaluated': return t('mcp', 'Not confirmed: {check} could not be checked, so access is not known.', { check: checks[answer.gate] || answer.gate })
			default: return answer.reason
			}
		}

		Object.assign(context, { moduleName, operationName, gateText, grantText, sourceText, resultText, explanation })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
