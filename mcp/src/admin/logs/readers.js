/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Original readers editor.
 * @param {object} context live bindings of this editor
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.logs = admin.modules.logs || {}
	admin.modules.logs.readers = function (context) {
		/**
		 * Server log section: the groups whose members may read the log besides the administrators, through
		 * GET and PUT /apps/mcp/api/logs-access. The picker locks while a save runs and each save carries
		 * the list it changed (`previous`), so the server answers 409 when another window or administrator
		 * saved first and a removed group is never put back. A listed group that was deleted shows as an
		 * option marked "(group removed)", to be removed.
		 */
		async function initLogsAccess() {
			const container = document.getElementById('mcp-logs-groups')
			const status = document.getElementById('mcp-logs-status')
			const summary = document.getElementById('mcp-logs-summary')
			if (!container || !window.OCA.Mcp.Admin.picker) {
				return
			}
			let version = 0
			// The block's own revision, from its own GET or save; never the matrix's, never an error body's.
			let logsRevision = null
			let picker = null
			context.followRevision(() => logsRevision, (revision) => { logsRevision = revision })

			/** @param {object} data state returned by the API */
			function show(data) {
				version = data.version
				logsRevision = data.revision
				const options = data.allGroups.map((group) => ({ id: group.id, label: group.displayName }))
				for (const id of data.missing) {
					options.push({ id: id, label: t('mcp', '{group} (group removed)', { group: id }, undefined, { escape: false }) })
				}
				if (!picker) {
					picker = window.OCA.Mcp.Admin.picker.create({
						container,
						status,
						options,
						selectedIds: data.groups,
						searchLabel: t('mcp', 'Search groups'),
						listLabel: t('mcp', 'Server log groups'),
						emptyOptionsText: t('mcp', 'No groups found; only administrators can read the log.'),
						noMatchText: t('mcp', 'No match.'),
						save: async (nextIds, previousIds) => {
							const sent = logsRevision
							// The server stores the groups as a set and commits only a set other than `previous` (LogsAccess).
							const committed = new Set(nextIds).size !== new Set(previousIds).size || nextIds.some((id) => !previousIds.includes(id))
							const data = await context.api('PUT', '/api/logs-access', { groups: nextIds, previous: previousIds, version: version, expectedRevision: sent })
							if (committed && context.oneStep(sent, data.revision)) {
								context.announce(sent, data.revision)
							}
							return data
						},
						onSaved: async (data) => {
							show(data)
							// The matrix shows the log column per user from the same gate, so it is reloaded.
							context.load()
						},
						onError: async (e) => {
							if (e.message === '409' || e.message === '428') {
								context.notifyError(t('mcp', 'The groups were changed in another window or by another administrator; the current list was loaded.'))
								context.load()
							} else {
								context.notifyError(t('mcp', 'Could not save the groups that may read the server log.'))
							}
							await reload()
						},
					})
				} else {
					picker.setOptions(options, data.groups)
				}
				paintSummary(data.groups, data.allGroups.map((group) => ({ id: group.id, label: group.displayName })))
			}

			/**
			 * The effective readers, as one line: groups are a gate on top of the per-user grant of the matrix.
			 *
			 * @param {string[]} groups the saved group ids
			 * @param {Array<{id: string, label: string}>} options the known groups, for their display names
			 */
			function paintSummary(groups, options) {
				if (!summary) {
					return
				}
				const readers = options.filter((option) => groups.includes(option.id))
				const names = readers.map((option) => option.label).join(', ')
				try {
					summary.textContent = readers.length === 0
						? t('mcp', 'Who may read the log: administrators only. Each of them also needs the “Server log” permission in the “Access and permissions” tab.')
						: n('mcp',
							'Who may read the log: administrators and the members of %n group ({names}). Each of them also needs the “Server log” permission in the “Access and permissions” tab.',
							'Who may read the log: administrators and the members of %n groups ({names}). Each of them also needs the “Server log” permission in the “Access and permissions” tab.',
							readers.length, { names }, { escape: false })
				} catch {
					// Optional explanatory text must never prevent managing the actual groups.
					summary.textContent = ''
				}
			}

			/** Loads the list again, after a conflict or a failure. */
			async function reload() {
				try {
					show(await context.api('GET', '/api/logs-access'))
				} catch (e) {
					if (picker) {
						picker.showFallback(t('mcp', 'Could not load the groups.'))
					} else {
						container.setAttribute('aria-busy', 'false')
						container.replaceChildren(context.el('p', { className: 'mcp-empty', textContent: t('mcp', 'Could not load the groups.') }))
					}
				}
			}

			await reload()
		}

		Object.assign(context, { initLogsAccess })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
