<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck;

use RuntimeException;

/**
 * Raised when a card changed between the read and the write.
 *
 * It is a conflict and not a permission problem, so it gets its own message; it never reaches the
 * client with the exception class or the underlying Deck detail.
 */
final class DeckConflictException extends RuntimeException {
}
