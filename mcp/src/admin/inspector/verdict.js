/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the inspector verdict unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	admin.modules.inspector = admin.modules.inspector || {}
	/** Attach general blocking verdicts and the matching administrator remedies.
	 * @param {object} context inspector controls, live request sequences and attached presentation operations
	 * @return {void}
	 */
	admin.modules.inspector.verdict = function (context) {
		const GENERAL = ['account', 'service', 'eligibility', 'connection']

		/**
		 * The general check that blocks every operation, or null when none does: every operation must be blocked by it.
		 * @param {Array<object>} operations the operations of the answer
		 * @return {string|null} a gate of GENERAL, or eligibility_unknown
		 */
		function generalBlock(operations) {
			const gates = new Set(operations.map((row) => row.blockingGate))
			if (operations.length === 0 || gates.size !== 1) return null
			const [gate] = gates
			return GENERAL.includes(gate) || gate === 'eligibility_unknown' ? gate : null
		}

		/** What to do about a general check that blocks, when the administrator or the person can do something.
		 * @param {string} gate effective access blocking gate code
		 * @return {string} translated remedy, or an empty string when none applies
		 */
		function remedy(gate) {
			return {
				service: t('mcp', 'Turn the service on in the Overview tab.'),
				eligibility: t('mcp', 'Turn on “Can connect” in the matrix or through a group.'),
				connection: t('mcp', 'They turn it on in their personal settings, under MCP for Cloud.'),
			}[gate] || ''
		}

		/**
		 * What the operations say once no general check blocks: the tools can be used when one operation is allowed, it is
		 * not confirmed when none is and one cannot be read, and nothing can be used when every one is blocked.
		 * @param {Array<object>} operations the operations of the answer, not empty
		 * @return {string} usable, unknown, none_granted (every one blocked by its grant) or none
		 */
		function outcome(operations) {
			if (operations.some((row) => row.allowed === true)) return 'usable'
			if (operations.some((row) => row.allowed !== false)) return 'unknown'
			return operations.every((row) => row.blockingGate === 'grant') ? 'none_granted' : 'none'
		}

		/** The verdict and the four general checks: passed, the one that blocks, or not checked after it.
		 * @param {object[]} operations effective access rows returned by the inspector
		 * @return {HTMLElement[]}
		 */
		function renderVerdict(operations) {
			// An answer without operations confirms nothing, not even the general checks.
			if (operations.length === 0) {
				return [context.el('p', { className: 'mcp-inspector-verdict', textContent: t('mcp', 'No operation was answered, so nothing can be said about {user}.', { user: context.inspected }, undefined, { escape: false }) })]
			}
			const block = context.generalBlock(operations)
			const failed = block === 'eligibility_unknown' ? 'eligibility' : block
			const index = failed === null ? GENERAL.length : GENERAL.indexOf(failed)
			const names = {
				account: t('mcp', 'Account active'),
				service: t('mcp', 'MCP service on'),
				eligibility: t('mcp', 'Can connect'),
				connection: t('mcp', 'Personal connection on'),
			}
			const checks = context.el('ul', { className: 'mcp-inspector-checks' }, GENERAL.map((gate, i) => {
				const state = i < index ? 'passed' : i > index ? 'skipped' : block === 'eligibility_unknown' ? 'unknown' : 'failed'
				const mark = { passed: '✓', failed: '✕', unknown: '?', skipped: '—' }[state]
				const word = { passed: t('mcp', 'passed'), failed: t('mcp', 'blocks'), unknown: t('mcp', 'not confirmed'), skipped: t('mcp', 'not checked') }[state]
				const symbol = context.el('span', { className: 'mcp-inspector-mark', textContent: mark })
				symbol.setAttribute('aria-hidden', 'true')
				return context.el('li', { className: 'mcp-inspector-check-' + state }, [symbol, ' ', names[gate] + ': ' + word])
			}))
			if (block === null) {
				const result = context.outcome(operations)
				if (result === 'usable') {
					return [context.el('p', { className: 'mcp-inspector-verdict mcp-inspector-allowed', textContent: t('mcp', '{user} can use the MCP tools allowed below: the general checks passed.', { user: context.inspected }, undefined, { escape: false }) }), checks]
				}
				const text = {
					unknown: t('mcp', 'Not confirmed: no operation of {user} is allowed for sure now, and some cannot be confirmed.', { user: context.inspected }, undefined, { escape: false }),
					none_granted: t('mcp', '{user} cannot use any MCP tool: nothing is granted.', { user: context.inspected }, undefined, { escape: false }),
					none: t('mcp', '{user} cannot use any MCP tool: every operation is blocked, as each module below says.', { user: context.inspected }, undefined, { escape: false }),
				}[result]
				const said = context.el('div', { className: 'mcp-note ' + (result === 'unknown' ? 'mcp-note-warning' : 'mcp-note-error') }, [context.el('p', { textContent: text })])
				said.setAttribute('role', 'status')
				return [said, checks]
			}
			const note = context.el('div', { className: 'mcp-note ' + (block === 'eligibility_unknown' ? 'mcp-note-warning' : 'mcp-note-error') }, [
				context.el('p', {}, [context.el('strong', { textContent: t('mcp', '{user} cannot use any MCP tool.', { user: context.inspected }, undefined, { escape: false }) }), ' ' + context.gateText(block) + (context.remedy(block) ? ' ' + context.remedy(block) : '')]),
			])
			note.setAttribute('role', 'status')
			return [note, checks]
		}

		Object.defineProperties(context, {
			GENERAL: { configurable: true, get: () => GENERAL },
		})

		Object.assign(context, { generalBlock, remedy, outcome, renderVerdict })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
