/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
const fs = require('node:fs')
const vm = require('./AdminContext.cjs')
const path = require('node:path')
/** Loads the Polls preset policy from src/admin/access/levels/polls.js (through AdminManifest), where the admin page loads it. */
class PollsCreateContext {
	/** @return {object} browser-shaped globals with the actual preset helper */
	static load() {
		const root = { t: (_, text) => text }
		vm.runInNewContext(require('./AdminManifest.cjs').levelsSource(), root)
		return root
	}
}
module.exports = PollsCreateContext
