/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Compose the matrix and start its original effects in their original order.
 * @param {object} admin operation input
 * @return {void} operation result
 */
(function (admin) {
	'use strict'
	const root = document.getElementById('mcp-admin')
	if (!root) return
	const context = { root }
	admin.sessions.matrix = context
	admin.modules.matrix.session(context)
	admin.modules.matrix.labels(context)
	admin.modules.matrix.requests(context)
	admin.modules.matrix.revisions(context)
	admin.modules.matrix.elements(context)
	admin.modules.matrix.loading(context)
	admin.modules.matrix.view(context)
	admin.modules.matrix.rendering(context)
	admin.modules.matrix.rows(context)
	admin.modules.matrix.operationCells(context)
	admin.modules.matrix.levelCells(context)
	admin.modules.matrix.eligibility(context)
	admin.modules.matrix.notices(context)
	admin.modules.matrix.commands(context)
	admin.modules.matrix.interactions(context)
	admin.modules.protection.exclusionTags(context)
	admin.modules.checkout.uploadLimit(context)
	admin.modules.logs.privacy(context)
	admin.modules.logs.readers(context)
	admin.modules.oauthClients.editor(context)
	context.attachView()
	context.attachExceptions()
	context.attachNotices()
	context.attachService()
	context.attachReloads()
	context.attachInteractions()
	/**
	 * What src/admin/overrides/start.js needs from the matrix: the page on screen, the shared request, lock and undo notice,
	 * and a re-render, so it can paint rows that were drawn before it loaded. It never touches the matrix state itself.
	 */
	window.OCA.Mcp.Admin.matrix = {
		data: () => context.state.data,
		api: context.api,
		announce: context.announce,
		reload: context.reloadLocked,
		policyError: context.policyError,
		notifyError: context.notifyError,
		isBusy: () => context.bulkBusy || context.matrixLocked,
		setBusy: context.setBulkBusy,
		labels: () => ({ modules: context.moduleLabels(), operations: context.operationLabels() }),
		/**
		 * @param {string} message text of the notice
		 * @param {{receiptId: string|null, revision: string}} saved the saved command
		 * @return {Function|null} the undo that was offered, to give back to focusUndo()
		 */
		offerUndo(message, saved) {
			const undo = saved.receiptId === null ? null : () => context.undoBulk(saved.receiptId, saved.revision)
			context.showNotice(message, undo)
			return undo
		},
		/** @param {Function|null} undo what offerUndo() returned: the focus moves to it while it is still the offered undo */
		focusUndo(undo) {
			if (undo !== null && context.pendingUndo === undo) {
				context.undoButton.focus()
			}
		},
		refresh: () => context.state.data !== null && context.render(),
		bulk: context.bulk,
		levelLabels: context.levelLabels,
	}
	context.load()
	context.initTagsSection()
	context.initOauthClients()
	context.initCheckoutLimit()
	context.initLogsAccess()
	context.initLogsPrivacy()
})((globalThis.window || globalThis).OCA.Mcp.Admin)
