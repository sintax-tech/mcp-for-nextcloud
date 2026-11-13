<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notifications\Presentation;

use OCA\Mcp\Mcp\Tools\ToolDefinition;

/** Strict own-notification schema and explicit limits of the opaque projection. */
final class NotificationsSchema {
	/** Fixed coverage warning, never a count of hidden source objects. */
	public const LIMITED = 'Coverage is limited; this is not a complete notification inbox.';
	/** Shared model guidance; no claims of formatted bell parity or historical pagination. */
	public const NOTE = 'Lists the authenticated user\'s latest own notifications, newest ID first, without marking anything read or dismissing anything. Returns only id, app and datetime; every notification is opaque. Subjects, messages, object references, parameters, links, icons, tokens and actions are never exposed. No source-format projector is provided yet, so the friendly text of the notification bell is unavailable. limit defaults to 50 and may be increased explicitly up to 200. partial is always true because this metadata view is limited; truncated is true when the native read reaches the requested limit, even if the inbox has exactly that many notifications. Native malformed rows may be omitted, so an empty result does not prove an empty inbox. There is no older-page cursor, delta cursor or stored watermark. getAfterId means newer IDs and is not used as historical pagination. No formatter, notifier, prepare, dismiss, markProcessed, push or read-marker maintenance is invoked. Requires the opt-in notifications.read grant and the compatible Notifications app.';
	/** One-id dismiss guidance. The list note stays true: listing still does not dismiss. */
	public const DISMISS = 'Dismisses one notification that belongs to the authenticated user. Nothing is removed until the same call returns with confirm set to true. The preview names only the id and, when the notification is in that user\'s inbox, the app and the datetime. A missing id and an id that belongs to somebody else are reported the same way. Impersonation is refused. The confirmed call follows the Notifications app dismiss endpoint: notifier callbacks, then the deletion of that user\'s row, then a device push, then a read-back. A partial or uncertain result is reported and is not retried. Subjects, messages, object references, parameters, links, icons, tokens and actions are never exposed. Requires the opt-in notifications.dismiss grant and the compatible Notifications app.';
	/** Static schema only. */
	private function __construct() {}
	/** @return ToolDefinition bounded opt-in metadata read */
	public static function definition(): ToolDefinition {
		return new ToolDefinition('notifications_list', 'notifications', 'read', self::NOTE, [
			'type' => 'object', 'additionalProperties' => false,
			'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50]],
		], app: 'notifications', requires: ['notifications.native']);
	}

	/** @return ToolDefinition one-id dismiss; confirm stays optional and is injected for writes */
	public static function dismiss(): ToolDefinition {
		return new ToolDefinition('notifications_dismiss', 'notifications', 'dismiss', self::DISMISS, [
			'type' => 'object', 'additionalProperties' => false, 'required' => ['id'],
			'properties' => ['id' => ['type' => 'integer', 'minimum' => 1]],
		], app: 'notifications', destructiveHint: true, requires: ['notifications.native']);
	}
}
