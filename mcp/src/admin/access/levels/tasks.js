/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Access levels of Tasks: Read, then Edit adds creating and editing, then Full adds deleting.
 * Registered in window.McpAdminLevelCatalog, which src/admin/admin-levels.js reads; see that file for how tiers make levels.
 */
(function (root) {
	'use strict'

	const catalog = root.McpAdminLevelCatalog = root.McpAdminLevelCatalog || { tiers: {}, tierNames: {} }
	catalog.tiers.tasks = [['read'], ['create', 'edit'], ['delete']]
})(typeof window !== 'undefined' ? window : globalThis)
