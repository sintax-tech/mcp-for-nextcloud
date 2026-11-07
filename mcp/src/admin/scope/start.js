/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Compose the scope editor and start its original effects once.
 * @param {object} admin shared OCA.Mcp.Admin registry and public surfaces
 * @return {void}
 */
(function (admin) {
	'use strict'

	const context = {}
	admin.sessions.scope = context
	admin.modules.scope.session(context)
	admin.modules.scope.loading(context)
	admin.modules.scope.drawer(context)
	admin.modules.scope.rendering(context)
	admin.modules.scope.browsing(context)
	admin.modules.scope.saving(context)
	admin.modules.scope.entry(context)
})((globalThis.window || globalThis).OCA.Mcp.Admin)
