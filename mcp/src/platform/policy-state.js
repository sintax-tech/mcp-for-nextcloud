// SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
const canonical = value => typeof value === 'string' && /^(0|[1-9][0-9]*)$/.test(value)

export function mustReloadPolicy(error) {
    return error.message === '409' || error.message === '428'
        || (error.message === '503' && error.data?.uncertain === true)
}

/** Page-local notifications of confirmed commits, never of revisions found in error bodies. */
export function createPolicyChannel() {
    const listeners = new Set()
    return {
        follow(listener) { listeners.add(listener); return () => listeners.delete(listener) },
        announce(from, to) {
            // A post-commit read may include another administrator's write; do not rebase other drafts onto it.
            if (!canonical(from) || !canonical(to) || BigInt(to) !== BigInt(from) + 1n) return
            for (const listener of listeners) listener(from, to)
        },
    }
}

/** One section's revision belongs to its rendered snapshot; late reads cannot replace a newer snapshot. */
export function createPolicyState(channel = createPolicyChannel()) {
    let revision = null
    let serial = 0
    const stop = channel.follow((from, to) => {
        if (revision === from) { revision = to; serial++ }
    })
    return {
        get revision() { return revision },
        read() { return ++serial },
        accept(ticket, value) {
            if (ticket !== serial) return false
            if (!canonical(value)) throw new Error('Invalid policy revision')
            revision = value
            return true
        },
        saved(from, to, { announce = true } = {}) {
            if (!canonical(from) || !canonical(to)) throw new Error('Invalid policy revision')
            if (revision !== from) return false
            serial++
            revision = to
            if (announce) channel.announce(from, to)
            return true
        },
        invalidate() { revision = null; serial++ },
        destroy() { stop(); serial++ },
    }
}
