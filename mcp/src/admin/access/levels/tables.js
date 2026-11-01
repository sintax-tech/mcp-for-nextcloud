/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Access levels of Tables: Read, then Full adds creating and editing together.
 * Registered in window.McpAdminLevelCatalog, which src/admin/admin-levels.js reads; see that file for how tiers make levels.
 */
(function (root) {
	'use strict'

	const catalog = root.McpAdminLevelCatalog = root.McpAdminLevelCatalog || { tiers: {}, tierNames: {} }
	catalog.tiers.tables = [['read'], ['create', 'edit']]
})(typeof window !== 'undefined' ? window : globalThis)
