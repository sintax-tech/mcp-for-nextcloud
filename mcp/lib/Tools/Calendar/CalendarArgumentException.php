<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

use InvalidArgumentException;

/**
 * Invalid argument the input schema cannot express (date format, time zone, range order).
 * The registry answers it with JSON-RPC -32602; the message is Portuguese and safe to show.
 */
final class CalendarArgumentException extends InvalidArgumentException {
}
