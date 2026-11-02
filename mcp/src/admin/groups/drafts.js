/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the groups drafts unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.groups = admin.modules.groups || {}
	/** Attach group level calculations, change summaries and draft operation editing.
	 * @param {object} context group directory controls, live policy draft and attached editor operations
	 * @return {void}
	 */
	admin.modules.groups.drafts = function (context) {
		/** Operations of a module in a grants object, whether it came as an object or as PHP's empty array. */
		const operationsIn = (grants, module) => (grants && !Array.isArray(grants) && grants[module]) || []
		/** The catalog's modules, without the "Can connect" key. */
		const modules = () => Object.entries(context.current.catalog).filter(([module]) => module !== '_system')
		/**
		 * The level a set of granted operations makes, with the matrix's tiers (src/admin/admin-levels.js). A group only grants, so an
		 * operation outside the set is "not granted", never denied.
		 * @param {Object<string, string[]>} grants granted operations indexed by module
		 * @param {string} module catalog module ID
		 * @return {string}
		 */
		function levelIn(grants, module) {
			const operations = context.current.catalog[module]
			const granted = operationsIn(grants, module)
			return window.OCA.Mcp.Admin.levels.levelOf(module, operations, Object.fromEntries(operations.map((operation) => [operation, granted.includes(operation)])))
		}
		/** What a member gets without any grant: GrantPolicy::defaultGranted(), reading except the server log.
		 * @param {string} module catalog module ID
		 * @return {string}
		 */
		function defaultLevel(module) {
			const operations = context.current.catalog[module]
			return window.OCA.Mcp.Admin.levels.levelOf(module, operations, Object.fromEntries(operations.map((operation) => [operation, operation === 'read' && module !== 'logs'])))
		}
		/** For a group the lowest level is "Not granted": the members keep what they have from elsewhere.
		 * @param {string} level permission level ID
		 * @return {string}
		 */
		function levelName(level) {
			return { ...window.OCA.Mcp.Admin.pollsGrants?.levelLabels(), forms_write: t('mcp', 'Read and write forms'), forms_write_results: t('mcp', 'Read and write forms and read responses'), none: t('mcp', 'Not granted'), read: t('mcp', 'Read'), edit: t('mcp', 'Edit'), full: t('mcp', 'Full'), custom: t('mcp', 'Custom') }[level] || level
		}
		/** A level in words, a custom one with its operations, since "Custom → Custom" would say nothing.
		 * @param {Object<string, string[]>} grants granted operations indexed by module
		 * @param {string} module catalog module ID
		 * @return {string}
		 */
		function levelText(grants, module) {
			const level = context.levelIn(grants, module)
			if (level !== 'custom') return context.levelName(level)
			return t('mcp', 'Custom ({operations})', { operations: context.current.catalog[module].filter((operation) => operationsIn(grants, module).includes(operation)).map(context.operationName).join(', ') }, undefined, context.RAW)
		}
		/** One line per changed module, "Files: Read → Edit", and one for "Can connect".
		 * @param {Object<string, string[]>} before previous module grants
		 * @param {Object<string, string[]>} after replacement module grants
		 * @return {string[]}
		 */
		function changes(before, after) {
			const lines = []
			const eligible = (grants) => operationsIn(grants, '_system').includes('eligible')
			if (eligible(before) !== eligible(after)) {
				lines.push(t('mcp', '{module}: {before} → {after}', { module: t('mcp', 'Can connect'), before: eligible(before) ? t('mcp', 'Yes') : t('mcp', 'No'), after: eligible(after) ? t('mcp', 'Yes') : t('mcp', 'No') }, undefined, context.RAW))
			}
			for (const [module] of modules()) {
				const was = [...operationsIn(before, module)].sort().join()
				const will = [...operationsIn(after, module)].sort().join()
				if (was !== will) lines.push(t('mcp', '{module}: {before} → {after}', { module: context.moduleName(module), before: context.levelText(before, module), after: context.levelText(after, module) }, undefined, context.RAW))
			}
			return lines
		}
		/** Replace a module's draft operations, invalidate its preview and redraw the editor.
		 * @param {string} module catalog module ID
		 * @param {string[]} operations catalog operation IDs
		 * @param {string|null|undefined} focusKey logical editor control to focus after redraw
		 * @return {void}
		 */
		function setOperations(module, operations, focusKey) {
			if (operations.length) context.draft[module] = [...operations].sort()
			else delete context.draft[module]
			context.preview = null
			context.render(focusKey)
		}
		/**
		 * The matrix, filtered by this group and by "With exceptions", for the members whose individual settings win over it.
		 * The matrix's group filter lists the groups it can filter by; a group it does not list (an external group, or one that
		 * no longer exists) is said so here, and the page does not move. The focus goes to the exceptions filter, a real
		 * control, which also tells a screen reader where it landed.
		 * @param {string} gid native Nextcloud group ID
		 * @param {string} name displayed group name
		 * @return {void}
		 */
		function viewInMatrix(gid, name) {
			const select = document.getElementById('mcp-group')
			const exceptionsOnly = document.getElementById('mcp-only-exceptions')
			if (!select || !Array.from(select.options).some((option) => option.value === gid)) {
				context.say(t('mcp', 'The matrix cannot show only the members of {group}: its group filter does not list this group.', { group: name }, undefined, context.RAW))
				return
			}
			select.value = gid
			select.dispatchEvent(new Event('change'))
			exceptionsOnly?.click()
			document.getElementById('mcp-block-matrix')?.scrollIntoView?.({ block: 'start' })
			exceptionsOnly?.focus()
		}
		/** A pressed level carries a check mark besides aria-pressed, as in the matrix popover, so it never depends on colour.
		 * @param {string} module catalog module ID
		 * @param {string} level permission level ID
		 * @param {boolean} pressed whether this choice is currently selected
		 * @param {function(MouseEvent): void} onclick handler invoked when the control is clicked
		 * @return {HTMLButtonElement}
		 */
		function levelButton(module, level, pressed, onclick) {
			const children = []
			if (pressed) {
				const mark = context.el('span', { className: 'mcp-pressed-mark', textContent: '✓' })
				mark.setAttribute('aria-hidden', 'true')
				children.push(mark)
			}
			children.push(context.levelName(level))
			const button = context.el('button', { type: 'button', name: 'mcp-group-level-' + module, value: level, className: 'mcp-group-level' + (level === 'none' ? ' mcp-group-level-none' : ''), onclick }, children)
			button.setAttribute('aria-pressed', String(pressed))
			return button
		}

		Object.defineProperties(context, {
			operationsIn: { configurable: true, get: () => operationsIn },
			modules: { configurable: true, get: () => modules },
		})

		Object.assign(context, { changes, defaultLevel, levelButton, levelIn, levelName, levelText, setOperations, viewInMatrix })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
