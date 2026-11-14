<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notifications;

use OCA\Mcp\Modules\Notifications\Tools\DismissNotification;
use OCA\Mcp\Modules\Notifications\Tools\ListNotifications;

/** Ordered notifications catalog; new tools append here. */
final class NotificationsToolCatalog {
	/** Own-notification read, then the one-id dismiss. */
	public const TOOLS = [ListNotifications::class, DismissNotification::class];
	/** Static catalog only. */
	private function __construct() {}
}
