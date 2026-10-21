/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
export function flashStatus(cell, ok) {
	cell.classList.remove('mcp-ok', 'mcp-error')
	void cell.offsetWidth
	cell.classList.add(ok ? 'mcp-ok' : 'mcp-error')
}
