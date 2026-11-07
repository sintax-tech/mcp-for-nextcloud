/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Access levels of Collectives: Read lists collectives and pages, reads and searches them, then Full adds creating,
 * editing and moving pages together. Creating a collective ('collective.create') is in no level: it is chosen operation
 * by operation, so no preset grants it and the presets stay what they were.
 * Registered in window.OCA.Mcp.Admin.levelCatalog, which src/admin/admin-levels.js reads; see that file for how tiers make levels.
 */
(function (root) {
	'use strict'

	const catalog = root.OCA.Mcp.Admin.levelCatalog = root.OCA.Mcp.Admin.levelCatalog || { tiers: {}, tierNames: {} }
	catalog.tiers.collectives = [['read'], ['create', 'edit', 'move']]
	catalog.untiered = Object.assign(catalog.untiered || {}, { collectives: ['collective.create'] })
})(typeof window !== 'undefined' ? window : globalThis)
