/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** level_popover wording operations.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	admin.modules.levelPopover = admin.modules.levelPopover || {}
	/**
	 * @param {object} context operation input
	 * @return {void} operation result
	 */
	admin.modules.levelPopover.wording = function (context) {
		/** @return {string} module name, or "Can connect"
		 * @param {*} module operation input
		 */
		function permissionName(module) {
			return module === null ? t('mcp', 'Can connect') : context.matrix.labels().modules[module] || module
		}

		/** @return {string} name of a level of src/admin/admin-levels.js, or Yes / No for "Can connect"
		 * @param {*} module operation input
		 * @param {*} level operation input
		 */
		function levelName(module, level) {
			if (module === null) {
				return level === 'read' ? t('mcp', 'Yes') : t('mcp', 'No')
			}
			return { ...window.OCA.Mcp.Admin.pollsGrants?.levelLabels(), forms_write: t('mcp', 'Read and write forms'), forms_write_results: t('mcp', 'Read and write forms and read responses'), none: t('mcp', 'None'), read: t('mcp', 'Read'), edit: t('mcp', 'Edit'), full: t('mcp', 'Full'), custom: t('mcp', 'Custom'), unknown: t('mcp', 'Unknown') }[level] || level
		}

		/** @return {string} the level these values are, for any module, "Can connect" included
		 * @param {*} module operation input
		 * @param {*} values operation input
		 */
		function levelOfValues(module, values) {
			if (module === null) {
				return values.eligible === null || values.eligible === undefined ? 'unknown' : values.eligible ? 'read' : 'none'
			}
			return context.levels.levelOf(module, context.operationsOf(module), values)
		}

		/** @return {string} where an inherited value comes from
		 * @param {*} origin operation input
		 */
		function sourceName(origin) {
			if (origin.source === 'group') return t('mcp', 'Granted by groups: {groups}', { groups: origin.groupIds.join(', ') }, undefined, { escape: false })
			if (origin.source === 'unknown') return t('mcp', 'Unknown')
			return t('mcp', 'Default')
		}

		/** @return {string} the "Inherited" line of the popover */
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

		/** @return {string} description of a level: the operations it allows
		 * @param {*} module operation input
		 * @param {*} level operation input
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
