<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

use RuntimeException;

/**
 * A conversation exists but this user cannot use it for this operation. Carries the cause only for logging: the
 * caller answers with a generic message so the module never reveals whether a room exists but is closed.
 */
class ConversationAccessException extends RuntimeException {
    public function __construct(string $message, ?\Throwable $previous = null) {
        parent::__construct($message, 0, $previous);
    }
}
