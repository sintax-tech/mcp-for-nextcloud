// SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import * as Levels from './levels.js'
import { createPresentation } from './presentation.js'
import { createScope } from '../../platform/lifecycle.js'
import { planBulkChange } from './bulk-plan.js'
import { createUndoNotice } from './undo-notice.js'
export function createBulkChanges({ root, request, t, n, getData, unavailableReason, reload, onBusy, notifyError, policy, policyError }) {
	const scope = createScope()
	const api = scope.request(request)
    const { moduleLabels, levelLabels } = createPresentation({ t })
	let bulkBusy = false
	const notice = createUndoNotice({ root, t, isBusy: () => bulkBusy })
	const showNotice = notice.show
	function setBulkBusy(on) { bulkBusy = on; onBusy(on); notice.setBusy(on) }
    async function sendCommand(module, requests) {
        const commands = requests.flatMap(change => change.uids.map(uid => ({ uid, module, operation: change.operation, state: change.granted ? 'allow' : 'deny' })))
        const sent = policy.revision
        const saved = await api('POST', '/api/grants/commands', { expectedRevision: sent, commands }, { uncertainWrite: true })
        if (!policy.saved(sent, saved.revision)) throw new Error('Policy snapshot changed')
        return saved
    }
	async function bulk(module, level, uids) {
		// The plan is computed from the table on screen, so never from one that is being replaced.
		if (bulkBusy || policy.revision === null) {
			return
		}
		const operationList = module === null ? ['eligible'] : getData().catalog[module]
		const allowed = module === null ? (level === 'read' ? ['eligible'] : []) : Levels.operationsFor(module, operationList, level)
		const plan = planBulkChange({ users: getData().users, module, operations: operationList, granted: Object.fromEntries(operationList.map((operation) => [operation, allowed.includes(operation)])), uids, unavailableReason })
		const levelName = module === null ? (level === 'read' ? t('mcp', 'Yes') : t('mcp', 'No')) : levelLabels()[level] || level
		const vars = { permission: module === null ? t('mcp', 'Can connect') : moduleLabels()[module] || module, level: levelName }
		const lines = []
		if (plan.unavailable > 0) {
			lines.push(n('mcp', '%n selected person is left out: this permission is unavailable or unknown for them.', '%n selected people are left out: this permission is unavailable or unknown for them.', plan.unavailable, vars, { escape: false }))
		}
		if (plan.unchanged > 0) {
			lines.push(n('mcp', '%n selected person already has it.', '%n selected people already have it.', plan.unchanged, vars, { escape: false }))
		}
		if (plan.users === 0) {
			showNotice([t('mcp', 'Nothing to change for the selected people: “{permission}” is already {level}.', vars, undefined, { escape: false }), ...lines].join(' '), null)
			return
		}
		const question = n('mcp', 'Set “{permission}” to {level} for %n selected person?', 'Set “{permission}” to {level} for %n selected people?', plan.users, vars, { escape: false })
		if (module === null && level !== 'read') {
			lines.push(t('mcp', 'They are signed out of their MCP clients, and undoing this does not sign them back in.'))
		}
		if (!window.confirm([question, ...lines].join('\n'))) {
			return
		}
		const requests = plan.changes.map((change) => ({ operation: change.operation, uids: [...change.previous.keys()], granted: change.granted }))
		let undo = null
		setBulkBusy(true)
		try {
            const saved = await sendCommand(module, requests)
            if (scope.disposed) return
            undo = saved.receiptId === null ? null : () => undoBulk(saved.receiptId, saved.revision)
            showNotice(n('mcp', '“{permission}” set to {level} for %n person.', '“{permission}” set to {level} for %n people.', plan.users, vars, { escape: false }), undo)
            await reload()
        } catch (error) {
            if (scope.disposed) return
            showNotice(t('mcp', 'Could not update the selected people.'), null)
            if (!await policyError(error)) notifyError(t('mcp', 'Could not update the selected people.'))
		} finally {
			if (!scope.disposed) setBulkBusy(false)
		}
		if (!scope.disposed) notice.focusUndo(undo)
	}
    async function undoBulk(receiptId, savedRevision) {
        setBulkBusy(true)
        try {
            const result = await api('POST', '/api/grants/undo', { receiptId, expectedRevision: savedRevision }, { uncertainWrite: true })
            if (!policy.saved(savedRevision, result.revision)) throw new Error('Policy snapshot changed')
            showNotice(t('mcp', 'Change undone.'), null)
            await reload()
        } catch (error) {
            if (scope.disposed) return
            if (error.message === '503' && !error.data?.uncertain) {
                const message = t('mcp', 'The change could not be undone because what these users inherit could not be read. Nothing was changed. Try again.')
                notifyError(message)
                showNotice(message, () => undoBulk(receiptId, savedRevision))
                return
            }
            const message = error.message === '503' && error.data?.uncertain
                ? t('mcp', 'The server could not confirm whether the change was saved. The table was reloaded with the saved values.')
                : error.message === '410'
                ? t('mcp', 'This change can no longer be undone: the time to undo it has passed.')
                : t('mcp', 'Could not undo the change. The table was reloaded with the saved values.')
            showNotice(message, null)
            if (!await policyError(error)) { notifyError(message); await reload() }
        } finally { if (!scope.disposed) setBulkBusy(false) }
    }
	return { apply: scope.guard(bulk), focusUndo: notice.focusUndo,
        offerUndo(message, saved) { const undo = saved.receiptId === null ? null : () => undoBulk(saved.receiptId, saved.revision); showNotice(message, undo); return undo }, get busy() { return bulkBusy }, destroy() { scope.destroy(); notice.destroy() } }
}
