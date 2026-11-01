/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** file-write-policy/editor operations.
 * @param {object} scope browser primitives
 * @param {object} admin app registry
 * @return {void} registers and starts the original editor
 */
(function (scope, admin) {
	'use strict'

	const { phpTrim, extensions, validPolicy } = admin.fileWritePolicy

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


	admin.fileWritePolicy.mount = mount
	if (scope.document) mount(scope.document, scope.fetch.bind(scope), scope.t,
		scope.OC.generateUrl('/apps/mcp/api/file-write-policy'), () => scope.OC.requestToken, scope.MutationObserver)
})((globalThis.window || globalThis), (globalThis.window || globalThis).OCA.Mcp.Admin)
