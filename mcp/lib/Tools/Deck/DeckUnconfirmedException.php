<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck;

use RuntimeException;

/**
 * A Deck write threw, and reading the state again could not tell whether it was saved.
 *
 * Deck 1.17.5 writes first and only then runs activity, events and notifications, so an exception of the write call
 * does not prove that nothing was written. When the read-back fails too, the honest answer is "unknown": the tool
 * answers with a result that is not an error and says which tool reads the item, so the client looks before it
 * retries and never builds a duplicate. Only the class of the original exception (the previous one) is logged.
 */
final class DeckUnconfirmedException extends RuntimeException {
	/**
	 * @param string $readWith Tool that reads the item back, named in the answer (for example `deck_read_card`).
	 * @param \Throwable $cause What the write call raised; kept as the previous exception for the log class only.
	 */
	public function __construct(public readonly string $readWith, \Throwable $cause) {
		parent::__construct('The Deck write could not be confirmed', 0, $cause);
	}
}
