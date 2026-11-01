/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Group policy saving operations.
 * @param {object} context live bindings of this editor
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.groups = admin.modules.groups || {}
	admin.modules.groups.saving = function (context) {
		async function run(action) {
			if (context.busy || !context.current || (action === 'save' && !context.preview)) return
			if (action === 'save' && window.OCA.Mcp.Admin.mailGrants && !window.OCA.Mcp.Admin.mailGrants.confirmWrites(Object.entries(context.draft)
				.flatMap(([module, operations]) => operations.filter((operation) => !(context.current.grants[module] || []).includes(operation)).map((operation) => ({ module, operation }))))) return
			context.busy = true
			const gid = context.current.groupId
			const body = { gid, grants: structuredClone(context.draft), expectedRevision: action === 'save' ? context.preview.revision : context.current.revision }
			if (action === 'save') body.previewId = context.preview.previewId
			context.render()
			context.root.setAttribute('aria-busy', 'true')
			let refresh = false
			try {
				const data = await context.api(action === 'save' ? 'PUT' : 'POST', '/api/group-grants' + (action === 'save' ? '' : '/preview'), body)
				if (action === 'preview') {
					context.preview = data
					context.say(t('mcp', 'Review the impact, then save the group policy.'))
				} else {
					context.preview = null
					refresh = true
					context.say(t('mcp', 'Saved'))
					window.dispatchEvent(new Event('mcp-group-policy-saved'))
				}
			} catch (e) {
				context.preview = null
				if (action === 'save' && e.message === 'unconfirmed') {
					refresh = true
					context.say(t('mcp', 'The change could not be confirmed. Your draft is kept. Retry after checking the group.'), true)
				} else if (e.message === '409' || e.message === '428') {
					// A fresh read pairs the revision with the stored policy. Never adopt an error revision or replay.
					refresh = true
					context.say(t('mcp', 'The policy or group members changed. Your draft is kept. Review a new preview before saving again.'), true)
				} else if (e.message === '503') {
					refresh = true
					context.say(t('mcp', 'The change could not be confirmed. Your draft is kept. Retry after checking the group.'), true)
				} else if (e.message === '422') {
					refresh = true
					context.say(context.untrusted(), true)
				} else {
					context.say(t('mcp', 'Could not save the group policy. Your draft is kept. Request a new preview and retry.'), true)
				}
			} finally {
				context.busy = false
				context.root.setAttribute('aria-busy', 'false')
				if (refresh) await context.open(gid, true, true)
				else context.render(action === 'preview' && context.preview ? 'save' : 'heading')
			}
		}

		Object.assign(context, { run })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
