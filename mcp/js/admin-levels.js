/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Access levels of the admin page: one level per module (None, Read, Edit, Full) instead of a box per operation.
 *
 * A level is only a way to show and choose a set of operations; the server never sees it. Each module's operations are
 * split into cumulative tiers (Read, then Edit adds its tier, then Full adds the last one), so a level is the union of
 * the tiers up to it. A set of allowed operations that is no such union is "custom" and is shown and edited operation
 * by operation. The tiers follow GrantPolicy::CATALOG; an operation the tiers do not name (a newer catalog) falls into
 * the last tier, so it is never hidden and never granted by a lower level.
 *
 * The level functions are pure, with no DOM and no strings: js/admin-grants.js draws the cells and names the levels.
 * The Polls presets and the Mail write warnings are defined below, in this file, so the page does not
 * load them as their own scripts. The other admin scripts still read window.McpAdminPollsGrants and
 * window.McpAdminMailGrants. Both are installed before the level functions run.
 */
/** Polls-specific protected-read names; rendering never writes or expands a stored policy. */
(function (root) {
	'use strict'

	/** @return {Object<string,string>} explicit cumulative names, never Edit or Full */
	function levelLabels() {
		return {
			polls_read: t('mcp', 'Read polls'),
			polls_read_create: t('mcp', 'Read polls and create private polls'),
			polls_read_results: t('mcp', 'Read polls, votes and results'),
			polls_read_results_links: t('mcp', 'Read polls, votes, results and existing links (credentials)'),
		}
	}

	/** @return {Object<string,string>} common results key matches Forms; links is distinct from Files.link */
	function operationLabels() {
		return { results: t('mcp', 'results'), links: t('mcp', 'Read existing links (credentials)') }
	}

	/**
	 * @param {string} module catalog module
	 * @param {string} operation exact grant
	 * @return {string|null} operation-specific warning, without changing Forms or Files hints
	 */
	function operationHint(module, operation) {
		if (module !== 'polls') return null
		if (operation === 'create') return t('mcp', 'Create private polls with initial options after a plan and confirmation; starts off; does not grant votes, results or credential links')
		if (operation === 'results') return t('mcp', 'Read votes and results allowed by native Polls permissions; starts off')
		if (operation === 'links') return t('mcp', 'Read existing participation links, including personal credentials, only with native edit permission; creates no links; starts off')
		return null
	}

	/**
	 * Independent named choices; creation never rides on a protected-read preset.
	 * @param {string[]} operations actual catalog
	 * @return {Array<{level:string, operations:string[]}>} exact preset sets
	 */
	function tiers(operations) {
		const rows = [
			{ level: 'polls_read', operations: ['read'] },
			{ level: 'polls_read_create', operations: ['read', 'create'] },
			{ level: 'polls_read_results', operations: ['read', 'results'] },
			{ level: 'polls_read_results_links', operations: ['read', 'results', 'links'] },
		]
		return rows.filter(row => row.operations.every(operation => operations.includes(operation)))
	}

	/**
	 * @param {string[]} operations catalog
	 * @param {string} level named independent choice
	 * @return {string[]} exact allowed set, in catalog order
	 */
	function operationsFor(operations, level) {
		if (level === 'none') return []
		const found = tiers(operations).find(row => row.level === level)
		if (!found) throw new Error('Unknown level ' + level + ' for polls')
		return operations.filter(operation => found.operations.includes(operation))
	}

	root.McpAdminPollsGrants = { levelLabels, operationLabels, operationHint, tiers, operationsFor }
})(typeof window !== 'undefined' ? window : globalThis);

