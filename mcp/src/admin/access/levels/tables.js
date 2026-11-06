/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Access levels of Tables: Read, then Full adds creating and editing together.
 * Registered in window.OCA.Mcp.Admin.levelCatalog, which src/admin/admin-levels.js reads; see that file for how tiers make levels.
 */
(function (root) {
	'use strict'

	const catalog = root.OCA.Mcp.Admin.levelCatalog = root.OCA.Mcp.Admin.levelCatalog || { tiers: {}, tierNames: {} }
	catalog.tiers.tables = [['read'], ['create', 'edit']]
	// Structure and sharing remain operation-only until the presets are explicitly widened.
	catalog.untiered = Object.assign(catalog.untiered || {}, { tables: ['schema', 'share'] })
})(typeof window !== 'undefined' ? window : globalThis)
