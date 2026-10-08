<?php
declare(strict_types=1);

namespace OCA\Mcp\Service;

/** The list of groups that may read the log changed since the caller read it: nothing was written. */
class LogsGroupsConflict extends \RuntimeException {
}
