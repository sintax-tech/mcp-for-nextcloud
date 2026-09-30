<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

use RuntimeException;

/** The optional spreed app is disabled for the user or its classes are not loaded; never reaches the client message. */
class TalkUnavailableException extends RuntimeException {
    public function __construct() {
        parent::__construct(Messages::TALK_UNAVAILABLE);
    }
}
