/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
'use strict'

const fs = require('node:fs')

/**
 * Paths named by one manifest, in file order.
 *
 * Each path is returned as written (relative to `src/`, not absolute). Blank lines and lines whose first
 * non-space character is `#` are skipped. The same path twice is refused, matching the builder. A bad path is
 * still the builder's refusal; this helper only reads the list the frontend tests load.
 *
 * @param {string} file absolute or relative path of `src/admin.manifest` or `src/settings.manifest`
 * @returns {string[]}
 */
function loadManifest(file) {
	const paths = []
	const seen = new Set()
	for (const raw of fs.readFileSync(file, 'utf8').split(/\r\n|\n|\r/)) {
		const line = raw.trim()
		if (line === '' || line.startsWith('#')) {
			continue
		}
		if (seen.has(line)) {
			throw new Error('duplicate entry: ' + line)
		}
		seen.add(line)
		paths.push(line)
	}
	return paths
}

module.exports = { loadManifest }
