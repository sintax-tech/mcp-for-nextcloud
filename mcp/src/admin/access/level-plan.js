// SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import * as levels from './levels.js'
export const INDIVIDUAL = ['individual_allow', 'individual_deny', 'invalid']
export function createLevelPlan({ catalog }) {
	/** @return {string[]} the operations of a module, or ["eligible"] for "Can connect" (module null) */
	function operationsOf(module) {
		return module === null ? ['eligible'] : catalog()[module]
	}
	/** @return {boolean|null} the effective value of one operation */
	function valueOf(user, module, operation) {
		return module === null ? user.eligible : user.grants[module][operation]
	}
	/** @return {{source: string, groupIds: string[]}} the origin of one operation */
	function originOfCell(user, module, operation) {
		const origin = module === null ? user.origins?.eligible : user.origins?.grants?.[module]?.[operation]
		return origin || { source: 'default', groupIds: [] }
	}
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
		for (const operation of operationsOf(module)) {
			const origin = originOfCell(user, module, operation)
			if (!INDIVIDUAL.includes(origin.source)) {
				out[operation] = { allowed: valueOf(user, module, operation), source: origin.source, groupIds: origin.groupIds || [] }
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
		for (const operation of operationsOf(module)) {
			const want = target[operation]
			if (want === undefined || valueOf(user, module, operation) === want) {
				continue
			}
			if (!want) {
				commands.push({ uid: user.uid, module, operation, state: 'deny' })
				continue
			}
			const back = inherited?.[operation]?.allowed === true && INDIVIDUAL.includes(originOfCell(user, module, operation).source)
			commands.push({ uid: user.uid, module, operation, state: back ? 'inherit' : 'allow' })
		}
		return commands
	}
	/** @return {Array<object>} the commands that give a module one level */
	function planLevel(user, module, level, inherited) {
		const operations = operationsOf(module)
		const allowed = levels.operationsFor(module, operations, level)
		return planTarget(user, module, Object.fromEntries(operations.map((operation) => [operation, allowed.includes(operation)])), inherited)
	}
	/** @return {Array<object>} the commands that give every individual value of a module back to inheritance */
	function planInherit(user, module) {
		return operationsOf(module)
			.filter((operation) => INDIVIDUAL.includes(originOfCell(user, module, operation).source))
			.map((operation) => ({ uid: user.uid, module, operation, state: 'inherit' }))
	}
	/** @return {Array<object>} the command that sets one operation to inherit, allow or deny, or none when it already is */
	function planOperation(user, module, operation, state) {
		const source = originOfCell(user, module, operation).source
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
    return { operationsOf, valueOf, originOfCell, inheritedValues, planTarget, planLevel, planInherit, planOperation, losesEligibility }
}
