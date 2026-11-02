/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Resource scope saving operations.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.scope = admin.modules.scope || {}
	/**
	 * @param {object} context operation input
	 * @return {void} operation result
	 */
	admin.modules.scope.saving = function (context) {
		/**
		 * @param {*} state operation input
		 * @return {Promise<*>} operation result
		 */
		async function saveModule(state) {
			if (state.saving) return
			// The revision this module's draft was read at, never one another module moved to since.
			const body = state.mode === 'unrestricted'
				? { expectedRevision: state.revision, mode: 'unrestricted' }
				: { expectedRevision: state.revision, mode: 'restricted', items: state.items.map((item) => item.id) }
			state.saving = true
			context.note(state, t('mcp', 'Saving…'))
			context.rerender(state)
			try {
				const result = await context.api('PUT', context.scopePath(state.module), null, body)
				state.saving = false
				context.announce(body.expectedRevision, result.revision)
				await context.reloadAll(state, result.revision)
				context.note(state, result.changed ? t('mcp', 'Saved. It applies from the next MCP call of the user; sessions are not ended.') : t('mcp', 'Nothing changed.'))
				if (result.changed && result.receiptId) context.offerUndo(state, result.receiptId, result.revision)
				context.rerender(state, 'mcp-scope-line-' + state.module)
				window.dispatchEvent(new Event('mcp-scope-saved'))
			} catch (error) {
				state.saving = false
				await context.saveFailed(state, error)
			}
		}

		/**
		 * @param {*} state operation input
		 * @param {*} error operation input
		 * @return {Promise<*>} operation result
		 */
		async function saveFailed(state, error) {
			const data = error.data || {}
			if (error.message === 'unconfirmed') {
				context.note(state, t('mcp', 'The save could not be confirmed: it may have been applied. The saved scope is shown again; check it before changing it.'), true)
				const line = state.line
				await context.reloadAll(state)
				state.line = line
			} else if (error.status === 409) {
				// Never written again from here: the admin sees what is saved now and decides.
				context.note(state, t('mcp', 'The policy changed after this scope was loaded, so nothing was saved. The saved scope is shown again.'), true)
				const line = state.line
				await context.reloadAll(state)
				state.line = line
			} else if (error.status === 422 && data.error === 'ScopeInactive') {
				state.active = false
				context.note(state, t('mcp', 'Not saved: the tools of this module do not apply a resource scope in this version.'), true)
			} else if (error.status === 422 && data.error === 'Inaccessible') {
				const missing = new Set(data.items || [])
				state.items = state.items.map((item) => missing.has(item.id) ? Object.assign({}, item, { inaccessible: true }) : item)
				context.note(state, t('mcp', 'Not saved: the user cannot reach the marked resources in Nextcloud. Remove them and save again.'), true)
			} else if (error.status === 422 && data.error === 'TooManyResources') {
				context.note(state, t('mcp', 'Not saved: at most {limit} resources can be selected.', { limit: data.limit || state.limit }, undefined, { escape: false }), true)
			} else if (error.status === 503 && data.uncertain) {
				context.note(state, t('mcp', 'The save could not be confirmed: it may have been applied. The saved scope is shown again; check it before changing it.'), true)
				const line = state.line
				await context.reloadAll(state)
				state.line = line
			} else {
				context.note(state, context.failure(error), true)
			}
			context.rerender(state, 'mcp-scope-line-' + state.module)
		}

		/**
		 * @param {*} state operation input
		 * @param {*} receiptId operation input
		 * @param {*} savedRevision operation input
		 * @return {*} operation result
		 */
		function offerUndo(state, receiptId, savedRevision) {
			const offer = { receiptId, savedRevision, hover: false, focus: false, expired: false }
			state.undo = offer
			setTimeout(() => {
				offer.expired = true
				context.expireUndo(state, offer)
			}, context.UNDO_SECONDS * 1000)
		}

		/** Removes an expired undo once neither the pointer nor the focus holds it; the focus never falls to the page.
		 * @param {*} state operation input
		 * @param {*} offer operation input
		 * @return {*} operation result
		 */
		function expireUndo(state, offer) {
			if (state.undo !== offer || !offer.expired || offer.hover || offer.focus) return
			const focused = document.activeElement && document.activeElement.id === 'mcp-scope-undo-' + state.module
			state.undo = null
			context.rerender(state, focused ? 'mcp-scope-line-' + state.module : undefined)
		}

		/**
		 * @param {*} state operation input
		 * @return {Promise<*>} operation result
		 */
		async function undoModule(state) {
			if (state.saving) return
			const { receiptId, savedRevision } = state.undo
			state.undo = null
			state.saving = true
			context.rerender(state)
			let current
			try {
				current = (await context.api('POST', '/api/grants/undo', null, { receiptId, expectedRevision: savedRevision })).revision
				context.announce(savedRevision, current)
				context.note(state, t('mcp', 'Change undone.'))
				window.dispatchEvent(new Event('mcp-scope-saved'))
			} catch (error) {
				context.note(state, error.message === 'unconfirmed'
					? t('mcp', 'The save could not be confirmed: it may have been applied. The saved scope is shown again; check it before changing it.')
					: error.status === 409
						? t('mcp', 'The policy changed after this change, so it can no longer be undone.')
						: error.status === 410
							? t('mcp', 'This change can no longer be undone: the time to undo it has passed.')
							: context.failure(error), true)
			}
			state.saving = false
			const line = state.line
			await context.reloadAll(state, current)
			state.line = line
			context.rerender(state, 'mcp-scope-line-' + state.module)
		}

		/**
		 * Tells the other blocks of the page that a write of this panel moved the revision from `from` to `to`.
		 *
		 * @param {string} from revision the write was sent with
		 * @param {string} to revision the server answered with
		 * @return {*} operation result
		 */
		function announce(from, to) {
			if (typeof from === 'string' && typeof to === 'string' && from !== to) {
				window.dispatchEvent(new CustomEvent(context.REVISION_MOVED, { detail: { from, to } }))
			}
		}

		Object.assign(context, { announce, expireUndo, offerUndo, saveFailed, saveModule, undoModule })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
