/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** inspector inspection operations.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	admin.modules.inspector = admin.modules.inspector || {}
	/**
	 * @param {object} context operation input
	 * @return {void} operation result
	 */
	admin.modules.inspector.inspection = function (context) {
		/**
		 * Asks what the individual values of the answer on screen repeat, then draws it again; an answer for another person
		 * or another inspection is dropped.
		 * @param {*} data operation input
		 * @param {*} mine operation input
		 * @return {Promise<*>} operation result
		 */
		async function classifyShown(data, mine) {
			const exceptions = window.OCA.Mcp.Admin.exceptions
			if (!exceptions?.classifyPerson || typeof data.revision !== 'string') return
			const cells = data.operations.map((row) => ({ module: row.module, operation: row.operation, allowed: row.grant?.allowed ?? null, source: row.grant?.source }))
			if (!cells.some((cell) => cell.source === 'individual_allow' || cell.source === 'individual_deny')) return
			try {
				const answer = await exceptions.classifyPerson(context.inspected, cells, data.revision)
				if (mine !== context.seq || context.shown !== data) return
				context.kinds = answer
			} catch (e) {
				return
			}
			// Drawn again in place: the table of every operation stays open, and a focused summary keeps the focus.
			const details = context.access.querySelector?.('details.mcp-inspector-all')
			const wasOpen = Boolean(details?.open)
			const hadFocus = document.activeElement !== undefined && document.activeElement === details?.querySelector?.('summary')
			context.renderAccess(data)
			const again = context.access.querySelector?.('details.mcp-inspector-all')
			if (again && wasOpen) again.open = true
			if (hadFocus) again?.querySelector?.('summary')?.focus?.()
		}


		/**
		 * @return {Promise<*>} operation result
		 */
		async function inspect() {
			const uid = context.user.value.trim()
			if (uid === '') {
				context.say(t('mcp', 'Enter the user ID to inspect.'), true)
				return
			}
			const mine = ++context.seq
			context.access.setAttribute('aria-busy', 'true')
			context.say(t('mcp', 'Inspecting…'))
			try {
				const data = await context.get('/api/inspector/access', { uid })
				if (mine !== context.seq) return
				context.inspected = uid
				context.kinds = null
				context.renderAccess(data)
				context.classifyShown(data, mine)
				context.resources.hidden = false
				context.pathResult.textContent = ''
				context.calendarResult.textContent = ''
				await context.loadCalendars()
				context.say(t('mcp', 'Access of {user} as the tools see it now.', { user: uid }))
			} catch (error) {
				if (mine !== context.seq) return
				context.inspected = null
				context.kinds = null
				context.shown = null
				context.access.replaceChildren()
				context.resources.hidden = true
				context.say(context.failure(error), true)
			} finally {
				if (mine === context.seq) context.access.setAttribute('aria-busy', 'false')
			}
		}


		/**
		 * @return {Promise<*>} operation result
		 */
		async function loadCalendars() {
			const module = context.calendarModule.value
			const mine = ++context.asked.calendars
			const user = context.inspected
			context.calendarOperation.replaceChildren(...(context.catalog[module] || ['read']).map((operation) => context.el('option', { value: operation, textContent: context.operationName(operation) })))
			context.calendarSelect.replaceChildren()
			try {
				const data = await context.get('/api/scope/' + encodeURIComponent(user) + '/' + module + '/candidates', {})
				if (context.stale('calendars', mine, user) || module !== context.calendarModule.value) return
				context.calendarSelect.replaceChildren(...data.items.map((item) => context.el('option', { value: item.id, textContent: item.name })))
				if (data.items.length === 0) context.calendarResult.textContent = t('mcp', 'This user has no calendar or task list of this kind in Nextcloud.')
			} catch (error) {
				if (context.stale('calendars', mine, user)) return
				context.say(context.failure(error), true)
			}
		}


		/**
		 * @param {*} kind operation input
		 * @param {*} target operation input
		 * @param {*} path operation input
		 * @param {*} query operation input
		 * @param {*} module operation input
		 * @return {Promise<*>} operation result
		 */
		async function explain(kind, target, path, query, module) {
			const mine = ++context.asked[kind]
			const user = context.inspected
			target.textContent = t('mcp', 'Checking…')
			try {
				const answer = await context.get(path, Object.assign({ uid: user }, query))
				if (context.stale(kind, mine, user)) return
				target.textContent = context.explanation(answer)
				if (answer.reason === 'out_of_scope' && window.OCA.Mcp.Admin.scope) {
					const change = context.el('button', { type: 'button', textContent: t('mcp', 'Open the resource scope') })
					change.addEventListener('click', () => window.OCA.Mcp.Admin.scope.open(user, module))
					target.append(' ', change)
				}
			} catch (error) {
				if (context.stale(kind, mine, user)) return
				// An error is announced like every other error of the inspector, in its alert region.
				target.textContent = ''
				context.say(context.failure(error), true)
			}
		}

		Object.assign(context, { classifyShown, inspect, loadCalendars, explain })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
