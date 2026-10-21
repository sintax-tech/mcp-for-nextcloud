// SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
function el(tag, props, children = []) {
    const node = Object.assign(document.createElement(tag), props || {})
    node.append(...children)
    return node
}

export function createClientCards({ scope, t, notifyError, report, flash, saveCustom, getClients, recover }) {
    let cardCount = 0
    const drafts = new WeakMap()
    function keepUnsaved(unsaved) {
        const kept = new Map(unsaved.filter((card) => drafts.get(card).client.id !== null).map((card) => [drafts.get(card).client.id, card]))
        const cards = getClients().map((client) => kept.get(client.id) || customCard(client))
        const extra = unsaved.filter((card) => !cards.includes(card)).map((card) => {
            if (drafts.get(card).client.id === null) {
                return card
            }
            const fresh = customCard({ ...drafts.get(card).read(), id: null })
            report(fresh.querySelector('.mcp-status'), false, t('mcp', 'Not saved: this client was deleted in the meantime. Your changes are kept here as a new client; saving adds it again.'))
            return fresh
        })
        for (const card of unsaved.filter((card) => cards.includes(card) || extra.includes(card))) {
            report(card.querySelector('.mcp-status'), false, t('mcp', 'Not saved: the settings changed after this page was loaded. Your changes are kept here; check them and save again.'))
        }
        return [...cards, ...extra]
    }

    function customCard(client) {
        const n = ++cardCount
        const saved = client.id !== null
        const name = el('input', { type: 'text', id: 'mcp-custom-name-' + n, value: client.name, maxLength: 64, autocomplete: 'off', spellcheck: false })
        const enabled = el('input', { type: 'checkbox', className: 'checkbox', id: 'mcp-custom-enabled-' + n, checked: client.enabled })
        const uris = el('textarea', {
            id: 'mcp-custom-uris-' + n, className: 'mcp-custom-redirects', rows: 3, spellcheck: false, autocomplete: 'off',
            placeholder: 'https://harness.example.com/oauth/callback', value: client.redirectUris.join('\n'),
        })
        const status = el('span', { className: 'mcp-status' })
        status.setAttribute('aria-live', 'polite')
        const save = el('button', { type: 'button', textContent: t('mcp', 'Save') })
        const remove = el('button', { type: 'button', textContent: t('mcp', 'Delete') })
        const rows = [
            el('p', {}, [el('label', { htmlFor: name.id, textContent: t('mcp', 'Name') }), ' ', name]),
            el('p', {}, [enabled, el('label', { htmlFor: enabled.id, textContent: t('mcp', 'Enabled') })]),
        ]
        if (saved) {
            const code = el('code', { textContent: client.clientId })
            const copy = el('button', { type: 'button', className: 'mcp-copy', textContent: t('mcp', 'Copy') })
            scope.on(copy, 'click', () => {
                navigator.clipboard.writeText(client.clientId).then(
                    scope.guard(() => { copy.textContent = t('mcp', 'Copied') }),
                    scope.guard(() => notifyError(t('mcp', 'Could not copy the client_id.'))),
                )
            })
            rows.push(el('p', {}, ['client_id: ', code, ' ', copy]))
        }
        rows.push(
            el('p', {}, [el('label', { htmlFor: uris.id, textContent: t('mcp', 'Accepted redirect URIs') })]),
            uris,
            el('p', { className: 'settings-hint mcp-hint', textContent: t('mcp', 'One URI per line, exactly as the client sends it, port included: https (http only for 127.0.0.1, localhost or [::1]), no wildcard or fragment, up to 2000 bytes.') }),
            el('p', {}, [save, ' ', remove, ' ', status]),
        )
        const card = el('div', { className: 'mcp-custom-client' }, rows)

        /** @return {object} the client as edited in the card */
        function read() {
            return {
                id: client.id,
                name: name.value.trim(),
                enabled: enabled.checked,
                redirectUris: uris.value.split('\n').map((uri) => uri.trim()).filter((uri) => uri !== ''),
            }
        }

        /** @return {boolean} whether the card holds what the server does not have: any new card, or a changed name or URI list */
        function edited() {
            const entry = read()
            return !saved || entry.name !== client.name || entry.redirectUris.join('\n') !== client.redirectUris.join('\n')
        }
        drafts.set(card, { client, read, edited })

        scope.on(save, 'click', async () => {
            const entry = read()
            save.disabled = true
            try {
                const added = await saveCustom((clients) => saved
                    ? clients.map((other) => other.id === client.id ? entry : other)
                    : [...clients, entry])
                const next = customCard(saved ? getClients().find((other) => other.id === client.id) : added)
                card.replaceWith(next)
                next.querySelector('.mcp-status').textContent = t('mcp', 'Saved')
                flash(next.querySelector('.mcp-status'), true)
            } catch (e) {
                    if (scope.disposed) return
                if (!(await recover(e))) {
                    report(status, false, t('mcp', 'Not saved: each client needs a name of up to 64 characters that no other client has, and 1 to 20 redirect URIs (absolute https URIs, without user name, wildcard or fragment).'))
                }
            } finally {
                if (!scope.disposed) save.disabled = false
            }
        })

        // A saved client's Enabled setting applies at once, like the native client's; a new one's is saved with it.
        scope.on(enabled, 'change', async () => {
            if (!saved) {
                return
            }
            const value = enabled.checked
            enabled.disabled = true
            try {
                await saveCustom((clients) => clients.map((other) => other.id === client.id ? { ...other, enabled: value } : other))
                report(status, true)
            } catch (e) {
                    if (scope.disposed) return
                enabled.checked = !value
                if (!(await recover(e))) {
                    report(status, false)
                    notifyError(t('mcp', 'Could not change the self-hosted client.'))
                }
            } finally {
                if (!scope.disposed) enabled.disabled = false
            }
        })

        scope.on(remove, 'click', async () => {
            if (!saved) {
                card.remove()
                return
            }
            if (!window.confirm(t('mcp', 'Delete the client “{name}”? Its connections are signed out at once.', { name: client.name }))) {
                return
            }
            remove.disabled = true
            try {
                await saveCustom((clients) => clients.filter((other) => other.id !== client.id))
                card.remove()
            } catch (e) {
                    if (scope.disposed) return
                if (!(await recover(e))) {
                    report(status, false)
                    notifyError(t('mcp', 'Could not delete the self-hosted client.'))
                }
            } finally {
                if (!scope.disposed) remove.disabled = false
            }
        })
        return card
    }

    return { customCard, keepUnsaved, unsaved: list => [...list.children].filter(card => drafts.get(card)?.edited()) }
}
