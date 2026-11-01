/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Individual override review operations.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.overrides = admin.modules.overrides || {}
	/**
	 * @param {object} context operation input
	 * @return {void} operation result
	 */
	admin.modules.overrides.review = function (context) {
		/**
		 * @param {{allowed: boolean|null, source: string, groupIds: string[]}|undefined} outcome the cell after the reset
		 * @return {string} what it will be, or "Unknown": a missing or null answer is never shown as a value
		 */
		function afterText(outcome) {
			if (!outcome || outcome.allowed === null || outcome.allowed === undefined) {
				return t('mcp', 'Unknown')
			}
			if (outcome.allowed) {
				return outcome.source === 'group'
					? t('mcp', 'Granted by groups: {groups}', { groups: outcome.groupIds.join(', ') }, undefined, { escape: false })
					: t('mcp', 'Allowed by default')
			}
			return t('mcp', 'Denied by default')
		}


		/**
		 * @param {{user: object, cells: object[]}} target one user of the plan
		 * @return {string} "Ana: Can connect: Individual override: allowed → Granted by groups: staff; Notes: edit: …"
		 */
		function describeTarget(target) {
			const names = {
				allow: t('mcp', 'Individual override: allowed'),
				deny: t('mcp', 'Individual override: denied'),
				invalid: t('mcp', 'Invalid stored override (denied)'),
			}
			const parts = target.cells.map((cell) => {
				const outcome = context.review.after?.get(context.afterKey(target.user.uid, cell.module, cell.operation))?.after
				return t('mcp', '{permission}: {before} → {after}', { permission: context.permissionName(cell.module, cell.operation), before: names[cell.kind], after: context.afterText(outcome) }, undefined, { escape: false })
			})
			return t('mcp', '{user}: {permissions}', { user: target.user.displayName, permissions: parts.join('; ') }, undefined, { escape: false })
		}


		/**
		 * What the server says about the results of a reset, once it was read.
		 *
		 * @param {object} current the open preview
		 * @return {{changed: number, same: number, unknown: number, lost: number, kept: number, lostUnknown: number}}
		 */
		function outcomes(current) {
			const out = { changed: 0, same: 0, unknown: 0, lost: 0, kept: 0, lostUnknown: 0 }
			for (const target of current.plan.targets) {
				for (const cell of target.cells) {
					const answer = current.after.get(context.afterKey(target.user.uid, cell.module, cell.operation))
					const after = answer?.after?.allowed ?? null
					const before = answer?.before?.allowed ?? null
					if (after === null || before === null) {
						out.unknown++
					} else if (after === before) {
						out.same++
					} else {
						out.changed++
					}
					if (cell.module === null && cell.kind === 'allow') {
						// An individual Allow of "Can connect": what the user is left with decides whether the connections stay.
						after === null ? out.lostUnknown++ : after ? out.kept++ : out.lost++
					}
				}
			}
			return out
		}


		/** Draws the preview: the exact cells, what each has before and after, what may change and the buttons.
		 * @return {*} operation result
		 */
		function renderReview() {
			const result = context.review.plan
			// The preview is drawn again when the server's answer arrives: the focus stays on what had it.
			const focused = ['h3', 'button.mcp-reset-confirm', 'button.mcp-reset-cancel', 'button.mcp-reset-reload'].find((selector) => {
				const node = context.panel.querySelector(selector)
				return node !== null && document.activeElement === node
			})
			const heading = context.el('h3', {
				tabIndex: -1,
				textContent: n('mcp', 'Back to group inheritance for %n user', 'Back to group inheritance for %n users', result.users, undefined, { escape: false }),
			})
			const lines = [
				context.el('p', { textContent: t('mcp', 'Overrides to remove: {allow} allowed, {deny} denied, {invalid} invalid.', { allow: result.allow, deny: result.deny, invalid: result.invalid }, undefined, { escape: false }) }),
			]
			const stale = context.outdated(context.review)
			if (stale) {
				lines.push(context.el('p', { className: 'mcp-reset-warning', textContent: t('mcp', 'The table is older than the preview: the permissions changed after it was loaded. Reload the table to review the reset again.') }))
			}
			const known = context.review.after !== null
			const counts = known ? context.outcomes(context.review) : null
			if (context.review.loading) {
				lines.push(context.el('p', { textContent: t('mcp', 'Checking what these users inherit…') }))
			} else if (context.review.unavailable) {
				lines.push(context.el('p', { textContent: t('mcp', 'The result after the reset could not be read, so it is shown as unknown.') }))
			}
			lines.push(context.el('p', { textContent: known
				? t('mcp', 'Results that change: {changed}. Unchanged: {same}. Unknown: {unknown}.', counts, undefined, { escape: false })
				: t('mcp', 'Each removed override is replaced by what the groups and defaults give, so some permissions may change.') }))
			if (known) {
				if (counts.lost > 0) {
					lines.push(context.el('p', { className: 'mcp-reset-warning', textContent: n('mcp',
						'%n user loses “Can connect” and the access it gives. Their MCP connections are revoked, and undoing this does not sign them back in.',
						'%n users lose “Can connect” and the access it gives. Their MCP connections are revoked, and undoing this does not sign them back in.',
						counts.lost, undefined, { escape: false }) }))
				}
				if (counts.kept > 0) {
					lines.push(context.el('p', { textContent: n('mcp', '%n user keeps “Can connect” through a group, so nobody is signed out for it.', '%n users keep “Can connect” through a group, so nobody is signed out for it.', counts.kept, undefined, { escape: false }) }))
				}
				if (counts.lostUnknown > 0) {
					lines.push(context.el('p', { className: 'mcp-reset-warning', textContent: n('mcp',
						'The “Can connect” of %n user after the reset is unknown. The reset is refused until the groups can be read.',
						'The “Can connect” of %n users after the reset is unknown. The reset is refused until the groups can be read.',
						counts.lostUnknown, undefined, { escape: false }) }))
				}
			} else if (result.eligibleAllow > 0) {
				// The server's answer is missing: never "will", a group or another rule may keep the user eligible.
				lines.push(context.el('p', { className: 'mcp-reset-warning', textContent: n('mcp',
					'%n user loses an individual Allow for “Can connect”. Unless a group or another rule keeps them eligible, they may be signed out of their MCP clients, and undoing this does not sign them back in.',
					'%n users lose an individual Allow for “Can connect”. Unless a group or another rule keeps them eligible, they may be signed out of their MCP clients, and undoing this does not sign them back in.',
					result.eligibleAllow, undefined, { escape: false }) }))
			}
			if (result.eligibleDeny > 0) {
				lines.push(context.el('p', { textContent: t('mcp', 'Removing an individual Deny for “Can connect” lets the groups decide again, which may give access.') }))
			}
			if (result.unseen > 0) {
				lines.push(context.el('p', { textContent: n('mcp',
					'%n of these values is in a permission the table does not show for that user. It is removed too.',
					'%n of these values are in permissions the table does not show for those users. They are removed too.',
					result.unseen, undefined, { escape: false }) }))
			}
			if (result.without > 0) {
				lines.push(context.el('p', { textContent: n('mcp', '%n selected user has no individual overrides and is left out.', '%n selected users have no individual overrides and are left out.', result.without, undefined, { escape: false }) }))
			}
			const list = context.el('ul', { className: 'mcp-reset-targets', tabIndex: 0 }, result.targets.map((target) => context.el('li', { textContent: context.describeTarget(target) })))
			// The list scrolls when many users are selected, so it is a named, focusable region the keyboard can reach.
			list.setAttribute('aria-label', t('mcp', 'Individual overrides'))
			const confirm = context.el('button', {
				type: 'button',
				className: 'primary mcp-reset-confirm',
				textContent: context.review.retry ? t('mcp', 'Try again') : t('mcp', 'Remove overrides'),
				disabled: context.review.loading || stale,
				onclick: context.execute,
			})
			const cancel = context.el('button', { type: 'button', className: 'mcp-reset-cancel', textContent: t('mcp', 'Cancel'), onclick: () => context.close(true) })
			const actions = [confirm, ' ', cancel]
			if (stale) {
				actions.push(' ', context.el('button', { type: 'button', className: 'mcp-reset-reload', textContent: t('mcp', 'Reload the table'), onclick: () => context.matrix.reload() }))
			}
			context.panel.replaceChildren(heading, ...lines, list, context.el('div', { className: 'mcp-reset-actions' }, actions))
			if (focused) {
				// A button that was just disabled cannot take the focus: the reload button, which now matters most, does.
				const target = context.panel.querySelector(focused)
				;(target.disabled ? context.panel.querySelector('button.mcp-reset-reload') || target : target).focus()
			}
		}

		Object.assign(context, { afterText, describeTarget, outcomes, renderReview })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
