<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

use RuntimeException;

/**
 * Domain failure whose message is safe to show to the user (Portuguese, no internals).
 * The module turns it into an MCP result with isError.
 */
final class CalendarException extends RuntimeException {
    /**
     * @return self calendar or event missing, or not visible to the user
     */
    public static function notFound(): self {
        return new self('Calendário ou evento não encontrado.');
    }

    /**
     * @return self the user cannot write to the resource
     */
    public static function forbidden(): self {
        return new self('Sem permissão para alterar este calendário ou evento.');
    }

    /**
     * @param string $reason Portuguese sentence explaining the conflict
     * @return self concurrent change, duplicate UID or unsupported change
     */
    public static function conflict(string $reason): self {
        return new self('Conflito: ' . $reason);
    }

    /**
     * @param string $message Portuguese sentence naming the limit
     * @return self a configured limit was exceeded
     */
    public static function limit(string $message): self {
        return new self($message);
    }

    /**
     * @param string $message Portuguese sentence explaining why the operation is blocked
     * @return self a safety precondition failed
     */
    public static function blocked(string $message): self {
        return new self($message);
    }
}
