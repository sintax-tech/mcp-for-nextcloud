<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

/**
 * Outcome of one dispatched CalDAV request: the HTTP status and, when the pipeline reported one, the ETag.
 *
 * The ETag is only present when the response carried it. Sabre omits it once a plugin has modified
 * the data (DAV/CorePlugin.php:428-518), so the callers re-read the object to learn the current ETag.
 */
final class DavResult {
    /**
     * @param int $status HTTP status the pipeline answered with
     * @param string|null $etag ETag reported in the response, quoted as the backend returns it
     */
    public function __construct(
        public readonly int $status,
        public readonly ?string $etag = null,
    ) {}
}