/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
'use strict'

const fs = require('node:fs')
const zlib = require('node:zlib')

/**
 * Reads one member out of a gzip-compressed ustar archive.
 *
 * The name is the path stored in the archive (`mcp/js/mcp-admin.js`), not a path on disk. The reader does not
 * call `tar`, so AppleDouble entries are not hidden. GNU long names and pax headers are not applied: the bundles
 * this helper is for have short ustar names. A member name that occurs twice is refused: tar would install the
 * last copy, and returning the first would hide that. The name is canonical before that comparison: `.` and
 * empty segments are dropped, `..` and an absolute name are refused, and a link is not the file `tar` would leave.
 *
 * @param {string} archivePath path of the `.tar.gz`
 * @param {string} memberName member to return
 * @returns {string} the member body as UTF-8
 */
function extractMember(archivePath, memberName) {
	const raw = zlib.gunzipSync(fs.readFileSync(archivePath))
	let offset = 0
	let found = null
	while (offset + 512 <= raw.length) {
		const header = raw.subarray(offset, offset + 512)
		if (header.every((byte) => byte === 0)) {
			break
		}
		const name = field(header, 0, 100)
		const prefix = field(header, 345, 155)
		const rawName = prefix === '' ? name : prefix + '/' + name
		const full = canonical(rawName)
		const size = parseInt(field(header, 124, 12).trim(), 8) || 0
		const type = String.fromCharCode(header[156])
		const linkTarget = field(header, 157, 100)
		offset += 512
		const body = raw.subarray(offset, offset + size)
		offset += Math.ceil(size / 512) * 512
		if (type === '1' || type === '2') {
			if (linkTarget === '') {
				throw new Error('unsafe archive member: ' + rawName)
			}
			canonical(linkTarget)
			if (full === memberName || full === 'mcp/src' || full.startsWith('mcp/src/')) {
				throw new Error('archive link: ' + full)
			}
		}
		if (type !== '5' && (full === 'mcp/src' || full.startsWith('mcp/src/'))) {
			throw new Error('packaged source: ' + full)
		}
		if (full === memberName && type !== '5' && type !== '1' && type !== '2') {
			if (found !== null) {
				throw new Error('duplicate archive member: ' + memberName)
			}
			found = body
		}
	}
	if (found === null) {
		throw new Error('not in archive: ' + memberName)
	}
	return found.toString('utf8')
}

/**
 * A NUL-terminated header field.
 *
 * @param {Buffer} header one 512-byte ustar header
 * @param {number} start first byte
 * @param {number} length field width
 * @returns {string}
 */
function field(header, start, length) {
	return header.subarray(start, start + length).toString('utf8').replace(/\0.*$/s, '')
}

/**
 * The path tar installs. `.` and empty segments are dropped. `..` and an absolute name are refused.
 *
 * @param {string} name member name or link target
 * @returns {string}
 */
function canonical(name) {
	if (name.startsWith('/') || name.includes('\\') || name.includes('\0')) {
		throw new Error('unsafe archive member: ' + name)
	}
	const parts = []
	for (const part of name.split('/')) {
		if (part === '' || part === '.') {
			continue
		}
		if (part === '..') {
			throw new Error('unsafe archive member: ' + name)
		}
		parts.push(part)
	}
	if (parts.length === 0) {
		throw new Error('unsafe archive member: ' + name)
	}
	return parts.join('/')
}

module.exports = { extractMember }
