/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** The admin namespace, shared by the independently registered page units.
 * @param {Window|typeof globalThis} root page global on which the single app namespace is initialized
 * @return {void}
 */
(function (root) {
	'use strict'

	root.OCA = root.OCA || {}
	root.OCA.Mcp = root.OCA.Mcp || {}
	root.OCA.Mcp.Admin = root.OCA.Mcp.Admin || { modules: {}, sessions: {} }
})(typeof window !== 'undefined' ? window : globalThis)
