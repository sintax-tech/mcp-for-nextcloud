/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the file read limit unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.checkout = admin.modules.checkout || {}
	/** Attach file read limit loading, display and persistence to the matrix context.
	 * @param {object} context matrix request and revision dependencies for the file read limit
	 * @return {void}
	 */
	admin.modules.checkout.readLimit = function (context) {
		/**
		 * File read limit of the status block, in whole MiB, through GET and PUT /apps/mcp/api/file-read-limit.
		 * Next to it: the effective limit and the memory_limit ceiling.
		 * @return {Promise<void>}
		 */
		async function initFileReadLimit() {
			const input = document.getElementById('mcp-file-read-limit')
			const save = document.getElementById('mcp-file-read-limit-save')
			const status = document.getElementById('mcp-file-read-limit-status')
			const info = document.getElementById('mcp-file-read-limit-info')
			const mib = (bytes) => Math.round(bytes / 1048576 * 10) / 10

			/** Display the configured limit, the effective limit and why memory_limit does or does not cap it.
			 * @param {object} data limit returned by the API, in bytes
			 * @return {void}
			 */
			function show(data) {
				// Round up and never show 0: a stored value below 1 MiB would fail the field's own min of 1 on the next save.
				input.value = String(Math.max(1, Math.ceil(data.configuredBytes / 1048576)))
				const effective = t('mcp', 'Effective limit: {size} MiB.', { size: mib(data.effectiveBytes) })
				let reason
				if (data.phpUnlimited) {
					reason = t('mcp', 'PHP sets no memory_limit, so the configured limit applies.')
				} else if (data.effectiveBytes < data.configuredBytes) {
					reason = t('mcp', 'PHP memory_limit caps this read at {size} MiB because a PDF parser holds several copies of the file.', { size: mib(data.effectiveBytes) })
				} else {
					reason = t('mcp', 'PHP memory_limit allows up to {size} MiB for this read.', { size: mib(data.ceilingBytes) })
				}
				info.textContent = effective + ' ' + reason
			}

			try {
				show(await context.api('GET', '/api/file-read-limit'))
			} catch (e) {
				info.textContent = t('mcp', 'Could not load the file read limit.')
				return
			}

			save.addEventListener('click', async () => {
				const value = Number(input.value)
				if (!Number.isInteger(value) || value < 1) {
					status.textContent = t('mcp', 'Enter a whole number of MiB, at least 1.')
					context.flash(status, false)
					return
				}
				save.disabled = true
				try {
					show(await context.api('PUT', '/api/file-read-limit', { mib: value }))
					status.textContent = t('mcp', 'Saved')
					context.flash(status, true)
				} catch (e) {
					status.textContent = t('mcp', 'Not saved')
					context.flash(status, false)
					context.notifyError(t('mcp', 'Could not save the file read limit.'))
				} finally {
					save.disabled = false
				}
			})
		}

		Object.assign(context, { initFileReadLimit })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
