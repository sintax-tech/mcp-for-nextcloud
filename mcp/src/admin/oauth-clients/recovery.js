/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the oauth clients recovery unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.oauthClients = admin.modules.oauthClients || {}
	/** Attach conflict recovery that refreshes OAuth state without replaying queued writes.
	 * @param {object} context OAuth controls, confirmed settings and live queue and revision bindings
	 * @return {void}
	 */
	admin.modules.oauthClients.recovery = function (context) {
		/**
		 * When a save failed because the page was out of date (409, or 428 without a revision) or the server could not
		 * confirm it (503 uncertain), shows what is stored now, in the queue's order, and tells the admin nothing was
		 * overwritten. The cards with unsaved typed changes are kept (see keepUnsaved()). An intent dropped behind such a
		 * save is handled by that reload.
		 * @param {Error} e error thrown by put()
		 * @return {Promise<boolean>} whether the error was handled here (the settings are then reloaded)
		 */
		async function recover(e) {
			if (e.message === 'stale') {
				return true
			}
			if (e.message === '409' || e.message === '428') {
				context.notifyError(t('mcp', 'The OAuth client settings were changed in another window or by another administrator. The current settings were loaded; nothing was overwritten.'))
			} else if (e.message === '503' && e.data && e.data.uncertain) {
				context.notifyError(t('mcp', 'The server could not confirm whether the change was saved. The current settings were loaded.'))
			} else {
				return false
			}
			try {
				const unsaved = [...context.customList.children].filter((card) => context.drafts.get(card)?.edited())
				context.show(await context.enqueue(() => context.api('GET', '/api/oauth-clients')))
				context.customList.replaceChildren(...context.keepUnsaved(unsaved))
			} catch (reload) {
				context.notifyError(t('mcp', 'Could not load the OAuth client settings.'))
			}
			return true
		}

		/**
		 * The cards of the clients the server has now, with the cards whose typed changes it does not have put back: a
		 * saved client keeps its edited card in its place, a new card comes after the saved ones, and the edits of a
		 * client deleted elsewhere come back as a new card. Each card put back says it was not saved.
		 * @param {HTMLElement[]} unsaved cards with typed changes, read before the reload
		 * @return {HTMLElement[]} the cards to show
		 */
		function keepUnsaved(unsaved) {
			const kept = new Map(unsaved.filter((card) => context.drafts.get(card).client.id !== null).map((card) => [context.drafts.get(card).client.id, card]))
			const cards = context.customClients.map((client) => kept.get(client.id) || context.customCard(client))
			const extra = unsaved.filter((card) => !cards.includes(card)).map((card) => {
				if (context.drafts.get(card).client.id === null) {
					return card
				}
				const fresh = context.customCard({ ...context.drafts.get(card).read(), id: null })
				context.report(fresh.querySelector('.mcp-status'), false, t('mcp', 'Not saved: this client was deleted in the meantime. Your changes are kept here as a new client; saving adds it again.'))
				return fresh
			})
			for (const card of unsaved.filter((card) => cards.includes(card) || extra.includes(card))) {
				context.report(card.querySelector('.mcp-status'), false, t('mcp', 'Not saved: the settings changed after this page was loaded. Your changes are kept here; check them and save again.'))
			}
			return [...cards, ...extra]
		}

		Object.assign(context, { keepUnsaved, recover })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
