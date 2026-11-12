<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Activity\Presentation;

use OCA\Mcp\Mcp\Tools\ToolDefinition;

/** Shared schema and fixed guide note for the first conservative Activity slice. */
final class ActivitySchema {
	/** No hidden item counts or completeness claims can be inferred from the coverage message. */
	public const LIMITED = 'Coverage is limited; this feed is not a complete activity history.';
	/** English model guidance, deliberately shared by discovery and guide. */
	public const NOTE = 'Lists only the authenticated user\'s own Activity feed metadata (id, app and datetime), newest first. File- and object-related events do not appear in this slice, nor do subjects, messages, links or parameters. Optional from/to ISO timestamps with explicit offsets filter the inclusive/exclusive period; app is an exact app ID. since is a native activity ID, never a timestamp. Returns at most 50 items after examining at most 500 native events per call, preserving native feed preferences. next_since advances past every examined event, including filtered events. Use the returned continuation deliberately; never automatically scan the whole history. partial is always true because coverage is limited; truncated means the bounded scan has not exhausted the native feed. An empty partial page never proves absence of activity. Retention and changes between pages can affect continuation. No formatter, provider, preview, notifier or read marker is invoked; nothing is written.';
	/** Static schema only. */
	private function __construct() {}
	/** @return ToolDefinition bounded read with the default-denied activity.read grant */
	public static function definition(): ToolDefinition {
		return new ToolDefinition('activity_list_events', 'activity', 'read', self::NOTE, [
			'type' => 'object', 'additionalProperties' => false, 'properties' => [
				'from' => ['type' => 'string', 'format' => 'date-time', 'description' => 'Inclusive ISO timestamp with Z or an explicit offset.'],
				'to' => ['type' => 'string', 'format' => 'date-time', 'description' => 'Exclusive ISO timestamp with Z or an explicit offset.'],
				'app' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'pattern' => '^[a-z0-9_]+$', 'description' => 'Exact native app ID; applied after the authorized native read.'],
				'since' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Last native activity ID examined; zero starts at the newest event.'],
				'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 50],
			],
		], app: 'activity', requires: ['activity.native']);
	}
}
