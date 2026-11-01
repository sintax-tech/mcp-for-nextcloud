/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** OAuth client saving operations.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.oauth_clients = admin.modules.oauth_clients || {}
	/**
	 * @param {object} context operation input
	 * @return {void} operation result
	 */
	admin.modules.oauth_clients.saving = function (context) {
		/**
		 * Runs one task of the block after the previous one has finished.
		 *
		 * @param {Function} task async work
		 * @return {Promise<*>} what the task returned
		 */
		function enqueue(task) {
			const run = context.queue.then(task)
			context.queue = run.catch(() => {})
			return run
		}


		/**
		 * Sends one change when its turn comes: the body is built then by `build`, from the clients the server confirmed
		 * last, with the revision of that same answer, which is then replaced by the revision and clients the server
		 * answers with. The policy revision moved, so the matrix is reloaded to keep its own saves valid. An intent
		 * queued before a reload is dropped (error "stale"); a refused save drops the intents queued behind it.
		 *
		 * @param {Function} build (clients) => the changed settings
		 * @return {Promise<object>} the settings the server saved
		 */
		function put(build) {
			const queuedAt = context.epoch
			return context.enqueue(async () => {
				if (queuedAt !== context.epoch) {
					throw new Error('stale')
				}
				try {
					const sent = context.oauthRevision
					const change = build(context.customClients)
					const committed = context.changes(change, context.confirmed)
					const data = await context.api('PUT', '/api/oauth-clients', { ...change, expectedRevision: sent })
					context.oauthRevision = data.revision
					context.customClients = data.customClients
					context.confirmed = data
					if (committed && context.oneStep(sent, data.revision)) {
						context.announce(sent, data.revision)
					}
					context.load()
					return data
				} catch (e) {
					if (e.message === '409' || e.message === '428' || (e.message === '503' && e.data && e.data.uncertain)) {
						context.epoch++
					}
					throw e
				}
			})
		}


		/**
		 * Whether a request differs from the settings stored at the block's revision, compared the way the server compares
		 * them (OAuthClientsService): the server then commits it and the revision advances. Hosts are trimmed, lowercased
		 * (ASCII only) and without duplicates, and the default list is stored as no list (GrantsController::hosts,
		 * writeHosts); clients keep their order, with names and redirect URIs trimmed and URIs without duplicates
		 * (CustomClient::validate, validateUris). Every trim is PHP's own (phpTrim()). A value still holding a control
		 * character after it, which the server refuses, counts as unsure. False when unsure, so nothing is announced then.
		 *
		 * @param {object} change the settings sent (hosts, nativeClientEnabled or customClients)
		 * @param {object} stored the server's last answer
		 * @return {boolean}
		 */
		function changes(change, stored) {
			const control = (text) => /[\x00-\x1f\x7f]/.test(text)
			const uris = (list) => [...new Set(list.map(context.phpTrim))]
			if (change.nativeClientEnabled !== undefined && change.nativeClientEnabled !== stored.nativeClientEnabled) {
				return true
			}
			if (change.hosts !== undefined) {
				// strtolower() of PHP 8 changes A-Z only.
				const hosts = [...new Set(change.hosts.map((host) => context.phpTrim(host).replace(/[A-Z]/g, (letter) => letter.toLowerCase())))]
				if (hosts.some(control)) {
					return false
				}
				// Stored as no list when the request is the default set; only known while the default is what is stored.
				const isDefault = stored.hostsDefault && hosts.length === stored.hosts.length && hosts.every((host) => stored.hosts.includes(host))
				if (stored.hostsDefault ? !isDefault : hosts.join(',') !== stored.hosts.join(',')) {
					return true
				}
			}
			if (change.customClients !== undefined) {
				if (change.customClients.some((client) => control(context.phpTrim(client.name)) || uris(client.redirectUris).some(control))) {
					return false
				}
				const key = (clients) => JSON.stringify(clients.map((client) => [client.id ?? null, context.phpTrim(client.name), client.enabled, uris(client.redirectUris)]))
				if (key(change.customClients) !== key(stored.customClients)) {
					return true
				}
			}
			return false
		}


		/**
		 * Saves one change to the list of self-hosted clients.
		 *
		 * @param {Function} change (clients) => every client to keep, a new one without id; applied to the clients the
		 *     server confirmed last, when the save's turn comes
		 * @return {Promise<object|undefined>} the client the server added, if any
		 */
		async function saveCustom(change) {
			let before = new Set()
			await context.put((clients) => {
				before = new Set(clients.map((client) => client.id))
				return { customClients: change(clients).map(({ id, name, enabled, redirectUris }) => ({ id, name, enabled, redirectUris })) }
			})
			return context.customClients.find((client) => !before.has(client.id))
		}

		Object.assign(context, { changes, enqueue, put, saveCustom })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
