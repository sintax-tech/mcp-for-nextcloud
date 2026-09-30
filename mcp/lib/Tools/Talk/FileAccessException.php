<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

use RuntimeException;

/**
 * A file the module could not reach or could not share, carrying the message the caller is allowed to see.
 *
 * It exists so a missing file, a file the user cannot share and a file that is not a file stay indistinguishable,
 * while "already shared in this conversation" can still be specific, which is a fact about the caller's own action.
 */
class FileAccessException extends RuntimeException {
    public function __construct(string $message, ?\Throwable $previous = null) {
        parent::__construct($message, 0, $previous);
    }
}
