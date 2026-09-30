<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

/**
 * A room already resolved for the authenticated user, together with that user's participant.
 *
 * Both are OCA\Talk objects held untyped on purpose: the class may not exist in this installation and the module
 * must compile and be testable without it.
 */
final class Conversation {
    public function __construct(
        public readonly object $room,
        public readonly object $participant,
    ) {}
}
