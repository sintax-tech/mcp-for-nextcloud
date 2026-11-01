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

	/**
	 * The source of one fragment of the admin bundle; a path the manifest does not list is an error, so a test never
	 * runs a file the page does not load.
	 *
	 * @param {string} fragment path relative to src/, as listed in src/admin.manifest
	 * @return {string} the fragment's source
	 */
	static fragment(fragment) {
		if (!AdminManifest.fragments().includes(fragment)) {
			throw new Error(fragment + ' is not in src/admin.manifest')
		}
		return fs.readFileSync(path.join(AdminManifest.app(), 'src', fragment), 'utf8')
	}

	/**
	 * The access levels as the page runs them: every src/admin/access/levels/<app>.js, then the level functions of
	 * src/admin/admin-levels.js, in manifest order and joined as the bundle joins them.
	 *
	 * @return {string} one script that installs McpAdminPollsGrants, McpAdminMailGrants and McpAdminLevels
	 */
	static levelsSource() {
		return AdminManifest.fragments()
			.filter((fragment) => fragment.startsWith('admin/access/levels/') || fragment === 'admin/admin-levels.js')
			.map((fragment) => AdminManifest.fragment(fragment))
			.join(';\n')
	}

	/**
	 * The source of an admin script by its file name under src/admin/; admin-levels.js stands for all the access levels
	 * ({@link AdminManifest.levelsSource}), which were one file up to RE-11.
	 *
	 * @param {string} name file name, such as admin-grants.js
	 * @return {string} the source the page runs for it
	 */
	static script(name) {
		return name === 'admin-levels.js' ? AdminManifest.levelsSource() : AdminManifest.fragment('admin/' + name)
	}
}

module.exports = AdminManifest
