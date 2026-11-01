/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

'use strict'
const vm = require('node:vm')
const names = {
	"McpAdminPicker": "picker",
	"McpAdminLevelCatalog": "levelCatalog",
	"McpAdminPollsGrants": "pollsGrants",
	"McpAdminMailGrants": "mailGrants",
	"McpAdminLevels": "levels",
	"McpAdminMatrix": "matrix",
	"McpLevelPopover": "levelPopover",
	"McpAdminExceptions": "exceptions",
	"McpRowActions": "rowActions",
	"McpAdminOverrides": "overrides",
	"McpScopeUi": "scope",
	"McpInspectorUi": "inspector"
}

/** Bind the existing fixture slots to the actual public operations under test. */
function prepare(context) {
	for (const root of [context, context.window].filter(Boolean)) {
		root.OCA = root.OCA || {}
		root.OCA.Mcp = root.OCA.Mcp || {}
		const admin = root.OCA.Mcp.Admin = root.OCA.Mcp.Admin || { modules: {}, sessions: {} }
		for (const [old, name] of Object.entries(names)) {
			const descriptor = Object.getOwnPropertyDescriptor(root, old)
			if (descriptor && !descriptor.get) admin[name] = root[old]
			Object.defineProperty(root, old, { configurable: true, get: () => admin[name], set: (value) => { admin[name] = value } })
		}
	}
	return context
}

module.exports = {
	...vm,
	createContext: (context, ...options) => vm.createContext(prepare(context), ...options),
	runInNewContext: (source, context = {}, ...options) => vm.runInNewContext(source, prepare(context), ...options),
	runInContext: (source, context, ...options) => vm.runInContext(source, prepare(context), ...options),
}
