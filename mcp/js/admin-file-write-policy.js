/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * File policy form, including independent protection alerts. No build step or inline handlers.
 * @param {Window|typeof globalThis} scope Browser globals, or node exports for tests.
 * @returns {void}
 */
(function (scope) {
	'use strict'

	/**
	 * Trim only the boundary bytes removed by PHP trim(), excluding form feed and Unicode spaces.
	 * @param {string} text Raw text or a separated token.
	 * @returns {string} Text with PHP's default ASCII boundary whitespace removed.
	 */
	function phpTrim(text) {
		return text.replace(/^[ \t\n\r\0\v]+|[ \t\n\r\0\v]+$/g, '')
	}

	/**
	 * Normalize the textarea with FileWritePolicy's ASCII grammar, PHP trim and non-UTF PCRE separators.
	 * @param {string} text Raw textarea input.
	 * @returns {string[]} Sorted, unique lowercase extensions.
	 * @throws {Error} For unsupported tokens, separators or more than 128 distinct extensions.
	 */
	function extensions(text) {
		if (/[^\x00-\x7f]/.test(text)) throw new Error('extensions')
		const trimmed = phpTrim(text)
		const tokens = trimmed === '' ? [] : trimmed.split(/[ \t\n\r\v\f]*(?:,|\r\n|[\n\r\v\f])[ \t\n\r\v\f]*/)
		if (tokens.at(-1) === '') tokens.pop()
		const canonical = tokens.map(token => phpTrim(token).toLowerCase().replace(/^\./, ''))
		if (canonical.some(token => !/^[a-z0-9][a-z0-9_-]{0,31}$/.test(token))) throw new Error('extensions')
		const result = [...new Set(canonical)].sort()
		if (result.length > 128) throw new Error('extensions')
		return result
	}

	/**
	 * Reject malformed responses rather than interpreting a failed load as off.
	 * @param {unknown} value Parsed JSON response.
	 * @returns {boolean} Whether it is a canonical policy.
	 */
	function validPolicy(value) {
		return !!value && value.version === 1 && ['off', 'allowlist'].includes(value.mode)
			&& typeof value.allowExtensionless === 'boolean' && Array.isArray(value.extensions)
			&& value.extensions.every(token => typeof token === 'string' && /^[a-z0-9][a-z0-9_-]{0,31}$/.test(token))
			&& value.extensions.length <= 128
			&& JSON.stringify([...new Set(value.extensions)].sort()) === JSON.stringify(value.extensions)
	}

	/**
	 * Bind one form using injectable browser primitives for request and DOM tests.
	 * @param {Document} document Admin page document.
	 * @param {typeof fetch} request Same-origin HTTP transport.
	 * @param {(app: string, text: string) => string} t Nextcloud translation function.
	 * @param {string} url Policy endpoint.
	 * @param {() => string} requestToken Read the current session CSRF token for each request.
	 * @param {typeof MutationObserver|undefined} Observer Shared protection-marker observer.
	 * @returns {{ready: Promise<void>, submit: (event: Event) => Promise<void>, aggregate: () => void}|null} Form hooks.
	 */
	function mount(document, request, t, url, requestToken, Observer) {
		/**
		 * @param {string} id Element identifier.
		 * @returns {HTMLElement|null} Matching form element.
		 */
		const get = id => document.getElementById(id)
		const form = get('mcp-file-policy-form')
		if (!form) return null
		const mode = get('mcp-file-policy-mode')
		const list = get('mcp-file-policy-extensions')
		const extensionless = get('mcp-file-policy-extensionless')
		const save = get('mcp-file-policy-save')
		const status = get('mcp-file-policy-status')
		let busy = true
		let issue = false

		/** @returns {void} Keep the shared marker visible while either protection configuration has an issue. */
		function aggregate() {
			const tags = get('mcp-tags-invalid-overview')
			const alert = get('mcp-tab-protection-alert')
			const hidden = !(issue || (tags && !tags.hidden))
			if (alert && alert.hidden !== hidden) alert.hidden = hidden
		}
		/**
		 * @param {boolean} value Whether loading or verification failed.
		 * @param {string} [message] Already translated, safe explanation.
		 * @returns {void}
		 */
		function markIssue(value, message) {
			issue = value
			get('mcp-file-policy-invalid').hidden = !value
			if (message) get('mcp-file-policy-invalid').textContent = message
			get('mcp-file-policy-overview').hidden = !value
			aggregate()
		}
		/** @returns {void} Synchronize busy state, editable controls and the empty-list warning. */
		function render() {
			form.setAttribute('aria-busy', String(busy))
			mode.disabled = busy
			list.disabled = busy || mode.value !== 'allowlist'
			extensionless.disabled = busy || mode.value !== 'allowlist'
			save.disabled = busy
			get('mcp-file-policy-empty').hidden = mode.value !== 'allowlist' || phpTrim(list.value) !== ''
		}
		/**
		 * @param {string} field Known policy field identifier.
		 * @param {string} message Translated error, or empty to clear it.
		 * @returns {void}
		 */
		function fieldError(field, message) {
			const control = { mode, extensions: list, allowExtensionless: extensionless }[field]
			const error = get('mcp-file-policy-' + field + '-error')
			if (!control || !error) return
			error.hidden = !message
			error.textContent = message || ''
			control.setAttribute('aria-invalid', String(!!message))
			if (message) control.focus()
		}
		const messages = {
			mode: t('mcp', 'Choose no restriction or only listed extensions.'),
			extensions: t('mcp', 'Enter valid extensions, separated by commas or new lines, without paths or wildcards.'),
			allowExtensionless: t('mcp', 'Choose whether files without an extension are allowed.'),
		}
		/**
		 * @param {string} method GET or PUT.
		 * @param {{mode: string, extensions: string[], allowExtensionless: boolean}} [body] Complete policy input.
		 * @returns {Promise<{version: number, mode: string, extensions: string[], allowExtensionless: boolean}>} Verified response.
		 * @throws {object|Error} On HTTP, JSON or policy validation failure.
		 */
		async function exchange(method, body) {
			const response = await request(url, {
				method,
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'Content-Type': 'application/json',
					requesttoken: requestToken(),
				},
				...(body ? { body: JSON.stringify(body) } : {}),
			})
			const data = await response.json()
			if (!response.ok || !validPolicy(data)) throw { status: response.status, data }
			return data
		}
		/**
		 * @param {{mode: string, extensions: string[], allowExtensionless: boolean}} policy Canonical server response.
		 * @returns {void}
		 */
		function fill(policy) {
			mode.value = policy.mode
			list.value = policy.extensions.join('\n')
			extensionless.checked = policy.allowExtensionless
		}
		/**
		 * @param {Event} event Form submission.
		 * @returns {Promise<void>} Settles after validation or the verified save; never clears input on failure.
		 */
		async function submit(event) {
			event.preventDefault()
			if (busy) return
			Object.keys(messages).forEach(field => fieldError(field, ''))
			status.textContent = ''
			if (!['off', 'allowlist'].includes(mode.value)) {
				fieldError('mode', messages.mode)
				return
			}
			const active = mode.value === 'allowlist'
			let parsed
			try { parsed = active ? extensions(list.value) : [] } catch (_) {
				fieldError('extensions', messages.extensions)
				return
			}
			busy = true
			render()
			status.textContent = t('mcp', 'Saving file type policy…')
			try {
				fill(await exchange('PUT', {
					mode: mode.value,
					extensions: parsed,
					allowExtensionless: active && extensionless.checked,
				}))
				markIssue(false)
				status.textContent = t('mcp', 'File type policy saved.')
			} catch (error) {
				if (error.status === 400 && messages[error.data?.field]) {
					fieldError(error.data.field, messages[error.data.field])
					status.textContent = ''
				} else {
					const message = t('mcp', 'The policy could not be saved and verified. Your input is preserved. Reload before relying on the saved state.')
					markIssue(true, message)
					status.textContent = message
				}
			} finally {
				busy = false
				render()
				for (const [field, control] of Object.entries({ mode, extensions: list, allowExtensionless: extensionless })) {
					if (!get('mcp-file-policy-' + field + '-error').hidden) { control.focus(); break }
				}
			}
		}
		form.addEventListener('submit', submit)
		mode.addEventListener('change', render)
		list.addEventListener('input', render)
		// The tag editor still owns its warning. Observe both its source and the shared marker so
		// late tag responses cannot clear a simultaneous policy warning, nor the reverse.
		if (Observer) {
			const observer = new Observer(aggregate)
			for (const id of ['mcp-tags-invalid-overview', 'mcp-tab-protection-alert']) {
				if (get(id)) observer.observe(get(id), { attributes: true, attributeFilter: ['hidden'] })
			}
		}
		render()
		const ready = exchange('GET').then(policy => {
			fill(policy)
			markIssue(false)
			status.textContent = ''
		}, () => {
			markIssue(true, t('mcp', 'The file type policy could not be loaded. Applicable MCP writes remain refused if the saved configuration is invalid. Choose a complete policy and save it to recover; no restriction is a deliberate choice.'))
			status.textContent = ''
		}).finally(() => { busy = false; render() })
		return { ready, submit, aggregate }
	}

	if (typeof module !== 'undefined' && module.exports) module.exports = { extensions, validPolicy, mount }
	if (scope.document) mount(scope.document, scope.fetch.bind(scope), scope.t,
		scope.OC.generateUrl('/apps/mcp/api/file-write-policy'), () => scope.OC.requestToken, scope.MutationObserver)
})(typeof window !== 'undefined' ? window : globalThis);

/** Backup settings editor. It runs after the file-type policy, in this same file, so the page does not load it apart. */
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
	if (typeof module !== 'undefined' && module.exports) module.exports.mountBackupSettings = mount
	if (scope.document) mount(scope.document, scope.fetch.bind(scope), scope.t, scope.OC.generateUrl('/apps/mcp/api/backup-settings'), () => scope.OC.requestToken)
})(typeof window !== 'undefined' ? window : globalThis)
