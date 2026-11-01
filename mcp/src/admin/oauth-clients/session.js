/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** One OAuth editor session and its live queue, revisions and card drafts.
 * @param {object} context live bindings of this editor
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.oauth_clients = admin.modules.oauth_clients || {}
	admin.modules.oauth_clients.session = function (context) {
		const hostsInput = document.getElementById('mcp-oauth-hosts')
		const hostsSave = document.getElementById('mcp-oauth-hosts-save')
		const hostsStatus = document.getElementById('mcp-oauth-hosts-status')
		const nativeBox = document.getElementById('mcp-native-client')
		const nativeStatus = document.getElementById('mcp-native-client-status')
		const details = document.getElementById('mcp-native-client-details')
		const clientId = document.getElementById('mcp-native-client-id')
		const redirects = document.getElementById('mcp-native-redirects')
		const customList = document.getElementById('mcp-custom-clients')
		const customAdd = document.getElementById('mcp-custom-add')
		/** Self-hosted clients as the server last returned them; each card edits one of them (or a new one). */
		let customClients = []
		let cardCount = 0
		// The block's own revision, from its own GET or save; never the matrix's, never an error body's.
		let oauthRevision = null
		let queue = Promise.resolve()
		// Advances when the page is reloaded after a refused save: intents queued before that are dropped.
		let epoch = 0
		/** Per card: the client it was built from, and what is typed in it now. */
		const drafts = new WeakMap()
		/** The server's last answer: what is stored at this block's revision. */
		let confirmed = null

		Object.defineProperties(context, {
			hostsInput: { configurable: true, get: () => hostsInput },
			hostsSave: { configurable: true, get: () => hostsSave },
			hostsStatus: { configurable: true, get: () => hostsStatus },
			nativeBox: { configurable: true, get: () => nativeBox },
			nativeStatus: { configurable: true, get: () => nativeStatus },
			details: { configurable: true, get: () => details },
			clientId: { configurable: true, get: () => clientId },
			redirects: { configurable: true, get: () => redirects },
			customList: { configurable: true, get: () => customList },
			customAdd: { configurable: true, get: () => customAdd },
			customClients: { configurable: true, get: () => customClients, set: (value) => { customClients = value } },
			cardCount: { configurable: true, get: () => cardCount, set: (value) => { cardCount = value } },
			oauthRevision: { configurable: true, get: () => oauthRevision, set: (value) => { oauthRevision = value } },
			queue: { configurable: true, get: () => queue, set: (value) => { queue = value } },
			epoch: { configurable: true, get: () => epoch, set: (value) => { epoch = value } },
			drafts: { configurable: true, get: () => drafts },
			confirmed: { configurable: true, get: () => confirmed, set: (value) => { confirmed = value } },
		})
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
