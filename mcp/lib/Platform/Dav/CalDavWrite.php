<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\Dav;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** Writes authorized CalDAV objects through the official DAV pipeline, preserving ACL, validation and If-Match. */
interface CalDavWrite {
	/**
	 * Creates a calendar object, refusing to overwrite an existing one.
	 *
	 * @param string $userId authenticated UID
	 * @param string $calendarUri calendar URI in the user's calendar home
	 * @param string $objectUri new object URI
	 * @param string $ics serialized iCalendar
	 * @param bool $scheduling false sends `x-nc-scheduling: false`, so nothing is scheduled
	 * @param int|null $sizeLimit maximum ICS size in bytes, read from dav/event_size_limit when null
	 * @param list<int> $acceptedStatuses HTTP statuses that count as success
	 * @return DavResult the status, and the ETag when the pipeline reported one
	 * @throws ToolFailure when the pipeline refuses the write
	 */
	public function put(string $userId, string $calendarUri, string $objectUri, string $ics, bool $scheduling = false, ?int $sizeLimit = null, array $acceptedStatuses = [201]): DavResult;

	/**
	 * Replaces a calendar object, always guarded by its current ETag.
	 *
	 * @param string $userId authenticated UID
	 * @param string $calendarUri calendar URI in the user's calendar home
	 * @param string $objectUri object URI
	 * @param string $etag ETag read from the backend, sent as If-Match
	 * @param string $ics serialized iCalendar
	 * @param bool $scheduling false sends `x-nc-scheduling: false`, so nothing is scheduled
	 * @param int|null $sizeLimit maximum ICS size in bytes, read from dav/event_size_limit when null
	 * @param list<int> $acceptedStatuses HTTP statuses that count as success
	 * @return DavResult the status, and the ETag when the pipeline reported one
	 * @throws ToolFailure when the pipeline refuses the write
	 */
	public function update(string $userId, string $calendarUri, string $objectUri, string $etag, string $ics, bool $scheduling = false, ?int $sizeLimit = null, array $acceptedStatuses = [204]): DavResult;

	/**
	 * Deletes a calendar object into the CalDAV trash.
	 *
	 * @param string $userId authenticated UID
	 * @param string $calendarUri calendar URI in the user's calendar home
	 * @param string $objectUri object URI
	 * @param string|null $etag ETag sent as If-Match, or null to skip the guard
	 * @param bool $scheduling false sends `x-nc-scheduling: false`, so no CANCEL is scheduled
	 * @param list<int> $acceptedStatuses HTTP statuses that count as success
	 * @return DavResult the status of the deletion
	 * @throws ToolFailure when the pipeline refuses the deletion
	 */
	public function delete(string $userId, string $calendarUri, string $objectUri, ?string $etag = null, bool $scheduling = false, array $acceptedStatuses = [204]): DavResult;
}
