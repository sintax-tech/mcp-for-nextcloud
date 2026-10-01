<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools;

/** A tool failure whose message is safe to show to the MCP client (no paths, content or internals). */
class ToolFailure extends \RuntimeException {
    /** Missing node, or node outside the scope the tool may see. */
    public const NOT_FOUND = 'Resource not found in Nextcloud.';
    /** Nextcloud denied the operation (ACL, share permissions). */
    public const FORBIDDEN = 'No access to this resource in Nextcloud.';
    /** The node is locked by another operation. */
    public const LOCKED = 'Resource locked by another operation; try again.';
    /** The caller's etag no longer matches. */
    public const CONFLICT = 'The resource has changed since the last read (etag differs); nothing was written.';
}
