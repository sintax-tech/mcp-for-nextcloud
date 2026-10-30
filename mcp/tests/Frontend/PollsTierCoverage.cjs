/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
const assert = require('node:assert/strict')
/** Keeps generic tier coverage exact while checking Polls through its independent preset helper. */
class PollsTierCoverage {
	/**
	 * @param {object} levels real frontend tier service
	 * @param {Object<string,string[]>} modules catalog read from GrantPolicy
	 * @return {void} every grant remains represented, with no generic Polls fallback
	 */
	static assertCatalog(levels, modules) {
		assert.deepEqual([...Object.keys(levels.TIERS), 'polls'].sort(), Object.keys(modules).sort())
		for (const [module, operations] of Object.entries(modules)) {
			if (module === 'polls') {
				const rows = levels.tiers(module, operations)
				assert.deepEqual([...new Set(rows.flatMap(row => [...row.operations]))].sort(), [...operations].sort())
				continue
			}
			const listed = levels.TIERS[module].flat()
			assert.deepEqual([...listed].sort(), [...operations].sort(), module)
			assert.equal(new Set(listed).size, listed.length, module + ' has no operation twice')
			assert.deepEqual(JSON.parse(JSON.stringify(levels.TIERS[module][0])), ['read'], module + ' starts with Read')
		}
	}
}
module.exports = PollsTierCoverage
