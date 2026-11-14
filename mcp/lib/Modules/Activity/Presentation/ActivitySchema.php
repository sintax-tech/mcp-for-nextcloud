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
	public const NOTE = 'Lists the authenticated user\'s own Activity feed, newest first. Returns id/app/datetime and current authorized references for known Files and simple Calendar VEVENT formats, with a normalized action. Source read grants, Guests, native ACL, scope, exclusions and backups still apply. Historical subjects, messages, names, paths, links and parameters are never copied. Every recognized parameter reference must be authorized or the entire event is omitted. File references show current metadata; a move without rename also references the current destination folder. folder_id selects events whose file object is a current strict descendant by ID and ancestry, not stored paths. Every alias must pass, including aliases outside the requested folder. Folder itself and non-file events are excluded by folder_id. Unsupported, deleted, hidden, unresolvable or inseparable references are omitted without hidden counts. Deck, VTODO, Calendar moves and collection/share activities remain omitted. References describe the current object, not its historical state. Optional from/to ISO timestamps with explicit offsets filter the inclusive/exclusive period; app is an exact app ID. since is a native activity ID, never a timestamp. Returns at most 50 items after examining at most 500 native events per call, preserving native feed preferences. next_since advances past every examined event, including filtered events. Use the returned continuation deliberately; never automatically scan the whole history. partial is always true because coverage is limited; truncated means the bounded scan has not exhausted the native feed. An empty partial page never proves absence of activity. Missing, expired or foreign continuation IDs are refused; restart explicitly with since=0. Changes between pages are not a snapshot. No Activity formatter, provider, preview, notifier or read marker is invoked. The same protected actor-root contract as Files may initialize native mounts/home folders; the tool performs no mutation. File aliases are bounded to 50 and ancestry to 128 metadata steps; an unverifiable chain is omitted.';
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
				'folder_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Current authorized folder ID. Only events whose file object is a current strict descendant; every other reference still needs full authorization; requires files.read.'],
				'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 50],
			],
		], app: 'activity', requires: ['activity.native']);
	}
}
