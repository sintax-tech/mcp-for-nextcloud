/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the groups session unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.groups = admin.modules.groups || {}
	/** Expose group controls, live directory and draft state, JSON requests and status feedback.
	 * @param {object} context group directory controls, live policy draft and attached editor operations
	 * @return {void}
	 */
	admin.modules.groups.session = function (context) {
		const root = context.root
		const search = document.getElementById('mcp-group-search')
		const list = document.getElementById('mcp-group-list')
		const detail = document.getElementById('mcp-group-detail')
		const status = document.getElementById('mcp-group-status')
		const alert = document.getElementById('mcp-group-alert')
		const prev = document.getElementById('mcp-group-prev')
		const next = document.getElementById('mcp-group-next')
		const counter = document.getElementById('mcp-group-count')
		const columns = document.getElementById('mcp-groups')
		const picker = document.getElementById('mcp-group-picker')
		const filters = { all: document.getElementById('mcp-group-filter-all'), granted: document.getElementById('mcp-group-filter-granted'), blocked: document.getElementById('mcp-group-filter-blocked') }
		let page = 1
		/** Filter of the list, sent to the server with the search and the page: all, granted or blocked. */
		let filter = 'all'
		/** Rows of the page on screen, kept so the open group can be marked without reading the list again. */
		let rows = []
		let listSeq = 0
		let detailSeq = 0
		let current = null
		/** Name of the open group as the list shows it; the detail API returns only the ID. */
		let shownName = null
		let draft = {}
		let preview = null
		/** Whether the detail shows each module's operations instead of its level (the "Operations" switch). */
		let advanced = false
		let busy = false
		let timer
		let sayTimer
		/** Milliseconds between clearing a live region and writing into it. */
		const SAY_DELAY = 60
		/** Groups per page of the directory API. */
		const PAGE_SIZE = 50
		/** Options of t() for text written with textContent, which escapes by itself. */
		const RAW = { escape: false }

		const copyGrants = (grants) => Object.fromEntries(Object.entries(grants).map(([module, ops]) => [module, [...ops]]))
		const unknown = () => t('mcp', 'Unknown count')
		const count = (value) => value === null || value === undefined ? unknown() : String(value)
		const untrusted = () => t('mcp', 'This group comes from a source that cannot guarantee group identity (for example LDAP). Grant MCP access to its members individually.')

		/** Create a DOM element, apply its properties and append its child nodes or text.
		 * @param {string} tag HTML element tag name
		 * @param {object} [props] DOM properties assigned to the element
		 * @param {Array<Node|string>} [children] child nodes and text appended in order
		 * @return {HTMLElement}
		 */
		function el(tag, props = {}, children = []) {
			const node = document.createElement(tag)
			Object.assign(node, props)
			node.append(...children)
			return node
		}

		/**
		 * Writes into the fixed live regions. Both are cleared at once and the message is written shortly after, in
		 * another task, so the same error twice in a row is announced again, and a message that was replaced or cleared
		 * in the meantime never appears.
		 * @param {string} message feedback text; an empty string clears the message
		 * @param {boolean} [error=false] announce through the alert region instead of the status region
		 * @return {void}
		 */
		function say(message, error = false) {
			clearTimeout(sayTimer)
			status.textContent = ''
			alert.textContent = ''
			if (message === '') return
			const region = error ? alert : status
			sayTimer = setTimeout(() => { region.textContent = message }, SAY_DELAY)
		}

		/** Fetch a JSON API response and reject unsuccessful requests with their response details.
		 * @param {string} method HTTP request method
		 * @param {string} path API path below /apps/mcp
		 * @param {object|undefined} body JSON request body when present
		 * @return {Promise<object>}
		 */
		async function api(method, path, body) {
			const response = await fetch(OC.generateUrl('/apps/mcp' + path), {
				method,
				headers: { 'Content-Type': 'application/json', Accept: 'application/json', requesttoken: OC.requestToken },
				body: body === undefined ? undefined : JSON.stringify(body),
			})
			// A proxy or expired session can return HTML with status 200. Only a readable JSON response confirms a save.
			const data = await response.json().catch((error) => {
				if (response.ok) throw Object.assign(new Error('unconfirmed'), { cause: error })
				return {}
			})
			if (!response.ok) throw Object.assign(new Error(String(response.status)), { data })
			return data
		}

		/** Resolve the translated module label, falling back to its catalog ID.
		 * @param {string} module catalog module ID
		 * @return {string}
		 */
		function moduleName(module) {
			return { files: t('mcp', 'Files'), notes: t('mcp', 'Notes'), deck: t('mcp', 'Deck'), calendar: t('mcp', 'Calendar'), contacts: t('mcp', 'Contacts'), tasks: t('mcp', 'Tasks'), talk: t('mcp', 'Talk'), people: t('mcp', 'People'), logs: t('mcp', 'Server log'), mail: t('mcp', 'Mail'), tables: t('mcp', 'Tables'), forms: t('mcp', 'Forms'), polls: t('mcp', 'Polls') }[module] || module
		}

		/** Resolve the translated operation label, falling back to its catalog ID.
		 * @param {string} operation catalog operation ID
		 * @return {string}
		 */
		function operationName(operation) {
			return { ...window.OCA.Mcp.Admin.pollsGrants?.operationLabels(), results: t('mcp', 'results'), read: t('mcp', 'read'), edit: t('mcp', 'edit'), create: t('mcp', 'create'), move: t('mcp', 'move'), delete: t('mcp', 'delete'), transfer: t('mcp', 'transfer'), reply: t('mcp', 'reply'), attach: t('mcp', 'attach'), quote: t('mcp', 'quote'), share: t('mcp', 'share'), link: t('mcp', 'link'), restore: t('mcp', 'restore'), messages: t('mcp', 'messages'), attachments: t('mcp', 'attachments'), flags: t('mcp', 'flags'), draft: t('mcp', 'draft'), send: t('mcp', 'send') }[operation] || operation
		}

		/** Flatten and sort granted module and operation pairs for stable draft comparison.
		 * @param {Object<string, string[]>} grants granted operations indexed by module
		 * @return {string[]}
		 */
		function grantKeys(grants) {
			return Object.entries(grants).flatMap(([module, operations]) => operations.map((operation) => module + ':' + operation)).sort()
		}

		/** Compare the current group draft with its saved grants; return null before a group is open.
		 * @return {boolean|null}
		 */
		function dirty() {
			return current && JSON.stringify(context.grantKeys(draft)) !== JSON.stringify(context.grantKeys(current.grants))
		}

		/** Ask before discarding unsaved group changes and report whether replacement may proceed.
		 * @return {boolean}
		 */
		function discardDraft() {
			return !context.dirty() || window.confirm(t('mcp', 'Discard the unsaved group changes?'))
		}

		/** Describe why a group cannot currently grant access or needs administrator confirmation.
		 * @param {object} group directory group with identity, grants and policy diagnosis
		 * @return {string}
		 */
		function diagnosis(group) {
			if (group.invalidId) return t('mcp', 'The directory returned an invalid group ID. This group cannot be edited.')
			if (group.trusted === null) return t('mcp', 'The group source is unavailable. Retry before editing.')
			if (group.exists === false) return t('mcp', 'This group no longer exists. Its saved policy does not grant access; you can clear it.')
			if (!group.trusted) return untrusted()
			if (group.state === 'needs_confirm') return t('mcp', 'This policy is awaiting confirmation and does not grant access. Confirming or saving will let it grant access again.')
			if (group.state === 'pending_delete') return t('mcp', 'Group deletion is pending. This policy does not grant access. Check the group before confirming it.')
			return ''
		}

		Object.defineProperties(context, {
			root: { configurable: true, get: () => root },
			search: { configurable: true, get: () => search },
			list: { configurable: true, get: () => list },
			detail: { configurable: true, get: () => detail },
			status: { configurable: true, get: () => status },
			alert: { configurable: true, get: () => alert },
			prev: { configurable: true, get: () => prev },
			next: { configurable: true, get: () => next },
			counter: { configurable: true, get: () => counter },
			columns: { configurable: true, get: () => columns },
			picker: { configurable: true, get: () => picker },
			filters: { configurable: true, get: () => filters },
			page: { configurable: true, get: () => page, set: (value) => { page = value } },
			filter: { configurable: true, get: () => filter, set: (value) => { filter = value } },
			rows: { configurable: true, get: () => rows, set: (value) => { rows = value } },
			listSeq: { configurable: true, get: () => listSeq, set: (value) => { listSeq = value } },
			detailSeq: { configurable: true, get: () => detailSeq, set: (value) => { detailSeq = value } },
			current: { configurable: true, get: () => current, set: (value) => { current = value } },
			shownName: { configurable: true, get: () => shownName, set: (value) => { shownName = value } },
			draft: { configurable: true, get: () => draft, set: (value) => { draft = value } },
			preview: { configurable: true, get: () => preview, set: (value) => { preview = value } },
			advanced: { configurable: true, get: () => advanced, set: (value) => { advanced = value } },
			busy: { configurable: true, get: () => busy, set: (value) => { busy = value } },
			timer: { configurable: true, get: () => timer, set: (value) => { timer = value } },
			sayTimer: { configurable: true, get: () => sayTimer, set: (value) => { sayTimer = value } },
			SAY_DELAY: { configurable: true, get: () => SAY_DELAY },
			PAGE_SIZE: { configurable: true, get: () => PAGE_SIZE },
			RAW: { configurable: true, get: () => RAW },
			copyGrants: { configurable: true, get: () => copyGrants },
			unknown: { configurable: true, get: () => unknown },
			count: { configurable: true, get: () => count },
			untrusted: { configurable: true, get: () => untrusted },
		})

		Object.assign(context, { api, diagnosis, dirty, discardDraft, el, grantKeys, moduleName, operationName, say })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
