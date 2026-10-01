<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

use OCA\Mcp\Tools\ToolFailure;

/**
 * Domain failure whose message is safe to show to the user (English source text, translated through Translator; no internals).
 * The module turns it into an MCP result with isError; as a ToolFailure the registry would do the same.
 */
final class CalendarException extends ToolFailure {
    /**
     * @return self calendar or event missing, or not visible to the user
     */
    public static function notFound(): self {
        return new self(CalendarMessages::notFound());
    }

    /**
     * @return self the user cannot write to the resource
     */
    public static function forbidden(): self {
        return new self(CalendarMessages::forbidden());
    }

    /**
     * @param string $reason translated sentence explaining the conflict
     * @return self concurrent change, duplicate UID or unsupported change
     */
    public static function conflict(string $reason): self {
        return new self(CalendarMessages::conflict($reason));
    }

    /**
     * @param string $message translated sentence naming the limit
     * @return self a configured limit was exceeded
     */
    public static function limit(string $message): self {
        return new self($message);
    }

    /**
     * @param string $message translated sentence explaining why the operation is blocked
     * @return self a safety precondition failed
     */
    public static function blocked(string $message): self {
        return new self($message);
    }
}
