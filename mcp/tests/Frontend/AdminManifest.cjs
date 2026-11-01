/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
'use strict'

const fs = require('node:fs')
const path = require('node:path')

/**
 * The admin page's sources as src/admin.manifest lists them: what js/mcp-admin.js is built from, in page order.
 * The tests load the admin scripts through it, so they run the same fragments, in the same order, as the page.
 */
class AdminManifest {
	/** @return {string} the app folder */
	static app() {
		return path.join(__dirname, '../..')
	}

	/**
	 * The fragments of the admin bundle: one path per line relative to src/, blank lines and `#` lines skipped.
	 *
	 * @return {string[]} paths relative to src/, in manifest order
	 */
	static fragments() {
		return fs.readFileSync(path.join(AdminManifest.app(), 'src/admin.manifest'), 'utf8').split('\n')
			.map((line) => line.trim())
			.filter((line) => line !== '' && !line.startsWith('#'))
	}

	/**
	 * Whether the page runs one fragment before another; both must be in the manifest.
	 *
	 * @param {string} first path relative to src/
	 * @param {string} second path relative to src/
	 * @return {boolean} true when first is listed, second is listed and first comes before second
	 */
	static loadsBefore(first, second) {
		const fragments = AdminManifest.fragments()
		const a = fragments.indexOf(first)
		const b = fragments.indexOf(second)
		return a >= 0 && b >= 0 && a < b
	}
}

module.exports = AdminManifest
