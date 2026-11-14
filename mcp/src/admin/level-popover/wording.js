/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the level popover wording unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	admin.modules.levelPopover = admin.modules.levelPopover || {}
	/** Attach permission names, level summaries and inheritance source descriptions.
	 * @param {object} context matrix and level catalogs, popover element and live active cell bindings
	 * @return {void}
	 */
	admin.modules.levelPopover.wording = function (context) {
		/** Name the module permission or the connection eligibility permission.
		 * @return {string} module name, or "Can connect"
		 * @param {string|null} module catalog module ID, null for connection eligibility
		 */
		function permissionName(module) {
			return module === null ? t('mcp', 'Can connect') : context.matrix.labels().modules[module] || module
		}

		/** Translate a level name, using Yes and No for connection eligibility.
		 * @return {string} name of a level of src/admin/admin-levels.js, or Yes / No for "Can connect"
		 * @param {string|null} module catalog module ID, null for connection eligibility
		 * @param {string} level permission level ID
		 */
		function levelName(module, level) {
			if (module === null) {
				return level === 'read' ? t('mcp', 'Yes') : t('mcp', 'No')
			}
			return window.OCA.Mcp.Admin.levelLabels()[level] || level
		}

		/** Calculate a level from the module's catalog and effective operation values.
		 * @return {string} the level these values are, for any module, "Can connect" included
		 * @param {string|null} module catalog module ID, null for connection eligibility
		 * @param {Object<string, boolean|null>} values effective operation values indexed by operation ID
		 */
		function levelOfValues(module, values) {
			if (module === null) {
				return values.eligible === null || values.eligible === undefined ? 'unknown' : values.eligible ? 'read' : 'none'
			}
			return context.levels.levelOf(module, context.operationsOf(module), values)
		}

		/** Describe the inherited grant source and any contributing groups.
		 * @return {string} where an inherited value comes from
		 * @param {{source: string, groupIds?: string[]}} origin grant source and contributing groups
		 */
		function sourceName(origin) {
			if (origin.source === 'group') return t('mcp', 'Granted by groups: {groups}', { groups: origin.groupIds.join(', ') }, undefined, { escape: false })
			if (origin.source === 'unknown') return t('mcp', 'Unknown')
			return t('mcp', 'Default')
		}

		/** The "inherited" line of the popover.
		 * @return {string} the "Inherited" line of the popover */
		function inheritedLine() {
			if (context.current.loading) return t('mcp', 'Checking what this person inherits…')
			if (context.current.unavailable || context.current.inherited === null) return t('mcp', 'What this person inherits could not be read.')
			const values = Object.fromEntries(Object.entries(context.current.inherited).map(([operation, value]) => [operation, value.allowed]))
			const level = context.levelOfValues(context.current.module, values)
			const sources = Object.values(context.current.inherited)
			const group = sources.find((value) => value.source === 'group')
			const origin = sources.some((value) => value.source === 'unknown') ? { source: 'unknown', groupIds: [] } : group || { source: 'default', groupIds: [] }
			return t('mcp', 'Inherits: {level} ({source})', { level: context.levelName(context.current.module, level), source: context.sourceName(origin) }, undefined, { escape: false })
		}

		/** Describe the operations included in a selectable permission level.
		 * @return {string} description of a level: the operations it allows
		 * @param {string|null} module catalog module ID, null for connection eligibility
		 * @param {string} level permission level ID
		 */
		function levelDescription(module, level) {
			if (module === null) {
				return level === 'read' ? t('mcp', 'The person may connect an MCP client.') : t('mcp', 'The person may not connect, and is signed out.')
			}
			const names = context.matrix.labels().operations
			const allowed = context.levels.operationsFor(module, context.operationsOf(module), level)
			return allowed.length === 0 ? t('mcp', 'The tools of this app stay hidden.') : allowed.map((operation) => names[operation] || operation).join(', ')
		}

		Object.assign(context, { permissionName, levelName, levelOfValues, sourceName, inheritedLine, levelDescription })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
