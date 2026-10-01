<?php
declare(strict_types=1);
namespace OCA\Mcp\Tools\Calendar;

use Closure;
use Sabre\VObject\Component\VCalendar;

/** Request-local proposed write; no ICS or closure is persisted in the approval store. */
final class PreparedCalendarWrite {
    public function __construct(
        public readonly Calendar $source,
        public readonly ?Calendar $target,
        public readonly ?StoredEvent $before,
        public readonly ?VCalendar $after,
        private Closure $write,
    ) {}

    public function dispatch(): array {
        return ($this->write)();
    }
}
