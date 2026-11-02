/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** file-write-policy/validation operations.
 * @param {object} scope browser primitives
 * @param {object} admin app registry
 * @return {void} registers and starts the original editor
 */
(function (scope, admin) {
	'use strict'

	/**
	 * Trim only the boundary bytes removed by PHP trim(), excluding form feed and Unicode spaces.
	 * @param {string} text Raw text or a separated token.
	 * @returns {string} Text with PHP's default ASCII boundary whitespace removed.
	 */
	function phpTrim(text) {
		return text.replace(/^[ \t\n\r\0\v]+|[ \t\n\r\0\v]+$/g, '')
	}

	/**
	 * Normalize the textarea with FileWritePolicy's ASCII grammar, PHP trim and non-UTF PCRE separators.
	 * @param {string} text Raw textarea input.
	 * @returns {string[]} Sorted, unique lowercase extensions.
	 * @throws {Error} For unsupported tokens, separators or more than 128 distinct extensions.
	 */
	function extensions(text) {
		if (/[^\x00-\x7f]/.test(text)) throw new Error('extensions')
		const trimmed = phpTrim(text)
		const tokens = trimmed === '' ? [] : trimmed.split(/[ \t\n\r\v\f]*(?:,|\r\n|[\n\r\v\f])[ \t\n\r\v\f]*/)
		if (tokens.at(-1) === '') tokens.pop()
		const canonical = tokens.map(token => phpTrim(token).toLowerCase().replace(/^\./, ''))
		if (canonical.some(token => !/^[a-z0-9][a-z0-9_-]{0,31}$/.test(token))) throw new Error('extensions')
		const result = [...new Set(canonical)].sort()
		if (result.length > 128) throw new Error('extensions')
		return result
	}

	/**
	 * Reject malformed responses rather than interpreting a failed load as off.
	 * @param {unknown} value Parsed JSON response.
	 * @returns {boolean} Whether it is a canonical policy.
	 */
	function validPolicy(value) {
		return !!value && value.version === 1 && ['off', 'allowlist'].includes(value.mode)
			&& typeof value.allowExtensionless === 'boolean' && Array.isArray(value.extensions)
			&& value.extensions.every(token => typeof token === 'string' && /^[a-z0-9][a-z0-9_-]{0,31}$/.test(token))
			&& value.extensions.length <= 128
			&& JSON.stringify([...new Set(value.extensions)].sort()) === JSON.stringify(value.extensions)
	}

	admin.fileWritePolicy = { phpTrim, extensions, validPolicy }
})((globalThis.window || globalThis), (globalThis.window || globalThis).OCA.Mcp.Admin)
