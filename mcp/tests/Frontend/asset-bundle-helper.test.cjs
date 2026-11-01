/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
'use strict'

const assert = require('node:assert/strict')
const fs = require('node:fs')
const os = require('node:os')
const path = require('node:path')
const test = require('node:test')
const zlib = require('node:zlib')
const { loadManifest } = require('./load-manifest.cjs')
const { extractMember } = require('./extract-bundle.cjs')

test('loadManifest keeps file order and skips blanks and comments', () => {
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'mcp-manifest-'))
	const file = path.join(dir, 'admin.manifest')
	try {
		fs.writeFileSync(file, '# built by scripts/build_assets.php\n\nadmin/b.js\r\n  shared/a.js  \n')
		assert.deepEqual(loadManifest(file), ['admin/b.js', 'shared/a.js'])
	} finally {
		fs.rmSync(dir, { recursive: true })
	}
})

test('extractMember reads the bundle out of a gzip ustar archive', () => {
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'mcp-bundle-'))
	const archive = path.join(dir, 'mcp.tar.gz')
	try {
		const body = '/* admin/a.js */\nvar a = 1\n'
		fs.writeFileSync(archive, zlib.gzipSync(Buffer.concat([
			ustar('mcp/js/mcp-admin.js', body),
			Buffer.alloc(1024),
		])))
		assert.equal(extractMember(archive, 'mcp/js/mcp-admin.js'), body)
	} finally {
		fs.rmSync(dir, { recursive: true })
	}
})

test('extractMember refuses a member that appears twice', () => {
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'mcp-bundle-'))
	const archive = path.join(dir, 'mcp.tar.gz')
	try {
		const good = '/* admin/a.js */\nvar a = 1\n'
		fs.writeFileSync(archive, zlib.gzipSync(Buffer.concat([
			ustar('mcp/js/mcp-admin.js', good),
			ustar('mcp/js/mcp-admin.js', '/* stale */\n'),
			Buffer.alloc(1024),
		])))
		assert.throws(
			() => extractMember(archive, 'mcp/js/mcp-admin.js'),
			/duplicate archive member: mcp\/js\/mcp-admin\.js/,
		)
	} finally {
		fs.rmSync(dir, { recursive: true })
	}
})

test('loadManifest refuses a path listed twice', () => {
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'mcp-manifest-'))
	const file = path.join(dir, 'admin.manifest')
	try {
		fs.writeFileSync(file, 'admin/a.js\nadmin/a.js\n')
		assert.throws(() => loadManifest(file), /duplicate entry: admin\/a\.js/)
	} finally {
		fs.rmSync(dir, { recursive: true })
	}
})

/**
 * One ustar member: header plus body padded to a whole block.
 *
 * @param {string} name member name, at most 100 bytes
 * @param {string} content member body
 * @returns {Buffer}
 */
function ustar(name, content) {
	const header = Buffer.alloc(512, 0)
	header.write(name, 0, 'utf8')
	writeOctal(header, 100, 8, 0o644)
	writeOctal(header, 108, 8, 0)
	writeOctal(header, 116, 8, 0)
	writeOctal(header, 124, 12, Buffer.byteLength(content))
	writeOctal(header, 136, 12, 0)
	header.fill(0x20, 148, 156)
	header[156] = 0x30
	header.write('ustar\0', 257, 'binary')
	header.write('00', 263, 'binary')
	let sum = 0
	for (const byte of header) {
		sum += byte
	}
	writeOctal(header, 148, 7, sum)
	header[155] = 0x20
	const body = Buffer.alloc(Math.ceil(Buffer.byteLength(content) / 512) * 512, 0)
	body.write(content, 0, 'utf8')
	return Buffer.concat([header, body])
}

/**
 * Writes an octal field the way ustar stores mode and size.
 *
 * @param {Buffer} header header being filled
 * @param {number} start first byte
 * @param {number} width digits before the NUL
 * @param {number} value non-negative integer
 * @returns {void}
 */
function writeOctal(header, start, width, value) {
	header.write(value.toString(8).padStart(width - 1, '0'), start, 'utf8')
	header[start + width - 1] = 0
}
