/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** level_popover planning operations.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	admin.modules.level_popover = admin.modules.level_popover || {}
	/**
	 * @param {object} context operation input
	 * @return {void} operation result
	 */
	admin.modules.level_popover.planning = function (context) {
		/**
		 * What each operation would be without the person's individual values: the preview's "after" for an individual cell,
		 * the value on screen for any other. null when the preview has not answered (or could not).
		 *
		 * @param {object} user row of the table
		 * @param {string|null} module module id, null for "Can connect"
		 * @param {Array<object>|null} cells the preview's cells, null without an answer
		 * @return {Object<string, {allowed: boolean|null, source: string, groupIds: string[]}>|null}
		 */
		function inheritedValues(user, module, cells) {
			if (cells === null) {
				return null
			}
			const out = {}
			for (const operation of context.operationsOf(module)) {
				const origin = context.originOfCell(user, module, operation)
				if (!context.INDIVIDUAL.includes(origin.source)) {
					out[operation] = { allowed: context.valueOf(user, module, operation), source: origin.source, groupIds: origin.groupIds || [] }
					continue
				}
				const answer = cells.find((cell) => cell.uid === user.uid && (cell.module ?? null) === module && cell.operation === operation)
				const after = answer?.after
				out[operation] = after ? { allowed: after.allowed ?? null, source: after.source, groupIds: after.groupIds || [] } : { allowed: null, source: 'unknown', groupIds: [] }
			}
			return out
		}


		/**
		 * The commands that give a set of target values: one per operation whose value changes. Taking an operation away is
		 * always an explicit deny. Giving one is "inherit" only when it is an individual value and the preview says the person
		 * inherits it allowed, otherwise an explicit allow: a later change of the inheritance can then only narrow the access.
		 *
		 * @param {object} user row of the table
		 * @param {string|null} module module id, null for "Can connect"
		 * @param {Object<string, boolean>} target value wanted per operation
		 * @param {object|null} inherited what inheritedValues() returned
		 * @return {Array<{uid: string, module: string|null, operation: string, state: string}>}
		 */
		function planTarget(user, module, target, inherited) {
			const commands = []
			for (const operation of context.operationsOf(module)) {
				const want = target[operation]
				if (want === undefined || context.valueOf(user, module, operation) === want) {
					continue
				}
				if (!want) {
					commands.push({ uid: user.uid, module, operation, state: 'deny' })
					continue
				}
				const back = inherited?.[operation]?.allowed === true && context.INDIVIDUAL.includes(context.originOfCell(user, module, operation).source)
				commands.push({ uid: user.uid, module, operation, state: back ? 'inherit' : 'allow' })
			}
			return commands
		}


		/** @return {Array<object>} the commands that give a module one level
		 * @param {*} user operation input
		 * @param {*} module operation input
		 * @param {*} level operation input
		 * @param {*} inherited operation input
		 */
		function planLevel(user, module, level, inherited) {
			const operations = context.operationsOf(module)
			const allowed = context.levels.operationsFor(module, operations, level)
			return context.planTarget(user, module, Object.fromEntries(operations.map((operation) => [operation, allowed.includes(operation)])), inherited)
		}


		/** @return {Array<object>} the commands that give every individual value of a module back to inheritance
		 * @param {*} user operation input
		 * @param {*} module operation input
		 */
		function planInherit(user, module) {
			return context.operationsOf(module)
				.filter((operation) => context.INDIVIDUAL.includes(context.originOfCell(user, module, operation).source))
				.map((operation) => ({ uid: user.uid, module, operation, state: 'inherit' }))
		}


		/** @return {Array<object>} the command that sets one operation to inherit, allow or deny, or none when it already is
		 * @param {*} user operation input
		 * @param {*} module operation input
		 * @param {*} operation operation input
		 * @param {*} state operation input
		 */
		function planOperation(user, module, operation, state) {
			const source = context.originOfCell(user, module, operation).source
			const now = source === 'individual_allow' ? 'allow' : source === 'individual_deny' || source === 'invalid' ? 'deny' : 'inherit'
			return now === state ? [] : [{ uid: user.uid, module, operation, state }]
		}


		/**
		 * Whether the commands take "Can connect" away from someone who has it now; an inherit whose result is unknown counts.
		 *
		 * @param {object} user row of the table
		 * @param {Array<object>} commands the commands to send
		 * @param {object|null} inherited what inheritedValues() returned for "Can connect"
		 * @return {boolean}
		 */
		function losesEligibility(user, commands, inherited) {
			if (user.eligible !== true) {
				return false
			}
			return commands.some((command) => command.module === null && (command.state === 'deny'
				|| (command.state === 'inherit' && inherited?.eligible?.allowed !== true)))
		}

		Object.assign(context, { inheritedValues, planTarget, planLevel, planInherit, planOperation, losesEligibility })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
