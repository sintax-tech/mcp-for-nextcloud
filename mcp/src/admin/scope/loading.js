/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the scope loading unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.scope = admin.modules.scope || {}
	/** Attach scope reads and revision-aware reloads that retain unrelated unsaved drafts.
	 * @param {object} context scope drawer controls, live module drafts and attached resource operations
	 * @return {void}
	 */
	admin.modules.scope.loading = function (context) {
		/** Load every scope module for a user and discard answers superseded by another user selection.
		 * @param {string} target Nextcloud user ID whose scope is read
		 * @param {string|null|undefined} focusModule resource module whose heading should receive focus
		 * @return {Promise<void>}
		 */
		async function openUser(target, focusModule) {
			const mine = ++context.seq
			context.uid = target
			context.modules = {}
			context.currentTab = focusModule || context.MODULES[0]
			context.tabs?.replaceChildren()
			context.container.replaceChildren()
			context.container.setAttribute('aria-busy', 'true')
			context.say(t('mcp', 'Loading the resource scope…'))
			try {
				const loaded = await Promise.all(context.MODULES.map((module) => context.api('GET', context.scopePath(module))))
				if (mine !== context.seq) return
				loaded.forEach((data) => {
					context.modules[data.module] = {
						module: data.module, active: data.active, limit: data.limit, revision: data.revision,
						mode: data.mode, savedMode: data.mode, items: [...data.items], savedItems: [...data.items],
						search: '', parents: [], results: null, page: 1, undo: null, saving: false, stale: false,
					}
				})
				context.MODULES.forEach((module) => context.container.append(context.renderModule(context.modules[module])))
				context.renderTabs()
				context.say(t('mcp', 'Resource scope of {user}.', { user: context.uid }, undefined, { escape: false }))
				const heading = document.getElementById('mcp-scope-heading-' + (focusModule || context.MODULES[0]))
				if (focusModule && heading) {
					heading.scrollIntoView?.({ block: 'start' })
					heading.focus?.()
				}
			} catch (error) {
				if (mine !== context.seq) return
				context.uid = null
				context.say(context.failure(error), true)
			} finally {
				if (mine === context.seq) context.container.setAttribute('aria-busy', 'false')
			}
		}

		/** Reads one module again from the server; its draft is replaced by the saved scope and its revision.
		 * @param {object} state live scope module draft, saved values, revision and persistence state
		 * @return {Promise<void>}
		 */
		async function reload(state) {
			const data = await context.api('GET', context.scopePath(state.module))
			Object.assign(state, { active: data.active, revision: data.revision, stale: false,
				mode: data.mode, savedMode: data.mode, items: [...data.items], savedItems: [...data.items] })
			context.rerender(state)
		}

		/**
		 * The policy revision moved (a save, an undo, a conflict): the three modules are read again. A module with an
		 * unsaved draft keeps it. When it already took the revision this page's own write made (`current`), the draft is
		 * still valid and stays as it is. Otherwise it keeps the revision it was read at, and says so: saving it is refused
		 * by the server (409) rather than accepted against a scope it never saw, and discarding it loads the saved scope.
		 * @param {object} origin the module that saved, undid or was refused
		 * @param {string} [current] revision made by this page's own write, when it committed
		 * @return {Promise<void>}
		 */
		async function reloadAll(origin, current) {
			for (const module of context.MODULES) {
				const state = context.modules[module]
				if (state === origin || !context.dirty(state)) {
					await context.reload(state).catch(() => {})
				} else if (current === undefined || state.revision !== current) {
					state.stale = true
					state.line = t('mcp', 'Changed before the policy moved, so saving will be refused. Discard the changes to load the saved scope.')
					context.rerender(state)
				}
			}
		}

		Object.assign(context, { openUser, reload, reloadAll })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
