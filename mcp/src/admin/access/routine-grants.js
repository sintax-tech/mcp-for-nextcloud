/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Shared grant wording for routine features; no rendering or permission effects. */
(function (admin) {
	'use strict'

	/** @return {Object<string,string>} Translated module names. */
	function moduleLabels() {
		return {
			"activity": t('mcp', 'Activity'),
			"notifications": t('mcp', 'Notifications'),
			"availability": t('mcp', 'Absence'),
			"status": t('mcp', 'User status'),
			"routines": t('mcp', 'Routines')
		}
	}

	/** @return {Object<string,string>} Translated operation names. */
	function operationLabels() {
		return {
			"availability": t('mcp', 'check availability'),
			"dismiss": t('mcp', 'dismiss notifications'),
			"absence": t('mcp', 'set absence'),
			"labels": t('mcp', 'manage board labels'),
			"tags": t('mcp', 'manage tags'),
			"comments": t('mcp', 'add comments'),
			"prepare": t('mcp', 'prepare meetings'),
			"meeting": t('mcp', 'create meetings'),
			"mailtask": t('mcp', 'create tasks from email')
		}
	}

	/**
	 * @param {string} module Grant module.
	 * @param {string} operation Grant operation.
	 * @return {string} Specific accessible description, or empty for an existing grant.
	 */
	function operationHint(module, operation) {
		return {
			"calendar.availability": t('mcp', 'Check local free/busy availability without reading event details; starts off'),
			"activity.read": t('mcp', 'Read own activity events with period, app and folder filters; starts off'),
			"notifications.read": t('mcp', 'Read own notifications; starts off'),
			"notifications.dismiss": t('mcp', 'Dismiss own notifications; starts off'),
			"availability.read": t('mcp', 'Read own current or planned absence; starts off'),
			"availability.absence": t('mcp', 'Create or update own absence; starts off'),
			"status.read": t('mcp', 'Read own configured status and message; starts off'),
			"status.edit": t('mcp', 'Change own status and message; starts off'),
			"deck.labels": t('mcp', 'Create, edit and permanently delete Deck board labels, and use labels when creating a card; starts off'),
			"files.tags": t('mcp', 'Assign or unassign existing file tags; starts off'),
			"files.comments": t('mcp', 'Add comments to accessible files; starts off'),
			"routines.prepare": t('mcp', 'Prepare a meeting using permitted sources; starts off'),
			"routines.meeting": t('mcp', 'Create a private meeting with a Talk room and agenda; starts off'),
			"routines.mailtask": t('mcp', 'Create a task from email and save its attachment privately; starts off')
		}[module + '.' + operation] || ''
	}

	admin.routineGrants = { moduleLabels, operationLabels, operationHint }
})((globalThis.window || globalThis).OCA.Mcp.Admin)
