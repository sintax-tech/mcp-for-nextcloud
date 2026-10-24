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
 * Pure functions, no DOM and no strings: js/admin-grants.js draws the cells and names the levels.
 */
(function (root) {
	'use strict'

	/** Cumulative tiers per module: [Read, Edit, Full]. Modules with one operation have only Read. */
	const TIERS = {
		files: [['read'], ['edit', 'create', 'move', 'restore'], ['share', 'link']],
		notes: [['read'], ['create', 'edit', 'move'], ['delete']],
		deck: [['read'], ['create', 'edit', 'move'], ['delete']],
		calendar: [['read'], ['create', 'edit', 'move'], ['delete', 'transfer']],
		contacts: [['read'], ['create', 'edit'], ['delete']],
		tasks: [['read'], ['create', 'edit'], ['delete']],
		talk: [['read'], ['reply', 'attach', 'quote'], ['create']],
		people: [['read']],
		logs: [['read']],
		mail: [['read']],
	}

	/** Names of the tiers by how many a module has. */
	const TIER_NAMES = { 1: ['read'], 2: ['read', 'full'], 3: ['read', 'edit', 'full'] }

	/**
	 * The tiers of a module, restricted to the operations the catalog has, with any operation they do not name added to
	 * the last tier.
	 *
	 * @param {string} module module id
	 * @param {string[]} operations the module's operations in the catalog
	 * @return {Array<{level: string, operations: string[]}>} one entry per tier, cumulative in this order
	 */
	function tiers(module, operations) {
		const known = TIERS[module] || [operations.filter((operation) => operation === 'read'), operations.filter((operation) => operation !== 'read')]
		const listed = known.map((tier) => tier.filter((operation) => operations.includes(operation)))
		const rest = operations.filter((operation) => !listed.some((tier) => tier.includes(operation)))
		if (rest.length > 0) {
			if (listed.length === 0) listed.push([])
			listed[listed.length - 1] = listed[listed.length - 1].concat(rest)
		}
		const kept = listed.filter((tier) => tier.length > 0)
		const names = TIER_NAMES[kept.length] || kept.map((_, index) => 'tier' + index)
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

	root.McpAdminLevels = { TIERS, tiers, levels, operationsFor, levelOf, originOf }
})(typeof window !== 'undefined' ? window : globalThis)
