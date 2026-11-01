/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
'use strict'
const manifest = require('./AdminManifest.cjs')
// Run the actual standalone registrations in this realm, as the former CommonJS loader did.
const source = manifest.fragment('admin/registration.js') + ';\n' + manifest.script('admin-file-write-policy.js')
module.exports = Function('globalThis', source + ';return globalThis.OCA.Mcp.Admin')({})
