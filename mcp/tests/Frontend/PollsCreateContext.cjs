/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
const fs = require('node:fs')
const vm = require('node:vm')
const path = require('node:path')
/** Loads the Polls preset policy from js/admin-levels.js, where the admin page loads it. */
class PollsCreateContext {
	/** @return {object} browser-shaped globals with the actual preset helper */
	static load() {
		const root = { t: (_, text) => text }
		vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../js/admin-levels.js'), 'utf8'), root)
		return root
	}
}
module.exports = PollsCreateContext