/**
 * What the Mail operations let the AI do, for the column tooltips of js/admin-grants.js: `read` lists accounts and
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

	root.McpAdminMailGrants = { WRITE_OPERATIONS, operationHint, writeWarning, confirmWrites }
})(typeof window !== 'undefined' ? window : globalThis);

(function (root) {
	'use strict'

	/** Cumulative tiers per module: [Read, Edit, Full]. Modules with one operation have only Read. */
	const TIERS = {
		files: [['read'], ['edit', 'create', 'move', 'restore'], ['share', 'link', 'delete']],
		notes: [['read'], ['create', 'edit', 'move'], ['delete']],
		deck: [['read'], ['create', 'edit', 'move'], ['delete']],
		calendar: [['read'], ['create', 'edit', 'move'], ['delete', 'transfer']],
		contacts: [['read'], ['create', 'edit'], ['delete']],
		tasks: [['read'], ['create', 'edit'], ['delete']],
		talk: [['read'], ['reply', 'attach', 'quote'], ['create']],
		people: [['read']],
		logs: [['read']],
		mail: [['read'], ['messages'], ['attachments'], ['flags'], ['move'], ['draft'], ['send']],
		tables: [['read'], ['create', 'edit']],
		forms: [['read'], ['create', 'edit'], ['results']],
	}

	/** Names of the tiers by how many a module has. */
	const TIER_NAMES = { 1: ['read'], 2: ['read', 'full'], 3: ['read', 'edit', 'full'] }

	/**
	 * Names of the tiers of the modules whose tiers are not Read, Edit and Full, in tier order. Mail only reads at first:
	 * its first tier lists accounts and folders, its second opens the messages, its third adds the text of attachments,
	 * and the next ones add the Mail writes, so none of its levels is called Full. js/admin-grants.js translates them.
	 */
	const MODULE_TIER_NAMES = {
		forms: ['read', 'forms_write', 'forms_write_results'],
		mail: ['folders', 'messages', 'attachments', 'flags', 'move', 'draft', 'send'],
	}

	/**
	 * @param {string} module module id
	 * @param {number} count number of tiers the module has
	 * @return {string[]|null} the module's own tier names when their number matches, or null to use the general ones
	 */
	function tierNames(module, count) {
		const own = MODULE_TIER_NAMES[module]
		return own && own.length === count ? own : null
	}

	/**
	 * The tiers of a module, restricted to the operations the catalog has, with any operation they do not name added to
	 * the last tier.
	 *
	 * @param {string} module module id
	 * @param {string[]} operations the module's operations in the catalog
	 * @return {Array<{level: string, operations: string[]}>} one entry per tier, cumulative in this order
	 */
	function tiers(module, operations) {
		if (module === 'polls') return root.McpAdminPollsGrants?.tiers(operations) || []
		const known = TIERS[module] || [operations.filter((operation) => operation === 'read'), operations.filter((operation) => operation !== 'read')]
		const listed = known.map((tier) => tier.filter((operation) => operations.includes(operation)))
		const rest = operations.filter((operation) => !listed.some((tier) => tier.includes(operation)))
		if (rest.length > 0) {
			if (listed.length === 0) listed.push([])
			listed[listed.length - 1] = listed[listed.length - 1].concat(rest)
		}
		const kept = listed.filter((tier) => tier.length > 0)
		const names = tierNames(module, kept.length) || TIER_NAMES[kept.length] || kept.map((_, index) => 'tier' + index)
		return kept.map((tier, index) => ({ level: names[index], operations: tier }))
	}

	/**
	 * @param {string} module module id
	 * @param {string[]} operations the module's operations in the catalog
	 * @return {string[]} the levels that can be chosen, from "none" up
	 */
	function levels(module, operations) {
		return ['none', ...tiers(module, operations).map((tier) => tier.level)]
	}

	/**
	 * @param {string} module module id
	 * @param {string[]} operations the module's operations in the catalog
	 * @param {string} level a level of levels()
	 * @return {string[]} the operations that level allows, in catalog order
	 */
	function operationsFor(module, operations, level) {
		if (module === 'polls') return root.McpAdminPollsGrants?.operationsFor(operations, level) || []
		const all = tiers(module, operations)
		const upTo = all.findIndex((tier) => tier.level === level)
		if (level !== 'none' && upTo < 0) {
			throw new Error('Unknown level ' + level + ' for ' + module)
		}
		const allowed = new Set(all.slice(0, upTo + 1).flatMap((tier) => tier.operations))
		return operations.filter((operation) => allowed.has(operation))
	}

	/**
	 * The level a user has in a module, from the effective value of each operation.
	 *
	 * @param {string} module module id
	 * @param {string[]} operations the module's operations in the catalog
	 * @param {Object<string, boolean|null>} values effective value per operation; null is unknown
	 * @return {string} a level of levels(), "custom" when the allowed set is no level, or "unknown" when any value is
	 */
	function levelOf(module, operations, values) {
		if (operations.some((operation) => values[operation] === null || values[operation] === undefined)) {
			return 'unknown'
		}
		const allowed = operations.filter((operation) => values[operation] === true)
		for (const level of levels(module, operations)) {
			const expected = operationsFor(module, operations, level)
			if (expected.length === allowed.length && expected.every((operation) => allowed.includes(operation))) {
				return level
			}
		}
		return 'custom'
	}

	/**
	 * Where a module's values come from, summed over its operations: an individual value on any operation makes the module
	 * individual (an invalid stored value first), then a group whose policy cannot be read, then a group, then the
	 * default.
	 *
	 * @param {string[]} operations the module's operations in the catalog
	 * @param {Object<string, {source: string, groupIds: string[]}>} origins origin per operation, as the API sends it
	 * @return {{source: string, groupIds: string[]}} source is invalid, individual, unknown, group or default
	 */
	function originOf(operations, origins) {
		const sources = operations.map((operation) => origins?.[operation] || { source: 'default', groupIds: [] })
		const groups = (kind) => [...new Set(sources.filter((origin) => origin.source === kind).flatMap((origin) => origin.groupIds || []))].sort()
		if (sources.some((origin) => origin.source === 'invalid')) return { source: 'invalid', groupIds: [] }
		if (sources.some((origin) => origin.source === 'individual_allow' || origin.source === 'individual_deny')) return { source: 'individual', groupIds: [] }
		if (sources.some((origin) => origin.source === 'unknown')) return { source: 'unknown', groupIds: groups('unknown') }
		if (sources.some((origin) => origin.source === 'group')) return { source: 'group', groupIds: groups('group') }
		return { source: 'default', groupIds: [] }
	}

	root.McpAdminLevels = { TIERS, MODULE_TIER_NAMES, tierNames, tiers, levels, operationsFor, levelOf, originOf }
})(typeof window !== 'undefined' ? window : globalThis)
