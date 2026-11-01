/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Group policy rendering operations.
 * @param {object} context live bindings of this editor
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.groups = admin.modules.groups || {}
	admin.modules.groups.rendering = function (context) {
		/**
		 * Draws the open group and focuses the control drawn under focusKey, so a change redraws without losing the place.
		 *
		 * @param {string|null} focusKey key of the control to focus, or null to leave the focus alone
		 */
		function render(focusKey = null) {
			const controls = new Map()
			const keyed = (key, node) => { controls.set(key, node); return node }
			const editable = !context.busy && context.current.trusted === true
			const name = context.shownName ?? context.current.groupId
			const heading = keyed('heading', context.el('h3', { tabIndex: -1, textContent: name }))
			heading.setAttribute('aria-describedby', 'mcp-group-alert mcp-group-diagnosis')
			const stats = context.current.counts
			const members = stats.members === null || stats.members === undefined
				? t('mcp', 'Unknown number of members')
				: n('mcp', '%n member', '%n members', stats.members)
			const head = context.el('div', { className: 'mcp-group-head' }, [heading])
			if (name !== context.current.groupId) head.append(context.el('span', { className: 'mcp-hint', textContent: t('mcp', 'ID: {id}', { id: context.current.groupId }, undefined, context.RAW) }))
			head.append(context.el('span', { className: 'mcp-hint', textContent: members }))
			const clear = context.el('button', { type: 'button', className: 'mcp-group-clear', textContent: t('mcp', 'Preview clearing group policy'), disabled: context.busy || context.grantKeys(context.current.grants).length === 0, onclick: () => {
				if (!context.discardDraft()) return
				context.draft = {}
				context.run('preview')
			} })
			head.append(clear)
			const confirm = context.el('button', { type: 'button', textContent: t('mcp', 'Confirm current policy'), disabled: !editable, onclick: () => {
				if (!context.discardDraft()) return
				context.draft = context.copyGrants(context.current.grants)
				context.run('preview')
			} })
			confirm.hidden = !['needs_confirm', 'pending_delete'].includes(context.current.state)
			// The group's notices live in its detail, under the title, with the action they ask for.
			const reason = context.diagnosis(context.current)
			const note = context.el('div', { className: 'mcp-note mcp-group-note', hidden: reason === '' }, [context.el('p', { id: 'mcp-group-diagnosis', textContent: reason }), confirm])

			const operationsSwitch = keyed('operations', context.el('input', { type: 'checkbox', id: 'mcp-group-operations', checked: context.advanced }))
			operationsSwitch.setAttribute('role', 'switch')
			operationsSwitch.addEventListener('change', () => { context.advanced = operationsSwitch.checked; context.render('operations') })
			const toolbar = context.el('div', { className: 'mcp-group-toolbar' }, [context.el('label', { htmlFor: 'mcp-group-operations' }, [operationsSwitch, t('mcp', 'Operations')])])

			const fields = context.el('fieldset', { disabled: !editable })
			fields.setAttribute('aria-describedby', 'mcp-group-diagnosis')
			fields.append(context.el('legend', { className: 'mcp-sr-only', textContent: t('mcp', 'Group grants') }))
			const eligible = keyed('eligible', context.el('input', { type: 'checkbox', name: 'mcp-group-eligible', id: 'mcp-group-eligible', checked: context.operationsIn(context.draft, '_system').includes('eligible') }))
			eligible.setAttribute('role', 'switch')
			eligible.addEventListener('change', () => context.setOperations('_system', eligible.checked ? ['eligible'] : [], 'eligible'))
			fields.append(context.el('div', { className: 'mcp-group-row' }, [context.el('label', { htmlFor: 'mcp-group-eligible', className: 'mcp-group-module' }, [t('mcp', 'Can connect')]), context.el('span', {}, [eligible]), context.el('span')]))
			for (const [module, operations] of context.modules()) {
				const label = context.el('span', { className: 'mcp-group-module', id: 'mcp-group-module-' + module, textContent: context.moduleName(module) })
				const granted = context.operationsIn(context.draft, module)
				const level = context.levelIn(context.draft, module)
				let control
				if (context.advanced) {
					control = context.el('span', { className: 'mcp-group-operations' }, operations.map((operation) => {
						const box = keyed('op:' + module + ':' + operation, context.el('input', { type: 'checkbox', name: 'mcp-group-op-' + module, value: operation, checked: granted.includes(operation) }))
						box.setAttribute('aria-label', context.moduleName(module) + ': ' + context.operationName(operation))
						box.addEventListener('change', () => {
							const values = new Set(granted)
							box.checked ? values.add(operation) : values.delete(operation)
							context.setOperations(module, [...values], 'op:' + module + ':' + operation)
						})
						return context.el('label', {}, [box, context.operationName(operation)])
					}))
				} else {
					control = context.el('span', { className: 'mcp-group-levels' }, window.OCA.Mcp.Admin.levels.levels(module, operations).map((choice) => keyed('level:' + module + ':' + choice,
						context.levelButton(module, choice, choice === level, () => context.setOperations(module, window.OCA.Mcp.Admin.levels.operationsFor(module, operations, choice), 'level:' + module + ':' + choice)))))
				}
				control.setAttribute('role', 'group')
				control.setAttribute('aria-labelledby', 'mcp-group-module-' + module)
				let hint = ''
				if (level === 'none') hint = t('mcp', 'Default: {level}', { level: context.defaultLevel(module) === 'none' ? t('mcp', 'None') : context.levelName(context.defaultLevel(module)) }, undefined, context.RAW)
				else if (level === 'custom' && !context.advanced) hint = t('mcp', 'Custom: change it in Operations')
				fields.append(context.el('div', { className: 'mcp-group-row' }, [label, control, context.el('span', { className: 'mcp-hint', textContent: hint })]))
			}

			// One sentence and a link instead of a table of counters: who wins over this group, and where to see them.
			const overrides = stats.overrides
			const exceptions = context.el('p', { className: 'mcp-group-exceptions' }, [overrides === null || overrides === undefined
				? t('mcp', 'The members with individual settings could not be counted.')
				: overrides === 0 ? t('mcp', 'No member has individual settings that take precedence over this group.')
					: n('mcp', '%n member has individual settings that take precedence over this group.', '%n members have individual settings that take precedence over this group.', overrides)])
			if (overrides !== 0) exceptions.append(context.el('button', { type: 'button', className: 'mcp-link-button', textContent: t('mcp', 'View in the matrix'), onclick: () => context.viewInMatrix(context.current.groupId, name) }))

			const parts = [head, note, ...context.UnknownGroupGrants.render(context.current, context.busy, (row) => context.UnknownGroupGrants.clean(context.current, row, {
				api: context.api, say: context.say, setBusy: (value) => { context.busy = value; context.root.setAttribute('aria-busy', String(value)); context.render() },
				refresh: async (gid) => { await context.open(gid, true, true) },
				saved: () => window.dispatchEvent(new Event('mcp-group-policy-saved')),
			})), toolbar, fields, exceptions]
			const save = keyed('save', context.el('button', { type: 'button', className: 'primary', textContent: t('mcp', 'Save group policy'), disabled: context.busy || !context.preview, onclick: () => context.run('save') }))
			const review = keyed('review', context.el('button', { type: 'button', className: 'primary', textContent: t('mcp', 'Review and save'), disabled: !editable, onclick: () => context.run('preview') }))
			for (const button of [review, confirm, clear, save]) button.setAttribute('aria-describedby', 'mcp-group-diagnosis')
			if (context.preview) {
				// The review of the preview the server returned: what changes, who is affected, and the save it allows.
				const region = context.el('div', { className: 'mcp-group-review' })
				region.setAttribute('role', 'region')
				region.setAttribute('aria-labelledby', 'mcp-group-review-title')
				const lines = context.changes(context.preview.current || context.current.grants, context.preview.grants || context.draft)
				region.append(context.el('h4', { id: 'mcp-group-review-title', textContent: t('mcp', 'Review the changes to {group}', { group: name }, undefined, context.RAW) }),
					lines.length ? context.el('ul', {}, lines.map((line) => context.el('li', { textContent: line }))) : context.el('p', { textContent: t('mcp', 'No change to the saved policy.') }),
					context.el('p', { textContent: t('mcp', 'Group grants apply to the live group, including future members. Individual overrides take precedence. Nextcloud permissions still apply.') }),
					context.el('p', { textContent: t('mcp', 'Preview: {members} current members; {revokes} credential holders will be signed out.', { members: context.count(context.preview.members), revokes: context.count(context.preview.revokes) }) }))
				if (context.preview.withdraws) region.append(context.el('p', { className: 'mcp-group-warning', textContent: t('mcp', 'Losing the last source of eligibility revokes connections. Restoring this policy does not reconnect users.') }))
				const back = context.el('button', { type: 'button', textContent: t('mcp', 'Back'), disabled: context.busy, onclick: () => { context.preview = null; context.render('review') } })
				region.append(context.el('div', { className: 'mcp-group-actions' }, [back, save]))
				parts.push(region)
			} else if (context.dirty()) {
				const lines = context.changes(context.current.grants, context.draft)
				const bar = context.el('div', { className: 'mcp-group-savebar' }, [
					context.el('div', { className: 'mcp-group-savebar-text' }, [context.el('strong', { textContent: n('mcp', '%n unsaved change', '%n unsaved changes', lines.length) }), context.el('span', { className: 'mcp-hint', textContent: lines.join('; ') })]),
					context.el('button', { type: 'button', textContent: t('mcp', 'Discard'), disabled: context.busy, onclick: () => { context.draft = context.copyGrants(context.current.grants); context.render('heading') } }),
					review,
				])
				parts.push(bar)
			}
			context.detail.replaceChildren(...parts)
			if (focusKey !== null) (controls.get(focusKey) || heading).focus()
		}

		Object.assign(context, { render })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
