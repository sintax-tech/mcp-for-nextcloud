<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

use RuntimeException;

/**
 * A confirmed call arrived without a usable proof that its draft was shown first: no approval id, an id nobody
 * issued, an id that already published something, an id that expired, or an id issued for another user or for
 * another content. All of them are the same answer, so the module never reveals which part of the approval failed.
 */
class ApprovalException extends RuntimeException {
    public function __construct(string $message, ?\Throwable $previous = null) {
        parent::__construct($message, 0, $previous);
    }
}