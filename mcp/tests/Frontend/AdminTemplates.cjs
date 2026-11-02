/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
'use strict'
const fs = require('node:fs')
const path = require('node:path')
/** Read the main template and its actual native-rendered panel sources for existing source assertions. */
exports.read = function (file, encoding) {
	const source = fs.readFileSync(file, encoding)
	if (path.basename(file) !== 'admin.php') return source
	return source.replace(/<\?php print_unescaped\(\$_\['renderAdminPanel'\]\('([^']+)', \$_\)\); \?>/g,
		(all, panel) => fs.readFileSync(path.join(path.dirname(file), 'admin', panel + '.php'), encoding).replace(/^<\?php[\s\S]*?\?>\n/, ''))
}
