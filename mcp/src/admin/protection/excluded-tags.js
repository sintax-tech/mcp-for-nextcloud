/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createScope } from '../../platform/lifecycle.js'

export function mountExcludedTags({ root, request, t, notifyError, createPicker, overviewAlert, tabAlert }) {
	const scope = createScope(root)
	const api = scope.request(request)
	function el(tag, props, children) {
		const node = document.createElement(tag)
		Object.assign(node, props || {})
		for (const child of children || []) {
			node.append(child)
		}
		return node
	}
	async function start() {
		const container = root.querySelector('#mcp-tags-list')
		const warning = root.querySelector('#mcp-tags-warning')
		const status = root.querySelector('#mcp-tags-status')
		const invalid = root.querySelector('#mcp-tags-invalid')
		const repair = root.querySelector('#mcp-tags-repair')
		// The gravest state of the app is an unreadable exclusion setting (fail-closed: every file is
		// refused). The repair block lives in the Data protection tab, so the Status block of Overview
		// also shows it and the tab carries a marker.
		if (!container || !createPicker) {
			return
		}

		/** @param {boolean} isInvalid shows or hides every sign of the unreadable setting */
		function setInvalidState(isInvalid) {
			if (invalid) {
				invalid.style.display = isInvalid ? 'block' : 'none'
			}
			if (overviewAlert) {
				overviewAlert.hidden = !isInvalid
			}
			if (tabAlert) {
				tabAlert.hidden = !isInvalid
			}
		}

		let allTags = []

		/**
		 * The type badge of a tag option, with its explanation in the title.
		 *
		 * @param {string} type collaborative, restricted or invisible
		 * @return {{text: string, title: string, className: string}}
		 */
		function tagBadge(type) {
			if (type === 'invisible') {
				return { text: t('mcp', 'Invisible'), title: t('mcp', 'Visible and assignable only by administrators'), className: 'mcp-tag-type-invisible' }
			}
			if (type === 'restricted') {
				return { text: t('mcp', 'Restricted'), title: t('mcp', 'Visible to users, assignable only by administrators and the groups they allow'), className: 'mcp-tag-type-restricted' }
			}
			return { text: t('mcp', 'Collaborative'), title: t('mcp', 'Users can assign and remove this tag'), className: 'mcp-tag-type-collaborative' }
		}

		/** @param {string[]} selectedIds warns when a collaborative tag, removable by editors, is selected */
		function updateWarning(selectedIds) {
			if (!warning) {
				return
			}
			const hasCollab = allTags.some((tag) => tag.type === 'collaborative' && selectedIds.includes(String(tag.id)))
			warning.style.display = hasCollab ? 'block' : 'none'
		}

		/**
		 * PUT /api/admin/tags with the same payload as before. A failure is explained here and rethrown,
		 * so the picker rolls the selection back; on success the invalid-setting block goes away.
		 *
		 * @param {string[]} tagIds the ids to save
		 */
		async function saveTags(tagIds) {
			try {
				await api('PUT', '/api/admin/tags', { tagIds })
			} catch (e) {
			if (scope.disposed) return
				// 503: the system tags could not be checked and the server kept the previous setting.
				notifyError(e.message === '503'
					? t('mcp', 'Could not check the system tags, so the exclusion tags were not changed. Try again later.')
					: t('mcp', 'Could not update exclusion tags.'))
				throw e
			}
			setInvalidState(false)
		}

		try {
			const res = await api('GET', '/api/admin/tags')
			allTags = res.tags || []

			const picker = createPicker({
				container,
				status,
				options: allTags.map((tag) => ({ id: String(tag.id), label: tag.name, badge: tagBadge(tag.type) })),
				selectedIds: (res.selected || []).map(String),
				searchLabel: t('mcp', 'Search tags'),
				listLabel: t('mcp', 'Exclusion tags'),
				emptyOptionsText: t('mcp', 'No system tags found. Create tags in Files settings.'),
				noMatchText: t('mcp', 'No match.'),
				// An empty list turns the protection off; from an invalid setting that goes from hiding
				// everything to hiding nothing, so it is never saved without the administrator saying so.
				confirmSave: (nextIds) => nextIds.length > 0
					|| window.confirm(t('mcp', 'Saving with no exclusion tags turns the protection off: every file becomes visible to the MCP tools again. Continue?')),
				save: saveTags,
				onChange: updateWarning,
				clearSavedAfterMs: 2000,
				errorStatus: '',
			})

			scope.use(picker.destroy)
			setInvalidState(Boolean(res.invalid))
			if (res.invalid && repair) {
				scope.on(repair, 'click', async () => {
					repair.disabled = true
					try {
						await picker.commit({ force: true })
					} finally {
						if (!scope.disposed) {
							repair.disabled = false
						}
					}
				})
			}

			updateWarning(picker.getSelected())
		} catch {
			if (scope.disposed) return
			container.setAttribute('aria-busy', 'false')
			container.replaceChildren(el('p', { className: 'mcp-empty', textContent: t('mcp', 'Could not load system tags.') }))
		}

	}
	const ready = start()
	return { ready, destroy: scope.destroy }
}
