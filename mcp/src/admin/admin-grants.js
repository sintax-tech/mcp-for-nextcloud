/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Existing settings blocks, delegated during the matrix extraction. */
(function (admin) {
	'use strict'
	admin.modules.settings = function (context) {


		async function initTagsSection() {
			const container = document.getElementById('mcp-tags-list')
			const warning = document.getElementById('mcp-tags-warning')
			const status = document.getElementById('mcp-tags-status')
			const invalid = document.getElementById('mcp-tags-invalid')
			const repair = document.getElementById('mcp-tags-repair')
			// The gravest state of the app is an unreadable exclusion setting (fail-closed: every file is
			// refused). The repair block lives in the Data protection tab, so the Status block of Overview
			// also shows it and the tab carries a marker.
			const overviewAlert = document.getElementById('mcp-tags-invalid-overview')
			const tabAlert = document.getElementById('mcp-tab-protection-alert')
			if (!container || !window.OCA.Mcp.Admin.picker) {
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
					await context.api('PUT', '/api/admin/tags', { tagIds })
				} catch (e) {
					// 503: the system tags could not be checked and the server kept the previous setting.
					context.notifyError(e.message === '503'
						? t('mcp', 'Could not check the system tags, so the exclusion tags were not changed. Try again later.')
						: t('mcp', 'Could not update exclusion tags.'))
					throw e
				}
				setInvalidState(false)
			}

			try {
				const res = await context.api('GET', '/api/admin/tags')
				allTags = res.tags || []

				const picker = window.OCA.Mcp.Admin.picker.create({
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

				setInvalidState(Boolean(res.invalid))
				if (res.invalid && repair) {
					repair.addEventListener('click', async () => {
						repair.disabled = true
						try {
							await picker.commit({ force: true })
						} finally {
							repair.disabled = false
						}
					})
				}

				updateWarning(picker.getSelected())
			} catch {
				container.setAttribute('aria-busy', 'false')
				container.replaceChildren(context.el('p', { className: 'mcp-empty', textContent: t('mcp', 'Could not load system tags.') }))
			}
		}


		/**
		 * OAuth clients section: allowed client_id hosts, the native client switch and the self-hosted clients, each with
		 * its name, Enabled setting and redirect URIs, through GET and PUT /apps/mcp/api/oauth-clients. The saves of this
		 * block go one at a time, and each queues an intent (this client, this change), not a body: the list it sends is
		 * built when its turn comes, from the clients the server confirmed last, and carries the revision of that same
		 * answer. So two quick changes never resend a list from before the first one (turning B off cannot turn A back on).
		 * A page that is out of date (another window or administrator saved first) gets 409 and is rebuilt from the
		 * server; the intents queued behind the refused one are dropped with it, never sent on the reloaded page. A card
		 * with typed changes the server does not have (a new client, or a changed name or redirect URIs) stays on screen
		 * through that rebuild, marked as not saved, for the admin to check and save again. A save of another block of this
		 * page moves the revision too; that move is adopted (see announce()), so it never refuses a save here.
		 */
		async function initOauthClients() {
			const hostsInput = document.getElementById('mcp-oauth-hosts')
			const hostsSave = document.getElementById('mcp-oauth-hosts-save')
			const hostsStatus = document.getElementById('mcp-oauth-hosts-status')
			const nativeBox = document.getElementById('mcp-native-client')
			const nativeStatus = document.getElementById('mcp-native-client-status')
			const details = document.getElementById('mcp-native-client-details')
			const clientId = document.getElementById('mcp-native-client-id')
			const redirects = document.getElementById('mcp-native-redirects')
			const customList = document.getElementById('mcp-custom-clients')
			const customAdd = document.getElementById('mcp-custom-add')
			/** Self-hosted clients as the server last returned them; each card edits one of them (or a new one). */
			let customClients = []
			let cardCount = 0
			// The block's own revision, from its own GET or save; never the matrix's, never an error body's.
			let oauthRevision = null
			let queue = Promise.resolve()
			// Advances when the page is reloaded after a refused save: intents queued before that are dropped.
			let epoch = 0
			/** Per card: the client it was built from, and what is typed in it now. */
			const drafts = new WeakMap()
			/** The server's last answer: what is stored at this block's revision. */
			let confirmed = null
			context.followRevision(() => oauthRevision, (revision) => { oauthRevision = revision })

			/** @param {object} data settings returned by the API */
			function show(data) {
				oauthRevision = data.revision
				confirmed = data
				hostsInput.value = data.hosts.join(', ')
				nativeBox.checked = data.nativeClientEnabled
				details.hidden = !data.nativeClientEnabled
				clientId.textContent = data.nativeClientId
				redirects.replaceChildren(...data.nativeRedirectUris.map((uri) => context.el('li', {}, [context.el('code', { textContent: uri })])))
				customClients = data.customClients
			}

			/**
			 * @param {HTMLElement} status status span next to the control
			 * @param {boolean} ok whether the save worked
			 * @param {string} [message] text shown on failure
			 */
			function report(status, ok, message) {
				status.textContent = ok ? t('mcp', 'Saved') : (message || t('mcp', 'Not saved'))
				context.flash(status, ok)
			}

			/**
			 * Runs one task of the block after the previous one has finished.
			 *
			 * @param {Function} task async work
			 * @return {Promise<*>} what the task returned
			 */
			function enqueue(task) {
				const run = queue.then(task)
				queue = run.catch(() => {})
				return run
			}

			/**
			 * Sends one change when its turn comes: the body is built then by `build`, from the clients the server confirmed
			 * last, with the revision of that same answer, which is then replaced by the revision and clients the server
			 * answers with. The policy revision moved, so the matrix is reloaded to keep its own saves valid. An intent
			 * queued before a reload is dropped (error "stale"); a refused save drops the intents queued behind it.
			 *
			 * @param {Function} build (clients) => the changed settings
			 * @return {Promise<object>} the settings the server saved
			 */
			function put(build) {
				const queuedAt = epoch
				return enqueue(async () => {
					if (queuedAt !== epoch) {
						throw new Error('stale')
					}
					try {
						const sent = oauthRevision
						const change = build(customClients)
						const committed = changes(change, confirmed)
						const data = await context.api('PUT', '/api/oauth-clients', { ...change, expectedRevision: sent })
						oauthRevision = data.revision
						customClients = data.customClients
						confirmed = data
						if (committed && context.oneStep(sent, data.revision)) {
							context.announce(sent, data.revision)
						}
						context.load()
						return data
					} catch (e) {
						if (e.message === '409' || e.message === '428' || (e.message === '503' && e.data && e.data.uncertain)) {
							epoch++
						}
						throw e
					}
				})
			}

			/**
			 * Whether a request differs from the settings stored at the block's revision, compared the way the server compares
			 * them (OAuthClientsService): the server then commits it and the revision advances. Hosts are trimmed, lowercased
			 * (ASCII only) and without duplicates, and the default list is stored as no list (GrantsController::hosts,
			 * writeHosts); clients keep their order, with names and redirect URIs trimmed and URIs without duplicates
			 * (CustomClient::validate, validateUris). Every trim is PHP's own (phpTrim()). A value still holding a control
			 * character after it, which the server refuses, counts as unsure. False when unsure, so nothing is announced then.
			 *
			 * @param {object} change the settings sent (hosts, nativeClientEnabled or customClients)
			 * @param {object} stored the server's last answer
			 * @return {boolean}
			 */
			function changes(change, stored) {
				const control = (text) => /[\x00-\x1f\x7f]/.test(text)
				const uris = (list) => [...new Set(list.map(context.phpTrim))]
				if (change.nativeClientEnabled !== undefined && change.nativeClientEnabled !== stored.nativeClientEnabled) {
					return true
				}
				if (change.hosts !== undefined) {
					// strtolower() of PHP 8 changes A-Z only.
					const hosts = [...new Set(change.hosts.map((host) => context.phpTrim(host).replace(/[A-Z]/g, (letter) => letter.toLowerCase())))]
					if (hosts.some(control)) {
						return false
					}
					// Stored as no list when the request is the default set; only known while the default is what is stored.
					const isDefault = stored.hostsDefault && hosts.length === stored.hosts.length && hosts.every((host) => stored.hosts.includes(host))
					if (stored.hostsDefault ? !isDefault : hosts.join(',') !== stored.hosts.join(',')) {
						return true
					}
				}
				if (change.customClients !== undefined) {
					if (change.customClients.some((client) => control(context.phpTrim(client.name)) || uris(client.redirectUris).some(control))) {
						return false
					}
					const key = (clients) => JSON.stringify(clients.map((client) => [client.id ?? null, context.phpTrim(client.name), client.enabled, uris(client.redirectUris)]))
					if (key(change.customClients) !== key(stored.customClients)) {
						return true
					}
				}
				return false
			}

			/**
			 * When a save failed because the page was out of date (409, or 428 without a revision) or the server could not
			 * confirm it (503 uncertain), shows what is stored now, in the queue's order, and tells the admin nothing was
			 * overwritten. The cards with unsaved typed changes are kept (see keepUnsaved()). An intent dropped behind such a
			 * save is handled by that reload.
			 *
			 * @param {Error} e error thrown by put()
			 * @return {Promise<boolean>} whether the error was handled here (the settings are then reloaded)
			 */
			async function recover(e) {
				if (e.message === 'stale') {
					return true
				}
				if (e.message === '409' || e.message === '428') {
					context.notifyError(t('mcp', 'The OAuth client settings were changed in another window or by another administrator. The current settings were loaded; nothing was overwritten.'))
				} else if (e.message === '503' && e.data && e.data.uncertain) {
					context.notifyError(t('mcp', 'The server could not confirm whether the change was saved. The current settings were loaded.'))
				} else {
					return false
				}
				try {
					const unsaved = [...customList.children].filter((card) => drafts.get(card)?.edited())
					show(await enqueue(() => context.api('GET', '/api/oauth-clients')))
					customList.replaceChildren(...keepUnsaved(unsaved))
				} catch (reload) {
					context.notifyError(t('mcp', 'Could not load the OAuth client settings.'))
				}
				return true
			}

			/**
			 * The cards of the clients the server has now, with the cards whose typed changes it does not have put back: a
			 * saved client keeps its edited card in its place, a new card comes after the saved ones, and the edits of a
			 * client deleted elsewhere come back as a new card. Each card put back says it was not saved.
			 *
			 * @param {HTMLElement[]} unsaved cards with typed changes, read before the reload
			 * @return {HTMLElement[]} the cards to show
			 */
			function keepUnsaved(unsaved) {
				const kept = new Map(unsaved.filter((card) => drafts.get(card).client.id !== null).map((card) => [drafts.get(card).client.id, card]))
				const cards = customClients.map((client) => kept.get(client.id) || customCard(client))
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

			/**
			 * Saves one change to the list of self-hosted clients.
			 *
			 * @param {Function} change (clients) => every client to keep, a new one without id; applied to the clients the
			 *     server confirmed last, when the save's turn comes
			 * @return {Promise<object|undefined>} the client the server added, if any
			 */
			async function saveCustom(change) {
				let before = new Set()
				await put((clients) => {
					before = new Set(clients.map((client) => client.id))
					return { customClients: change(clients).map(({ id, name, enabled, redirectUris }) => ({ id, name, enabled, redirectUris })) }
				})
				return customClients.find((client) => !before.has(client.id))
			}

			/**
			 * One self-hosted client: name, Enabled, client_id with Copy (once saved), redirect URIs, Save and Delete.
			 * Saving or deleting replaces only this card, so unsaved edits in the other cards stay.
			 *
			 * @param {object} client a stored client, or {id: null, ...} for a new one
			 * @return {HTMLElement} the card
			 */
			function customCard(client) {
				const n = ++cardCount
				const saved = client.id !== null
				const name = context.el('input', { type: 'text', id: 'mcp-custom-name-' + n, value: client.name, maxLength: 64, autocomplete: 'off', spellcheck: false })
				const enabled = context.el('input', { type: 'checkbox', className: 'checkbox', id: 'mcp-custom-enabled-' + n, checked: client.enabled })
				const uris = context.el('textarea', {
					id: 'mcp-custom-uris-' + n, className: 'mcp-custom-redirects', rows: 3, spellcheck: false, autocomplete: 'off',
					placeholder: 'https://harness.example.com/oauth/callback', value: client.redirectUris.join('\n'),
				})
				const status = context.el('span', { className: 'mcp-status' })
				status.setAttribute('aria-live', 'polite')
				const save = context.el('button', { type: 'button', textContent: t('mcp', 'Save') })
				const remove = context.el('button', { type: 'button', textContent: t('mcp', 'Delete') })
				const rows = [
					context.el('p', {}, [context.el('label', { htmlFor: name.id, textContent: t('mcp', 'Name') }), ' ', name]),
					context.el('p', {}, [enabled, context.el('label', { htmlFor: enabled.id, textContent: t('mcp', 'Enabled') })]),
				]
				if (saved) {
					const code = context.el('code', { textContent: client.clientId })
					const copy = context.el('button', { type: 'button', className: 'mcp-copy', textContent: t('mcp', 'Copy') })
					copy.addEventListener('click', () => {
						navigator.clipboard.writeText(client.clientId).then(
							() => { copy.textContent = t('mcp', 'Copied') },
							() => context.notifyError(t('mcp', 'Could not copy the client_id.')),
						)
					})
					rows.push(context.el('p', {}, ['client_id: ', code, ' ', copy]))
				}
				rows.push(
					context.el('p', {}, [context.el('label', { htmlFor: uris.id, textContent: t('mcp', 'Accepted redirect URIs') })]),
					uris,
					context.el('p', { className: 'settings-hint mcp-hint', textContent: t('mcp', 'One URI per line, exactly as the client sends it, port included: https (http only for 127.0.0.1, localhost or [::1]), no wildcard or fragment, up to 2000 bytes.') }),
					context.el('p', {}, [save, ' ', remove, ' ', status]),
				)
				const card = context.el('div', { className: 'mcp-custom-client' }, rows)

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

				save.addEventListener('click', async () => {
					const entry = read()
					save.disabled = true
					try {
						const added = await saveCustom((clients) => saved
							? clients.map((other) => other.id === client.id ? entry : other)
							: [...clients, entry])
						const next = customCard(saved ? customClients.find((other) => other.id === client.id) : added)
						card.replaceWith(next)
						next.querySelector('.mcp-status').textContent = t('mcp', 'Saved')
						context.flash(next.querySelector('.mcp-status'), true)
					} catch (e) {
						if (!(await recover(e))) {
							report(status, false, t('mcp', 'Not saved: each client needs a name of up to 64 characters that no other client has, and 1 to 20 redirect URIs (absolute https URIs, without user name, wildcard or fragment).'))
						}
					} finally {
						save.disabled = false
					}
				})

				// A saved client's Enabled setting applies at once, like the native client's; a new one's is saved with it.
				enabled.addEventListener('change', async () => {
					if (!saved) {
						return
					}
					const value = enabled.checked
					enabled.disabled = true
					try {
						await saveCustom((clients) => clients.map((other) => other.id === client.id ? { ...other, enabled: value } : other))
						report(status, true)
					} catch (e) {
						enabled.checked = !value
						if (!(await recover(e))) {
							report(status, false)
							context.notifyError(t('mcp', 'Could not change the self-hosted client.'))
						}
					} finally {
						enabled.disabled = false
					}
				})

				remove.addEventListener('click', async () => {
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
						if (!(await recover(e))) {
							report(status, false)
							context.notifyError(t('mcp', 'Could not delete the self-hosted client.'))
						}
					} finally {
						remove.disabled = false
					}
				})
				return card
			}

			try {
				show(await context.api('GET', '/api/oauth-clients'))
			} catch (e) {
				context.notifyError(t('mcp', 'Could not load the OAuth client settings.'))
				return
			}
			customList.replaceChildren(...customClients.map(customCard))
			customAdd.addEventListener('click', () => {
				const card = customCard({ id: null, name: '', enabled: true, redirectUris: [] })
				customList.append(card)
				card.querySelector('input[type="text"]').focus()
			})

			hostsSave.addEventListener('click', async () => {
				const hosts = hostsInput.value.split(',').map((host) => host.trim().toLowerCase()).filter((host) => host !== '')
				hostsSave.disabled = true
				try {
					show(await put(() => ({ hosts })))
					report(hostsStatus, true)
				} catch (e) {
					if (!(await recover(e))) {
						report(hostsStatus, false, t('mcp', 'Invalid host list: use lowercase host names only, without https://, port, path or *, and keep at least one.'))
					}
				} finally {
					hostsSave.disabled = false
				}
			})

			nativeBox.addEventListener('change', async () => {
				const value = nativeBox.checked
				nativeBox.disabled = true
				try {
					show(await put(() => ({ nativeClientEnabled: value })))
					report(nativeStatus, true)
				} catch (e) {
					nativeBox.checked = !value
					if (!(await recover(e))) {
						report(nativeStatus, false)
						context.notifyError(t('mcp', 'Could not change the native client.'))
					}
				} finally {
					nativeBox.disabled = false
				}
			})
		}


		/**
		 * Checkout upload limit of the status block, in whole MiB, through GET and PUT /apps/mcp/api/checkout-limit.
		 * Next to it: the effective limit and PHP's post_max_size ceiling.
		 */
		async function initCheckoutLimit() {
			const input = document.getElementById('mcp-checkout-limit')
			const save = document.getElementById('mcp-checkout-limit-save')
			const status = document.getElementById('mcp-checkout-limit-status')
			const info = document.getElementById('mcp-checkout-limit-info')
			const mib = (bytes) => Math.round(bytes / 1048576 * 10) / 10

			/** @param {object} data limit returned by the API, in bytes */
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


		/**
		 * Server log section: the switch that hides the text of log messages in the log tools, through GET and PUT
		 * /apps/mcp/api/logs-privacy.
		 */
		async function initLogsPrivacy() {
			const box = document.getElementById('mcp-logs-hide-file-names')
			const status = document.getElementById('mcp-logs-hide-file-names-status')
			if (!box) {
				return
			}
			// The "Learn more" button opens the explanation that ships in the template; without JS it stays open.
			const more = document.getElementById('mcp-logs-privacy-more')
			const detail = document.getElementById('mcp-logs-privacy-detail')
			if (more && detail) {
				detail.hidden = true
				more.hidden = false
				more.addEventListener('click', () => {
					detail.hidden = !detail.hidden
					more.setAttribute('aria-expanded', detail.hidden ? 'false' : 'true')
				})
			}
			try {
				box.checked = (await context.api('GET', '/api/logs-privacy')).hideFileNames
			} catch (e) {
				box.disabled = true
				context.notifyError(t('mcp', 'Could not load the log privacy setting.'))
				return
			}
			box.addEventListener('change', async () => {
				const value = box.checked
				box.disabled = true
				try {
					box.checked = (await context.api('PUT', '/api/logs-privacy', { hideFileNames: value })).hideFileNames
					status.textContent = t('mcp', 'Saved')
					context.flash(status, true)
				} catch (e) {
					box.checked = !value
					status.textContent = t('mcp', 'Not saved')
					context.flash(status, false)
					context.notifyError(t('mcp', 'Could not change the log privacy setting.'))
				} finally {
					box.disabled = false
				}
			})
		}


		/**
		 * Server log section: the groups whose members may read the log besides the administrators, through
		 * GET and PUT /apps/mcp/api/logs-access. The picker locks while a save runs and each save carries
		 * the list it changed (`previous`), so the server answers 409 when another window or administrator
		 * saved first and a removed group is never put back. A listed group that was deleted shows as an
		 * option marked "(group removed)", to be removed.
		 */
		async function initLogsAccess() {
			const container = document.getElementById('mcp-logs-groups')
			const status = document.getElementById('mcp-logs-status')
			const summary = document.getElementById('mcp-logs-summary')
			if (!container || !window.OCA.Mcp.Admin.picker) {
				return
			}
			let version = 0
			// The block's own revision, from its own GET or save; never the matrix's, never an error body's.
			let logsRevision = null
			let picker = null
			context.followRevision(() => logsRevision, (revision) => { logsRevision = revision })

			/** @param {object} data state returned by the API */
			function show(data) {
				version = data.version
				logsRevision = data.revision
				const options = data.allGroups.map((group) => ({ id: group.id, label: group.displayName }))
				for (const id of data.missing) {
					options.push({ id: id, label: t('mcp', '{group} (group removed)', { group: id }, undefined, { escape: false }) })
				}
				if (!picker) {
					picker = window.OCA.Mcp.Admin.picker.create({
						container,
						status,
						options,
						selectedIds: data.groups,
						searchLabel: t('mcp', 'Search groups'),
						listLabel: t('mcp', 'Server log groups'),
						emptyOptionsText: t('mcp', 'No groups found; only administrators can read the log.'),
						noMatchText: t('mcp', 'No match.'),
						save: async (nextIds, previousIds) => {
							const sent = logsRevision
							// The server stores the groups as a set and commits only a set other than `previous` (LogsAccess).
							const committed = new Set(nextIds).size !== new Set(previousIds).size || nextIds.some((id) => !previousIds.includes(id))
							const data = await context.api('PUT', '/api/logs-access', { groups: nextIds, previous: previousIds, version: version, expectedRevision: sent })
							if (committed && context.oneStep(sent, data.revision)) {
								context.announce(sent, data.revision)
							}
							return data
						},
						onSaved: async (data) => {
							show(data)
							// The matrix shows the log column per user from the same gate, so it is reloaded.
							context.load()
						},
						onError: async (e) => {
							if (e.message === '409' || e.message === '428') {
								context.notifyError(t('mcp', 'The groups were changed in another window or by another administrator; the current list was loaded.'))
								context.load()
							} else {
								context.notifyError(t('mcp', 'Could not save the groups that may read the server log.'))
							}
							await reload()
						},
					})
				} else {
					picker.setOptions(options, data.groups)
				}
				paintSummary(data.groups, data.allGroups.map((group) => ({ id: group.id, label: group.displayName })))
			}

			/**
			 * The effective readers, as one line: groups are a gate on top of the per-user grant of the matrix.
			 *
			 * @param {string[]} groups the saved group ids
			 * @param {Array<{id: string, label: string}>} options the known groups, for their display names
			 */
			function paintSummary(groups, options) {
				if (!summary) {
					return
				}
				const readers = options.filter((option) => groups.includes(option.id))
				const names = readers.map((option) => option.label).join(', ')
				try {
					summary.textContent = readers.length === 0
						? t('mcp', 'Who may read the log: administrators only. Each of them also needs the “Server log” permission in the “Access and permissions” tab.')
						: n('mcp',
							'Who may read the log: administrators and the members of %n group ({names}). Each of them also needs the “Server log” permission in the “Access and permissions” tab.',
							'Who may read the log: administrators and the members of %n groups ({names}). Each of them also needs the “Server log” permission in the “Access and permissions” tab.',
							readers.length, { names }, { escape: false })
				} catch {
					// Optional explanatory text must never prevent managing the actual groups.
					summary.textContent = ''
				}
			}

			/** Loads the list again, after a conflict or a failure. */
			async function reload() {
				try {
					show(await context.api('GET', '/api/logs-access'))
				} catch (e) {
					if (picker) {
						picker.showFallback(t('mcp', 'Could not load the groups.'))
					} else {
						container.setAttribute('aria-busy', 'false')
						container.replaceChildren(context.el('p', { className: 'mcp-empty', textContent: t('mcp', 'Could not load the groups.') }))
					}
				}
			}

			await reload()
		}

		Object.assign(context, { initCheckoutLimit, initLogsAccess, initLogsPrivacy, initOauthClients, initTagsSection })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
