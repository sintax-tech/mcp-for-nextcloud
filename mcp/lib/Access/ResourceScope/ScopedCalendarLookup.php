<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\ResourceScope;

use OCA\Mcp\Platform\Dav\Calendar;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** Resolves calendars through native visibility and ACL narrowed by the current operation resource scope. */
interface ScopedCalendarLookup {
	/**
	 * The visible calendars of the component, inside the scope of its module.
	 *
	 * @param string $userId authenticated UID
	 * @param string $component VEVENT for the calendar tools, VTODO for the task tools
	 * @return list<Calendar> the native list, in its order, without the calendars outside the scope
	 * @throws PolicyUnavailable when the scope cannot be read
	 */
	public function visible(string $userId, string $component = 'VEVENT'): array;

	/**
	 * @param string $userId authenticated UID
	 * @param string $path "/remote.php/dav/calendars/<uid>/<uri>/", trailing slash optional
	 * @param string $component VEVENT or VTODO
	 * @return Calendar the visible calendar inside the scope
	 * @throws ToolFailure not found when the path is foreign, malformed, not visible or outside the scope
	 * @throws PolicyUnavailable when the scope cannot be read
	 */
	public function resolve(string $userId, string $path, string $component = 'VEVENT'): Calendar;

	/**
	 * The scope comes before the write check: a calendar outside it is missing, never "read-only".
	 *
	 * @param string $userId authenticated UID
	 * @param string $path calendar path
	 * @param string $component VEVENT or VTODO
	 * @return Calendar the writable calendar inside the scope
	 * @throws ToolFailure not found as in resolve(), forbidden when it cannot be written
	 * @throws PolicyUnavailable when the scope cannot be read
	 */
	public function resolveWritable(string $userId, string $path, string $component = 'VEVENT'): Calendar;
}
