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
	/** Static schema only. */
	private function __construct() {}
	/** @return ToolDefinition bounded opt-in metadata read */
	public static function definition(): ToolDefinition {
		return new ToolDefinition('notifications_list', 'notifications', 'read', self::NOTE, [
			'type' => 'object', 'additionalProperties' => false,
			'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50]],
		], app: 'notifications', requires: ['notifications.native']);
	}
}
