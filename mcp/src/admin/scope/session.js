/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Resource scope session operations.
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
	admin.modules.scope.session = function (context) {
		const MODULES = ['files', 'calendar', 'tasks']
		const UNDO_SECONDS = 10
		/** Delay before a message is written, so the same message twice in a row is announced twice (as in the matrix). */
		const SAY_DELAY = 60
		const root = document.getElementById('mcp-scope')
		const form = document.getElementById('mcp-scope-form')
		const userInput = document.getElementById('mcp-scope-user')
		const status = document.getElementById('mcp-scope-status')
		const alertBox = document.getElementById('mcp-scope-alert')
		const container = document.getElementById('mcp-scope-modules')
		const tabs = document.getElementById('mcp-scope-tabs')
		const drawerTitle = document.getElementById('mcp-scope-title')
		const closeButton = document.getElementById('mcp-scope-close')
		const entryButton = document.getElementById('mcp-scope-entry')
		/** The module whose tab is open, and the control the focus goes back to when the drawer closes. */
		let currentTab = 'files'
		let returnFocus = null
		let returnRow = null
		let uid = null
		let seq = 0
		let sayTimer = null
		/** @type {Object<string, object>} state of each module of the open user */
		let modules = {}
		/** Page event of a write this page committed: detail {from, to}; src/admin/matrix/start.js sends and follows it too. */
		const REVISION_MOVED = 'mcp-revision-moved'


		/**
		 * @param {*} tag operation input
		 * @param {*} props operation input
		 * @param {*} children operation input
		 * @return {*} operation result
		 */
		function el(tag, props = {}, children = []) {
			const node = document.createElement(tag)
			Object.assign(node, props)
			node.append(...children)
			return node
		}

		/**
		 * @param {*} message operation input
		 * @param {boolean} error operation input
		 * @return {*} operation result
		 */
		function say(message, error = false) {
			clearTimeout(sayTimer)
			status.textContent = ''
			alertBox.textContent = ''
			if (message === '') return
			const region = error ? alertBox : status
			sayTimer = setTimeout(() => { region.textContent = message }, SAY_DELAY)
		}

		/**
		 * @param {*} method operation input
		 * @param {*} path operation input
		 * @param {*} query operation input
		 * @param {*} body operation input
		 * @return {Promise<*>} operation result
		 */
		async function api(method, path, query, body) {
			const url = OC.generateUrl('/apps/mcp' + path) + (query ? '?' + new URLSearchParams(query) : '')
			const response = await fetch(url, {
				method,
				headers: { 'Content-Type': 'application/json', Accept: 'application/json', requesttoken: OC.requestToken },
				body: body === undefined ? undefined : JSON.stringify(body),
			})
			// A 2xx HTML response cannot confirm a save or undo; retain HTTP status handling for errors.
			const data = await response.json().catch((error) => {
				if (response.ok) throw Object.assign(new Error('unconfirmed'), { cause: error })
				return {}
			})
			if (!response.ok) throw Object.assign(new Error(String(response.status)), { status: response.status, data })
			return data
		}

		/**
		 * @param {*} module operation input
		 * @param {*} suffix operation input
		 * @return {*} operation result
		 */
		function scopePath(module, suffix = '') {
			return '/api/scope/' + encodeURIComponent(uid) + '/' + module + suffix
		}

		/**
		 * @param {*} module operation input
		 * @return {*} operation result
		 */
		function title(module) {
			return { files: t('mcp', 'Files: folders'), calendar: t('mcp', 'Calendar: calendars'), tasks: t('mcp', 'Tasks: task lists') }[module]
		}

		/**
		 * @param {*} module operation input
		 * @return {*} operation result
		 */
		function emptyText(module) {
			return {
				files: t('mcp', 'Nothing selected: the Files tools of this user reach no folder.'),
				calendar: t('mcp', 'Nothing selected: the Calendar tools of this user reach no calendar.'),
				tasks: t('mcp', 'Nothing selected: the Tasks tools of this user reach no task list.'),
			}[module]
		}

		/**
		 * @param {*} error operation input
		 * @return {*} operation result
		 */
		function failure(error) {
			if (error.status === 403) return t('mcp', 'Only administrators can change the resource scope.')
			if (error.status === 400) return t('mcp', 'Check the user ID and try again.')
			if (error.status === 503) return t('mcp', 'The MCP policy cannot be read right now. Nothing was changed. Try again shortly.')
			return t('mcp', 'The request failed. Try again.')
		}

		/** What a saved or selected item is called on screen: its path for a folder, its name otherwise.
		 * @param {*} item operation input
		 * @return {*} operation result
		 */
		function label(item) {
			if (item.found === false) return t('mcp', 'Resource no longer found ({id})', { id: item.id }, undefined, { escape: false })
			return item.path || item.name
		}

		/**
		 * @param {*} item operation input
		 * @return {*} operation result
		 */
		function badges(item) {
			const out = []
			if (item.found === false) out.push(context.el('span', { className: 'mcp-badge', textContent: t('mcp', 'not found: it has no effect') }))
			if (item.excluded) out.push(context.el('span', { className: 'mcp-badge', textContent: t('mcp', 'excluded by a tag: choosing it has no effect') }))
			if (item.inaccessible) out.push(context.el('span', { className: 'mcp-badge mcp-badge-error', textContent: t('mcp', 'the user cannot reach it in Nextcloud') }))
			return out
		}


		/**
		 * A message of one module: shown under it and announced through the page's live regions, which exist from the
		 * start (a region created with its text already in it is not reliably announced).
		 * @param {*} state operation input
		 * @param {*} message operation input
		 * @param {boolean} error operation input
		 * @return {*} operation result
		 */
		function note(state, message, error = false) {
			state.line = message
			if (message !== '') context.say(context.title(state.module) + ': ' + message, error)
		}


		/** @return {boolean} whether the draft differs from what is saved
		 * @param {*} state operation input
		 */
		function dirty(state) {
			const ids = (items) => items.map((item) => item.id).sort().join(',')
			return state.mode !== state.savedMode || (state.mode === 'restricted' && ids(state.items) !== ids(state.savedItems))
		}

		Object.defineProperties(context, {
			MODULES: { configurable: true, get: () => MODULES },
			UNDO_SECONDS: { configurable: true, get: () => UNDO_SECONDS },
			SAY_DELAY: { configurable: true, get: () => SAY_DELAY },
			root: { configurable: true, get: () => root },
			form: { configurable: true, get: () => form },
			userInput: { configurable: true, get: () => userInput },
			status: { configurable: true, get: () => status },
			alertBox: { configurable: true, get: () => alertBox },
			container: { configurable: true, get: () => container },
			tabs: { configurable: true, get: () => tabs },
			drawerTitle: { configurable: true, get: () => drawerTitle },
			closeButton: { configurable: true, get: () => closeButton },
			entryButton: { configurable: true, get: () => entryButton },
			currentTab: { configurable: true, get: () => currentTab, set: (value) => { currentTab = value } },
			returnFocus: { configurable: true, get: () => returnFocus, set: (value) => { returnFocus = value } },
			returnRow: { configurable: true, get: () => returnRow, set: (value) => { returnRow = value } },
			uid: { configurable: true, get: () => uid, set: (value) => { uid = value } },
			seq: { configurable: true, get: () => seq, set: (value) => { seq = value } },
			sayTimer: { configurable: true, get: () => sayTimer, set: (value) => { sayTimer = value } },
			modules: { configurable: true, get: () => modules, set: (value) => { modules = value } },
			REVISION_MOVED: { configurable: true, get: () => REVISION_MOVED },
		})

		Object.assign(context, { api, badges, dirty, el, emptyText, failure, label, note, say, scopePath, title })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
