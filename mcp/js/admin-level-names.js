/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Level names of the modules whose tiers are not Read, Edit and Full, for js/admin-levels.js. Mail only reads: its
 * first tier lists accounts and folders, its second opens the messages, its third adds the text of attachments and its
 * fourth and fifth the Mail writes, flags and then moving, so its levels are named after that and none of them is called
 * Full.
 *
 * Pure data and one lookup, no DOM and no strings: js/admin-grants.js translates the names.
 */
(function (root) {
	'use strict'

	/** Names of the tiers of a module, in tier order. */
	const MODULE_TIER_NAMES = {
		forms: ['read', 'forms_write', 'forms_write_results'],
		mail: ['folders', 'messages', 'attachments', 'flags', 'move', 'draft', 'send'],
	}

	/**
	 * The names of a module's tiers, when the module has its own and their number matches.
	 *
	 * @param {string} module module id
	 * @param {number} count number of tiers the module has
	 * @return {string[]|null} the names, or null to use the general ones
	 */
	function forModule(module, count) {
		const own = MODULE_TIER_NAMES[module]
		return own && own.length === count ? own : null
	}

	root.McpAdminLevelNames = { MODULE_TIER_NAMES, forModule }
})(typeof window !== 'undefined' ? window : globalThis)
