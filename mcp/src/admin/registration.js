/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** The admin namespace, shared by the independently registered page units. */
(function (root) {
	'use strict'

	root.OCA = root.OCA || {}
	root.OCA.Mcp = root.OCA.Mcp || {}
	root.OCA.Mcp.Admin = root.OCA.Mcp.Admin || { modules: {}, sessions: {} }
})(typeof window !== 'undefined' ? window : globalThis)
