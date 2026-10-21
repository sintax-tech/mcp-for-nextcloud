// SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
function phpTrim(text) {
    return text.replace(/^[ \t\n\r\x00\x0B]+|[ \t\n\r\x00\x0B]+$/g, '')
}

/** Match server normalization before announcing a revision to other page sections. */
export function changes(change, stored) {
    const control = (text) => /[\x00-\x1f\x7f]/.test(text)
    const uris = (list) => [...new Set(list.map(phpTrim))]
    if (change.nativeClientEnabled !== undefined && change.nativeClientEnabled !== stored.nativeClientEnabled) {
        return true
    }
    if (change.hosts !== undefined) {
        // strtolower() of PHP 8 changes A-Z only.
        const hosts = [...new Set(change.hosts.map((host) => phpTrim(host).replace(/[A-Z]/g, (letter) => letter.toLowerCase())))]
        if (hosts.some(control)) {
            return false
        }
        // Stored as no list when the request is the default set; only known while the default is what is stored.
        const isDefault = stored.hostsDefault && hosts.length === stored.hosts.length && hosts.every((host) => stored.hosts.includes(host))
        if (stored.hostsDefault ? !isDefault : hosts.join(',') !== stored.hosts.join(',')) {
            return true
        }
    }
    if (change.customClients !== undefined) {
        if (change.customClients.some((client) => control(phpTrim(client.name)) || uris(client.redirectUris).some(control))) {
            return false
        }
        const key = (clients) => JSON.stringify(clients.map((client) => [client.id ?? null, phpTrim(client.name), client.enabled, uris(client.redirectUris)]))
        if (key(change.customClients) !== key(stored.customClients)) {
            return true
        }
    }
    return false
}
