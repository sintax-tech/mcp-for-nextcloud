/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** OAuth client cards operations.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.oauthClients = admin.modules.oauthClients || {}
	/**
	 * @param {object} context operation input
	 * @return {void} operation result
	 */
	admin.modules.oauthClients.cards = function (context) {
		/**
		 * One self-hosted client: name, Enabled, client_id with Copy (once saved), redirect URIs, Save and Delete.
		 * Saving or deleting replaces only this card, so unsaved edits in the other cards stay.
		 *
		 * @param {object} client a stored client, or {id: null, ...} for a new one
		 * @return {HTMLElement} the card
		 */
		function customCard(client) {
			const n = ++context.cardCount
			const saved = client.id !== null
			const name = context.el('input', { type: 'text', id: 'mcp-custom-name-' + n, value: client.name, maxLength: 64, autocomplete: 'off', spellcheck: false })
			const enabled = context.el('input', { type: 'checkbox', className: 'checkbox', id: 'mcp-custom-enabled-' + n, checked: client.enabled })
			const uris = context.el('textarea', {
				id: 'mcp-custom-uris-' + n, className: 'mcp-custom-redirects', rows: 3, spellcheck: false, autocomplete: 'off',
				placeholder: 'https://harness.example.com/oauth/callback', value: client.redirectUris.join('\n'),
			})
			const status = context.el('span', { className: 'mcp-status' })
			status.setAttribute('aria-live', 'polite')
			const save = context.el('button', { type: 'button', textContent: t('mcp', 'Save') })
			const remove = context.el('button', { type: 'button', textContent: t('mcp', 'Delete') })
			const rows = [
				context.el('p', {}, [context.el('label', { htmlFor: name.id, textContent: t('mcp', 'Name') }), ' ', name]),
				context.el('p', {}, [enabled, context.el('label', { htmlFor: enabled.id, textContent: t('mcp', 'Enabled') })]),
			]
			if (saved) {
				const code = context.el('code', { textContent: client.clientId })
				const copy = context.el('button', { type: 'button', className: 'mcp-copy', textContent: t('mcp', 'Copy') })
				copy.addEventListener('click', () => {
					navigator.clipboard.writeText(client.clientId).then(
						() => { copy.textContent = t('mcp', 'Copied') },
						() => context.notifyError(t('mcp', 'Could not copy the client_id.')),
					)
				})
				rows.push(context.el('p', {}, ['client_id: ', code, ' ', copy]))
			}
			rows.push(
				context.el('p', {}, [context.el('label', { htmlFor: uris.id, textContent: t('mcp', 'Accepted redirect URIs') })]),
				uris,
				context.el('p', { className: 'settings-hint mcp-hint', textContent: t('mcp', 'One URI per line, exactly as the client sends it, port included: https (http only for 127.0.0.1, localhost or [::1]), no wildcard or fragment, up to 2000 bytes.') }),
				context.el('p', {}, [save, ' ', remove, ' ', status]),
			)
			const card = context.el('div', { className: 'mcp-custom-client' }, rows)

			/** @return {object} the client as edited in the card */
			function read() {
				return {
					id: client.id,
					name: name.value.trim(),
					enabled: enabled.checked,
					redirectUris: uris.value.split('\n').map((uri) => uri.trim()).filter((uri) => uri !== ''),
				}
			}

			/** @return {boolean} whether the card holds what the server does not have: any new card, or a changed name or URI list */
			function edited() {
				const entry = read()
				return !saved || entry.name !== client.name || entry.redirectUris.join('\n') !== client.redirectUris.join('\n')
			}
			context.drafts.set(card, { client, read, edited })

			save.addEventListener('click', async () => {
				const entry = read()
				save.disabled = true
				try {
					const added = await context.saveCustom((clients) => saved
						? clients.map((other) => other.id === client.id ? entry : other)
						: [...clients, entry])
					const next = context.customCard(saved ? context.customClients.find((other) => other.id === client.id) : added)
					card.replaceWith(next)
					next.querySelector('.mcp-status').textContent = t('mcp', 'Saved')
					context.flash(next.querySelector('.mcp-status'), true)
				} catch (e) {
					if (!(await context.recover(e))) {
						context.report(status, false, t('mcp', 'Not saved: each client needs a name of up to 64 characters that no other client has, and 1 to 20 redirect URIs (absolute https URIs, without user name, wildcard or fragment).'))
					}
				} finally {
					save.disabled = false
				}
			})

			// A saved client's Enabled setting applies at once, like the native client's; a new one's is saved with it.
			enabled.addEventListener('change', async () => {
				if (!saved) {
					return
				}
				const value = enabled.checked
				enabled.disabled = true
				try {
					await context.saveCustom((clients) => clients.map((other) => other.id === client.id ? { ...other, enabled: value } : other))
					context.report(status, true)
				} catch (e) {
					enabled.checked = !value
					if (!(await context.recover(e))) {
						context.report(status, false)
						context.notifyError(t('mcp', 'Could not change the self-hosted client.'))
					}
				} finally {
					enabled.disabled = false
				}
			})

			remove.addEventListener('click', async () => {
				if (!saved) {
					card.remove()
					return
				}
				if (!window.confirm(t('mcp', 'Delete the client “{name}”? Its connections are signed out at once.', { name: client.name }))) {
					return
				}
				remove.disabled = true
				try {
					await context.saveCustom((clients) => clients.filter((other) => other.id !== client.id))
					card.remove()
				} catch (e) {
					if (!(await context.recover(e))) {
						context.report(status, false)
						context.notifyError(t('mcp', 'Could not delete the self-hosted client.'))
					}
				} finally {
					remove.disabled = false
				}
			})
			return card
		}

		Object.assign(context, { customCard })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
