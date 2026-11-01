/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Access levels of Forms: Read, then writing forms, then writing forms and reading responses; src/admin/admin-grants.js translates the names.
 * Registered in window.OCA.Mcp.Admin.levelCatalog, which src/admin/admin-levels.js reads; see that file for how tiers make levels.
 */
(function (root) {
	'use strict'

	const catalog = root.OCA.Mcp.Admin.levelCatalog = root.OCA.Mcp.Admin.levelCatalog || { tiers: {}, tierNames: {} }
	catalog.tiers.forms = [['read'], ['create', 'edit'], ['results']]
	catalog.tierNames.forms = ['read', 'forms_write', 'forms_write_results']
})(typeof window !== 'undefined' ? window : globalThis)
