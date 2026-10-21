/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Bind only the host APIs consumed by settings; importing this module reads none. */
export function createNextcloud(host = window) {
    return {
        t: (...args) => host.t(...args),
        n: (...args) => host.n(...args),
        generateUrl: path => host.OC.generateUrl(path),
        requestToken: () => host.OC.requestToken,
        fetch: (...args) => host.fetch(...args),
        notifyError(message, status) {
            if (host.OCP && host.OCP.Toast) host.OCP.Toast.error(message)
            else if (status) status.textContent = message
            else host.alert(message)
        },
    }
}
