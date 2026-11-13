<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\Dav;

/**
 * Outcome of one dispatched DAV request: the HTTP status, the ETag when the pipeline reported one, for reads the body,
 * and the Content-Location of a created resource when the pipeline names it.
 *
 * The ETag is only present when the response carried it. Sabre omits it once a plugin has modified
 * the data (DAV/CorePlugin.php:428-518), so the callers re-read the object to learn the current ETag.
 */
final class DavResult {
	/**
	 * @param int $status HTTP status the pipeline answered with
	 * @param string|null $etag ETag reported in the response, quoted as the backend returns it
	 * @param string|null $body response body of a read (a multistatus document), null for writes
	 * @param string|null $location Content-Location of the created resource, as the pipeline set it
	 */
	public function __construct(
		public readonly int $status,
		public readonly ?string $etag = null,
		public readonly ?string $body = null,
		public readonly ?string $location = null,
	) {}
}
