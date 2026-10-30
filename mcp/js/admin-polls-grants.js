/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
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
})(typeof window !== 'undefined' ? window : globalThis)
