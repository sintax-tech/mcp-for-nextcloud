<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck;

use RuntimeException;

/**
 * Raised before any Deck call when the session does not hold the authenticated caller.
 *
 * The Deck services receive their `userId` from the DI container, which reads `ISession::get('user_id')`.
 * When that value is missing or belongs to another account, Deck would write first and fail afterwards
 * (`CardService::create()` then `enrichCards()`), so the gateway refuses up front and nothing is written.
 */
final class DeckSessionException extends RuntimeException {
}
