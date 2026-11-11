/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Frontend defaults, checked against GrantPolicy for every catalog pair by the permission tests. */
(function (admin) {
	'use strict'

	const deniedReads = ['logs', 'activity', 'notifications', 'availability', 'status']
	/**
	 * @param {string} module Grant module.
	 * @param {string} operation Grant operation.
	 * @return {boolean} Effective default without an individual or group grant.
	 */
	function granted(module, operation) {
		return operation === 'read' && !deniedReads.includes(module)
	}
	admin.grantDefaults = { granted, deniedReads }
})((globalThis.window || globalThis).OCA.Mcp.Admin)
