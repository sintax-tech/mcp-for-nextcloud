/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
'use strict'

const fs = require('node:fs')
const path = require('node:path')
const { loadManifest } = require('./load-manifest.cjs')

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
	 * The fragments of the admin bundle, read by the builder's own manifest reader (RE-10).
	 *
	 * @return {string[]} paths relative to src/, in manifest order
	 */
	static fragments() {
		return loadManifest(path.join(AdminManifest.app(), 'src/admin.manifest'))
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
			.filter((fragment) => fragment === 'admin/registration.js' || fragment.startsWith('admin/access/levels/') || fragment === 'admin/admin-levels.js')
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
		if (name === 'admin-levels.js') return AdminManifest.levelsSource()
		if (name === 'admin-grants.js' && AdminManifest.fragments().includes('admin/matrix/start.js')) {
			return AdminManifest.fragments().filter(p => p.startsWith('admin/matrix/') || p === 'admin/admin-grants.js')
				.map(p => AdminManifest.fragment(p)).join(';\n')
		}
		return AdminManifest.fragment('admin/' + name)
	}
	/**
	 * Load an actual registered unit and expose one declared operation to the existing fixture.
	 * Other operations of that unit remain the fixture dependencies when the case supplies them.
	 * @param {string} declaration original function or constant name
	 * @return {string} executable unit and fixture binding
	 */
	static operation(declaration) {
		const name = declaration.match(/(?:function|const) (\w+)/)?.[1]
		const entry = AdminManifest.fragments().find(p => {
			if (!p.startsWith('admin/matrix/') && p !== 'admin/admin-grants.js') return false
			return new RegExp('(?:function|const) ' + name + '(?:\\(| )').test(AdminManifest.fragment(p))
		})
		if (!entry) throw Error('Missing declared operation: ' + declaration)
		const source = AdminManifest.fragment(entry)
		const address = entry === 'admin/admin-grants.js' ? 'settings' : 'matrix.' + path.basename(entry, '.js').replaceAll('-', '_')
		const operations = [...source.matchAll(/^\t\t(?:async )?function (\w+)\(/gm)].map(m => m[1])
		return ';\n' + AdminManifest.fragment('admin/registration.js') + ';\n' + source + `;\n(function () {
			const target = globalThis
			const before = Object.getOwnPropertyDescriptors(target)
			;(globalThis.window || globalThis).OCA.Mcp.Admin.modules.${address}(target)
			for (const key of ${JSON.stringify(operations)}) {
				if (key === ${JSON.stringify(name)}) continue
				if (before[key]) Object.defineProperty(target, key, before[key])
				else delete target[key]
			}
		})();\n`
	}

}

module.exports = AdminManifest
