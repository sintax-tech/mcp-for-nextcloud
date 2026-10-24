/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Revisioned administrator backup editor. Transport and display values never become HTML. */
(function (scope) {
	'use strict'

	/**
	 * @param {Document} document Admin document.
	 * @param {typeof fetch} request Same-origin HTTP transport.
	 * @param {Function} t Nextcloud translator.
	 * @param {string} url Existing app settings API namespace.
	 * @param {Function} token Request-token provider.
	 * @returns {{ready: Promise<void>, reload: Function}|undefined} editor lifecycle
	 */
	function mount(document, request, t, url, token) {
		const get = id => document.getElementById('mcp-backup-' + id)
		const form = get('form')
		const events = scope.addEventListener ? scope : document
		if (!form) return
		const folder = get('folder'), copies = get('copies'), save = get('save'), reload = get('reload')
		let state = null, busy = false, stale = false
		/** @returns {void} Refresh controls without changing the administrator's draft. */
		function render() {
			form.setAttribute('aria-busy', String(busy))
			folder.disabled = busy || !state
			copies.disabled = busy || !state || (!state.versionsAvailable && copies.checked)
			save.disabled = busy || !state || stale
			reload.disabled = busy
		}
		/**
		 * @param {string} method HTTP verb.
		 * @param {Object|undefined} body Draft.
		 * @returns {Promise<Object>} Canonical backend response.
		 */
		async function exchange(method, body) {
			const response = await request(url, { method, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', requesttoken: token() }, ...(body ? { body: JSON.stringify(body) } : {}) })
			const data = await response.json()
			if (!response.ok) throw new Error(String(response.status))
			if (!data || typeof data.folder !== 'string' || typeof data.copies !== 'boolean' || typeof data.effectiveCopies !== 'boolean' || typeof data.versionsAvailable !== 'boolean' || !/^(0|[1-9][0-9]*)$/.test(data.revision)) throw new Error('invalid')
			return data
		}
		/**
		 * @param {Object} data Verified response.
		 * @returns {void} Fill saved settings and describe the current account's effective state.
		 */
		function fill(data) {
			state = data
			folder.value = data.folder
			copies.checked = data.copies
			stale = false
			get('effective').textContent = !data.versionsForActor
				? t('mcp', 'Copies are required for your account. Existing Files operations are unavailable without the Versions app.')
				: data.effectiveCopies ? t('mcp', 'MCP pre-write copies are currently required for your account.') : t('mcp', 'MCP pre-write copies are currently suspended for your account. Native versioning follows its own policy.')
		}
		/** @returns {Promise<void>} Reload saved state explicitly; a failed load never offers defaults to save. */
		async function load() {
			busy = true; render()
			try { fill(await exchange('GET')); get('status').textContent = '' }
			catch (_) { state = null; get('status').textContent = t('mcp', 'Backup settings could not be loaded. Reload before saving. No changes were made.') }
			finally { busy = false; render() }
		}
		form.addEventListener('submit', async event => {
			event.preventDefault()
			if (busy || !state || stale) return
			busy = true; render()
			const from = state.revision
			try {
				const data = await exchange('PUT', { folder: folder.value, copies: copies.checked, expectedRevision: from })
				fill(data)
				get('status').textContent = t('mcp', 'Backup settings saved.')
				if (scope.CustomEvent) events.dispatchEvent(new scope.CustomEvent('mcp-revision-moved', { detail: { from, to: data.revision } }))
			} catch (error) {
				stale = error.message !== '400'
				get('status').textContent = error.message === '400'
					? t('mcp', 'Use one valid Nextcloud folder name. Suspending copies requires the Versions app to be enabled.')
					: t('mcp', 'Backup settings could not be saved or changed elsewhere. Reload to verify the saved state before trying again.')
			} finally { busy = false; render() }
		})
		copies.addEventListener('change', render)
		reload.addEventListener('click', load)
		events.addEventListener('mcp-revision-moved', event => {
			if (state && !busy && event.detail?.from === state.revision && typeof event.detail.to === 'string') state.revision = event.detail.to
		})
		return { ready: load(), reload: load }
	}
	if (typeof module !== 'undefined' && module.exports) module.exports = { mount }
	if (scope.document) mount(scope.document, scope.fetch.bind(scope), scope.t, scope.OC.generateUrl('/apps/mcp/api/backup-settings'), () => scope.OC.requestToken)
})(typeof window !== 'undefined' ? window : globalThis)
