/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Access levels of Talk: Read, then Edit adds replying, attaching and quoting, then Full adds create.
 * Registered in window.OCA.Mcp.Admin.levelCatalog, which src/admin/admin-levels.js reads; see that file for how tiers make levels.
 */
(function (root) {
	'use strict'

	const catalog = root.OCA.Mcp.Admin.levelCatalog = root.OCA.Mcp.Admin.levelCatalog || { tiers: {}, tierNames: {} }
	catalog.tiers.talk = [['read'], ['reply', 'attach', 'quote'], ['create']]
})(typeof window !== 'undefined' ? window : globalThis)
