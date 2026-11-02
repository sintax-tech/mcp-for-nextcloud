/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Explicit diagnosis and cleanup of an unknown group grant.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.groups = admin.modules.groups || {}
	/** Expose unknown grant diagnosis, row refresh and explicit audited cleanup.
	 * @param {object} context group directory controls, live policy draft and attached editor operations
	 * @return {void}
	 */
	admin.modules.groups.unknownGrants = function (context) {
		const UnknownGroupGrants = class UnknownGroupGrants {
			/** Describe unknown grants in a group directory row, or return null when none exist.
			 * @returns {object|null} Textual warning for a directory row.
			 * @param {object} group directory group with identity, grants and policy diagnosis
			 */
			static warning(group) {
				return group.unknownGrants?.length ? { symbol: '?', text: t('mcp', 'Unknown grants: denied'), warning: true } : null
			}
			/** Merge freshly read policy diagnosis into the matching directory row.
			 * @returns {object[]} Fresh diagnosis replaces the open group's directory entry after readback.
			 * @param {object[]} rows directory or access rows in display order
			 * @param {object} group directory group with identity, grants and policy diagnosis
			 */
			static refreshRow(rows, group) {
				return rows.map((row) => row.groupId === group.groupId ? { ...row, ...group, unknownGrants: group.unknownGrants || [] } : row)
			}
			/** Build unknown grant diagnostics with an explicit cleanup button for each opaque row.
			 * @returns {HTMLElement[]} Accessible diagnostics with one explicit cleanup action per opaque row.
			 * @param {object} group directory group with identity, grants and policy diagnosis
			 * @param {boolean} busy whether a policy change currently locks the controls
			 * @param {function(object): Promise<void>} clean callback that clears the chosen unknown grant
			 */
			static render(group, busy, clean) {
				if (!group.unknownGrants?.length) return []
				const section = context.el('section', { className: 'mcp-note' })
				section.setAttribute('aria-label', t('mcp', 'Unknown group grants'))
				section.append(context.el('h4', { textContent: t('mcp', 'Unknown group grants') }),
					context.el('p', { textContent: t('mcp', 'These saved grants are unknown to this version and grant no access. Other permissions continue to apply. Cleanup is optional and is recorded.') }))
				for (const row of group.unknownGrants) {
					section.append(context.el('p', {}, [context.el('span', { textContent: row.module + '.' + row.operation + ' — ' + t('mcp', 'Stored value: Allow; effective: denied') }),
						context.el('button', { type: 'button', disabled: busy, textContent: t('mcp', 'Clear unknown grant'), onclick: () => clean(row) })]))
				}
				return [section]
			}
			/** Confirms the exact row and snapshot, never replays writes, and reloads after every attempt.
			 * @param {object} group directory group with identity, grants and policy diagnosis
			 * @param {object} row grant row returned by the API
			 * @param {{api: function(string, string, object): Promise<object>, setBusy: function(boolean): void,
			 *     refresh: function(string): Promise<void>, say: function(string, boolean=): void, saved: function(): void}} options cleanup dependencies
			 * @return {Promise<void>}
			 */
			static async clean(group, row, { api, setBusy, refresh, say, saved }) {
				if (!window.confirm(t('mcp', 'Clear 1 unknown grant from group {group}? Key: {key}. Stored value: Allow. This cleanup will be recorded.',
					{ group: group.groupId, key: row.module + '.' + row.operation }, undefined, { escape: false }))) return
				setBusy(true)
				try {
					const result = await api('DELETE', '/api/group-grants/unknown', { gid: group.groupId, ids: [row.id], grantSnapshot: group.grantSnapshot, expectedRevision: group.revision, confirm: true })
					if (!result.auditId || result.removed !== 1 || !result.report) throw new Error('unconfirmed')
					say(result.auditPending ? t('mcp', 'Unknown grant cleared and recorded. Audit log delivery is pending.') : t('mcp', 'Unknown grant cleared and recorded.'))
					saved()
				} catch (error) {
					say(error.message === '409' || error.message === '428'
						? t('mcp', 'The saved grants changed. Review the refreshed group before clearing again.')
						: t('mcp', 'The cleanup could not be confirmed. Check the refreshed group before trying again.'), true)
				} finally {
					setBusy(false)
					await refresh(group.groupId)
				}
			}
		}

		Object.defineProperties(context, {
			UnknownGroupGrants: { configurable: true, get: () => UnknownGroupGrants },
		})
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
