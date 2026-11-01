/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Original upload limit editor.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.checkout = admin.modules.checkout || {}
	/**
	 * @param {object} context operation input
	 * @return {void} operation result
	 */
	admin.modules.checkout.upload_limit = function (context) {
		/**
		 * Checkout upload limit of the status block, in whole MiB, through GET and PUT /apps/mcp/api/checkout-limit.
		 * Next to it: the effective limit and PHP's post_max_size ceiling.
		 * @return {Promise<*>} operation result
		 */
		async function initCheckoutLimit() {
			const input = document.getElementById('mcp-checkout-limit')
			const save = document.getElementById('mcp-checkout-limit-save')
			const status = document.getElementById('mcp-checkout-limit-status')
			const info = document.getElementById('mcp-checkout-limit-info')
			const mib = (bytes) => Math.round(bytes / 1048576 * 10) / 10

			/** @param {object} data limit returned by the API, in bytes
			 * @return {*} operation result
			 */
			function show(data) {
				// Round up and never show 0: a stored value below 1 MiB would fail the field's own min of 1 on the next save.
				input.value = String(Math.max(1, Math.ceil(data.configuredBytes / 1048576)))
				const effective = t('mcp', 'Effective limit: {size} MiB.', { size: mib(data.effectiveBytes) })
				const php = data.phpBytes > 0
					? t('mcp', 'PHP allows up to {size} MiB (post_max_size).', { size: mib(data.phpBytes) })
					: t('mcp', 'PHP sets no request size limit.')
				info.textContent = effective + ' ' + php
			}

			try {
				show(await context.api('GET', '/api/checkout-limit'))
			} catch (e) {
				info.textContent = t('mcp', 'Could not load the checkout upload limit.')
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
					show(await context.api('PUT', '/api/checkout-limit', { mib: value }))
					status.textContent = t('mcp', 'Saved')
					context.flash(status, true)
				} catch (e) {
					status.textContent = t('mcp', 'Not saved')
					context.flash(status, false)
					context.notifyError(t('mcp', 'Could not save the checkout upload limit.'))
				} finally {
					save.disabled = false
				}
			})
		}

		Object.assign(context, { initCheckoutLimit })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
