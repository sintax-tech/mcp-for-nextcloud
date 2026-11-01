/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Tests of revealFocus() of src/admin/admin-grants.js, cut out of the source and run against stubs, as in admin-bulk.test.cjs:
 * a control that takes the focus inside the matrix is scrolled out from under the sticky columns of its row (the
 * selection box and the name at the start, the "…" menu at the end), so a cell reached by Tab is never hidden
 * (WCAG 2.4.11).
 */
const { test } = require('node:test')
const assert = require('node:assert/strict')
const vm = require('node:vm')
const fs = require('node:fs')
const path = require('node:path')

const root = path.join(__dirname, '../..')
const source = fs.readFileSync(path.join(root, 'src/admin/admin-grants.js'), 'utf8')

function cut(declaration) {
	const start = source.indexOf('\t' + declaration)
	assert.ok(start > 0, declaration + ' was found in src/admin/admin-grants.js')
	return source.slice(start, source.indexOf('\n\t}\n', start) + 4)
}

/**
 * A row of the matrix scrolled by `wrap.scrollLeft`: sticky cells stay where they are, the others move with the scroll.
 * Widths in px: box 44 (sticky), name 140 (sticky), cells 120 each, "…" 56 (sticky at the end of a 400px block).
 */
function row({ stickyActions = true, stickyStart = true, direction = 'ltr' } = {}) {
	const wrap = { scrollLeft: 0, clientLeft: 0, clientWidth: 400, getBoundingClientRect: () => ({ left: 0, right: 400 }) }
	const cell = (left, width, sticky, name) => ({ name, sticky, left, width, closest: () => null })
	const cells = [cell(0, 44, stickyStart, 'box'), cell(44, 140, stickyStart, 'name'), ...[0, 1, 2, 3, 4, 5, 6].map((i) => cell(184 + i * 120, 120, false, 'cell' + i)), cell(344, 56, stickyActions, 'actions')]
	const rect = (c) => { const left = c.sticky ? c.left : c.left - wrap.scrollLeft; return { left, right: left + c.width } }
	const tr = { children: [] }
	for (const c of cells) {
		c.getBoundingClientRect = () => rect(c)
		c.parentElement = tr
		c.button = { getBoundingClientRect: () => { const r = rect(c); return { left: r.left + 10, right: r.right - 10 } }, closest: () => c }
		tr.children.push(c)
	}
	const context = { getComputedStyle: (node) => ({ position: node.sticky ? 'sticky' : 'static', direction }) }
	vm.runInNewContext(cut('function revealFocus(') + '\nthis.revealFocus = revealFocus', context)
	const focus = (name) => { const c = cells.find((x) => x.name === name); context.revealFocus({ currentTarget: wrap, target: c.button }); return c.button.getBoundingClientRect() }
	return { wrap, focus }
}

test('a cell under the sticky columns at the end is scrolled out from under them', () => {
	const r = row()
	r.wrap.scrollLeft = 0
	const shown = r.focus('cell1')
	assert.ok(shown.right <= 344, 'clear of the "…" column, which starts at 344')
	assert.ok(shown.left >= 184, 'and still clear of the name')
})

test('a cell under the sticky columns at the start is scrolled out from under them', () => {
	const r = row()
	r.wrap.scrollLeft = 500
	const shown = r.focus('cell3')
	assert.ok(shown.left >= 184, 'clear of the box and the name, which end at 184')
})

test('a visible cell is not moved, and a sticky cell never moves the block', () => {
	const r = row()
	r.wrap.scrollLeft = 0
	r.focus('cell0')
	assert.equal(r.wrap.scrollLeft, 0, 'cell0 sits between 184 and 304, clear of both ends')
	r.wrap.scrollLeft = 300
	r.focus('name')
	r.focus('actions')
	assert.equal(r.wrap.scrollLeft, 300)
})

test('a column that is not sticky (the box and the "…" on a phone) is no obstacle, but the edge of the block is', () => {
	const r = row({ stickyActions: false })
	r.wrap.scrollLeft = 0
	const shown = r.focus('cell1')
	assert.ok(shown.right <= 400, 'cell1 (304 to 424) showed only in part: it is scrolled inside the block')
	assert.ok(shown.right > 344, 'by the edge of the block, not by the "…", which scrolls on a phone')
})

test('a control wider than the room left keeps its start in view', () => {
	const r = row()
	r.wrap.clientWidth = 280
	r.wrap.getBoundingClientRect = () => ({ left: 0, right: 280 })
	r.wrap.scrollLeft = 0
	const shown = r.focus('cell1')
	assert.ok(shown.left >= 184, 'its start is clear of the name, even though its end cannot be')
})

test('the matrix block listens for the focus', () => {
	assert.match(source, /table\.parentElement\?\.addEventListener\('focusin', revealFocus\)/)
})

test('right to left, the start columns are on the right: a cell under them is scrolled out to the left', () => {
	// Mirror of the row: the box and the name on the right (216 to 400), the "…" on the left (0 to 56).
	const wrap = { scrollLeft: 0, clientLeft: 0, clientWidth: 400, getBoundingClientRect: () => ({ left: 0, right: 400 }) }
	const tr = { children: [] }
	const make = (name, left, width, sticky) => {
		const c = { name, sticky, left, width, parentElement: tr }
		c.getBoundingClientRect = () => { const l = sticky ? left : left - wrap.scrollLeft; return { left: l, right: l + width } }
		c.button = { getBoundingClientRect: () => c.getBoundingClientRect(), closest: () => c }
		tr.children.push(c)
		return c
	}
	make('box', 356, 44, true); make('name', 216, 140, true)
	const target = make('cell0', 150, 120, false)
	make('actions', 0, 56, true)
	const context = { getComputedStyle: (node) => ({ position: node.sticky ? 'sticky' : 'static', direction: 'rtl' }) }
	vm.runInNewContext(cut('function revealFocus(') + '\nthis.revealFocus = revealFocus', context)
	context.revealFocus({ currentTarget: wrap, target: target.button })
	const shown = target.getBoundingClientRect()
	assert.ok(shown.right <= 216 && shown.left >= 56, 'between the "…" (to 56) and the name (from 216)')
})
