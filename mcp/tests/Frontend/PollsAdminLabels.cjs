/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
const assert = require('node:assert/strict')
const vm = require('node:vm')
const fs = require('node:fs')
const path = require('node:path')

/** Loads the actual linked Polls label helper while preserving the global catalog/translation contract. */
class PollsAdminLabels {
	/** @param {string} root app root @param {Function} cut existing source extractor @return {void} */
	static check(root, cut) {
		const context = { t: (app, text) => text }
		context.window = context
		vm.runInNewContext(fs.readFileSync(path.join(root, 'js/admin-levels.js'), 'utf8'), context)
		vm.runInNewContext(cut('function operationLabels() {') + '\nthis.labels = operationLabels()', context)
		const policy = fs.readFileSync(path.join(root, 'lib/Access/Grants/GrantPolicy.php'), 'utf8')
		const operations = new Set([...policy.matchAll(/^\s*'\w+' => \[((?:'\w+',? ?)+)\],$/gm)].flatMap((match) => match[1].match(/\w+/g)))
		assert.ok(operations.has('restore'), 'the catalog was read from lib/Access/Grants/GrantPolicy.php')
		for (const operation of operations) {
			assert.equal(typeof context.labels[operation], 'string', `label of ${operation}`)
			for (const language of ['pt_BR', 'es', 'de']) {
				const translations = JSON.parse(fs.readFileSync(path.join(root, 'l10n', language + '.json'), 'utf8')).translations
				assert.equal(typeof translations[context.labels[operation]], 'string', `${language} translation of "${context.labels[operation]}"`)
			}
		}
		assert.equal(context.labels.restore, 'restore')
	}
}
module.exports = PollsAdminLabels
