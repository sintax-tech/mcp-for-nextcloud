/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Matrix revisions operations.
 * @param {object} context live bindings of this editor
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.matrix = admin.modules.matrix || {}
	admin.modules.matrix.revisions = function (context) {
		const REVISION_MOVED = 'mcp-revision-moved'


		/**
		 * Tells the other blocks of this page that a write of this page moved the policy revision from `from` to `to`.
		 * Only the answer of a save qualifies: the server committed it from `from`, so `to` holds that write and nothing else.
		 *
		 * @param {string} from revision the write was sent with
		 * @param {string} to revision the server answered with
		 */
		function announce(from, to) {
			if (typeof from === 'string' && typeof to === 'string' && from !== to) {
				window.dispatchEvent(new CustomEvent(REVISION_MOVED, { detail: { from, to } }))
			}
		}


		/**
		 * Whether `to` is exactly one commit after `from`. The OAuth clients and server-log saves answer with the revision of
		 * a read made after their commit, which may already hold another administrator's write. A commit advances the
		 * revision by one, and only when the request differs from what is stored at `from`; so a save whose request differs
		 * from the settings this block confirmed at `from` made one step itself, and one step in all is that save alone. A
		 * request that changes nothing commits nothing: its answer is never announced, whatever it shows. Compared as exact
		 * integers, never as floating-point numbers.
		 *
		 * @param {string} from revision the save was sent with
		 * @param {string} to revision of the answer
		 * @return {boolean}
		 */
		function oneStep(from, to) {
			try {
				return BigInt(to) === BigInt(from) + 1n
			} catch {
				return false
			}
		}


		/**
		 * PHP's trim() without a character list: removes only " \t\n\r\0\x0B" from both ends. String.prototype.trim()
		 * keeps NUL and removes Unicode spaces PHP keeps, so it cannot stand in for the server's normalization.
		 *
		 * @param {string} text
		 * @return {string}
		 */
		function phpTrim(text) {
			return text.replace(/^[ \t\n\r\x00\x0B]+|[ \t\n\r\x00\x0B]+$/g, '')
		}


		/**
		 * Keeps a block's own revision in step with the writes of this page: when the block is exactly at the revision a
		 * write committed from, it takes the one that write made, and its unsaved changes stay valid.
		 *
		 * @param {Function} get () => the block's revision
		 * @param {Function} set (revision) => void
		 */
		function followRevision(get, set) {
			window.addEventListener(REVISION_MOVED, (event) => {
				if (get() === event.detail.from) {
					set(event.detail.to)
				}
			})
		}

		Object.defineProperties(context, {
			REVISION_MOVED: { configurable: true, get: () => REVISION_MOVED },
		})

		Object.assign(context, { announce, followRevision, oneStep, phpTrim })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
