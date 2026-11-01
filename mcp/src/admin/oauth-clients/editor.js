/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Compose and start the OAuth clients editor at its original matrix startup phase. */
(function (admin) {
	'use strict'
	admin.modules.oauth_clients = admin.modules.oauth_clients || {}
	/**
	 * @param {object} matrix matrix request, revision and presentation dependencies
	 * @return {void}
	 */
	admin.modules.oauth_clients.editor = function (matrix) {
		/** @return {Promise<void>} initial settings load and original listener registration */
		async function initOauthClients() {
			const context = { api: matrix.api, flash: matrix.flash, el: matrix.el,
				notifyError: matrix.notifyError, oneStep: matrix.oneStep, phpTrim: matrix.phpTrim,
				announce: matrix.announce, load: matrix.load }
			admin.sessions.oauthClients = context
			admin.modules.oauth_clients.session(context)
			admin.modules.oauth_clients.saving(context)
			admin.modules.oauth_clients.recovery(context)
			admin.modules.oauth_clients.cards(context)
			matrix.followRevision(() => context.oauthRevision, (revision) => { context.oauthRevision = revision })

			/** @param {object} data settings returned by the API */
			function show(data) {
				context.oauthRevision = data.revision
				context.confirmed = data
				context.hostsInput.value = data.hosts.join(', ')
				context.nativeBox.checked = data.nativeClientEnabled
				context.details.hidden = !data.nativeClientEnabled
				context.clientId.textContent = data.nativeClientId
				context.redirects.replaceChildren(...data.nativeRedirectUris.map((uri) => context.el('li', {}, [context.el('code', { textContent: uri })])))
				context.customClients = data.customClients
			}


			/**
			 * @param {HTMLElement} status status span next to the control
			 * @param {boolean} ok whether the save worked
			 * @param {string} [message] text shown on failure
			 */
			function report(status, ok, message) {
				status.textContent = ok ? t('mcp', 'Saved') : (message || t('mcp', 'Not saved'))
				context.flash(status, ok)
			}

			context.show = show
			context.report = report

			try {
				show(await context.api('GET', '/api/oauth-clients'))
			} catch (e) {
				context.notifyError(t('mcp', 'Could not load the OAuth client settings.'))
				return
			}
			context.customList.replaceChildren(...context.customClients.map(context.customCard))
			context.customAdd.addEventListener('click', () => {
				const card = context.customCard({ id: null, name: '', enabled: true, redirectUris: [] })
				context.customList.append(card)
				card.querySelector('input[type="text"]').focus()
			})

			context.hostsSave.addEventListener('click', async () => {
				const hosts = context.hostsInput.value.split(',').map((host) => host.trim().toLowerCase()).filter((host) => host !== '')
				context.hostsSave.disabled = true
				try {
					show(await context.put(() => ({ hosts })))
					report(context.hostsStatus, true)
				} catch (e) {
					if (!(await context.recover(e))) {
						report(context.hostsStatus, false, t('mcp', 'Invalid host list: use lowercase host names only, without https://, port, path or *, and keep at least one.'))
					}
				} finally {
					context.hostsSave.disabled = false
				}
			})

			context.nativeBox.addEventListener('change', async () => {
				const value = context.nativeBox.checked
				context.nativeBox.disabled = true
				try {
					show(await context.put(() => ({ nativeClientEnabled: value })))
					report(context.nativeStatus, true)
				} catch (e) {
					context.nativeBox.checked = !value
					if (!(await context.recover(e))) {
						report(context.nativeStatus, false)
						context.notifyError(t('mcp', 'Could not change the native client.'))
					}
				} finally {
					context.nativeBox.disabled = false
				}
			})		}
		matrix.initOauthClients = initOauthClients
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
