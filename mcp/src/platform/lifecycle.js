// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
/** Own a mounted controller's listeners/timers, without cancelling accepted writes. */
export function createScope(root) {
    let disposed = false
    const cleanups = []
    const timers = new Set()
    const scope = {
        get disposed() { return disposed },
        guard(callback) {
            return (...args) => { if (!disposed) return callback(...args) }
        },
        use(cleanup) {
            if (disposed) cleanup()
            else cleanups.push(cleanup)
        },
        on(target, event, callback, options) {
            const guarded = scope.guard(callback)
            target.addEventListener(event, guarded, options)
            scope.use(() => target.removeEventListener(event, guarded, options))
        },
        timeout(callback, delay) {
            if (disposed) return null
            const id = setTimeout(() => { timers.delete(id); if (!disposed) callback() }, delay)
            timers.add(id)
            return id
        },
        clearTimeout(id) { clearTimeout(id); timers.delete(id) },
        request(request) {
            return async (...args) => {
                if (disposed) throw new Error('Controller disposed')
                const result = await request(...args)
                if (disposed) throw new Error('Controller disposed')
                return result
            }
        },
        destroy() {
            if (disposed) return
            disposed = true
            for (const id of timers) clearTimeout(id)
            timers.clear()
            for (const cleanup of cleanups.reverse()) cleanup()
            cleanups.length = 0
        },
    }
    // Restore only this owner's original controls so a new mount is not left locked.
    if (root) for (const control of root.querySelectorAll('input, button, select')) {
        const disabled = control.disabled
        scope.use(() => { control.disabled = disabled })
    }
    return scope
}
