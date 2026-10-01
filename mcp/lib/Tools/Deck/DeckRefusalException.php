<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck;

use RuntimeException;

/**
 * A rule of the tools that refuses a Deck write on purpose, before anything is written.
 *
 * Unlike a Deck failure, its message is written by this module, already translated and free of any
 * Deck detail, so {@see DeckErrors} may hand it to the client as it is. It is a normal outcome (a list
 * that still holds cards, a board that is not the caller's), not something to log as an error. The one
 * exception to "before anything is written" is the `*ReceivedCards(false)` pair, raised after the delete when
 * it could not be taken back: its message says the item is in the Deck trash.
 */
final class DeckRefusalException extends RuntimeException {
	/**
	 * @param int $cards Active and archived cards the list holds.
	 * @return self Refusal worded for a list.
	 */
	public static function stackNotEmpty(int $cards): self {
		return new self(DeckMessages::errorStackNotEmpty($cards));
	}

	/**
	 * @param int $cards Active and archived cards the lists of the board hold.
	 * @return self Refusal worded for a board.
	 */
	public static function boardNotEmpty(int $cards): self {
		return new self(DeckMessages::errorBoardNotEmpty($cards));
	}

	/**
	 * @param bool $restored Whether the list was brought back; false when it stayed in the Deck trash.
	 * @return self Refusal for a list that received a card while it was being deleted.
	 */
	public static function stackReceivedCards(bool $restored): self {
		return new self(DeckMessages::errorStackReceivedCards($restored));
	}

	/**
	 * @param bool $restored Whether the board was brought back; false when it stayed in the Deck trash.
	 * @return self Refusal for a board that received a card while it was being deleted.
	 */
	public static function boardReceivedCards(bool $restored): self {
		return new self(DeckMessages::errorBoardReceivedCards($restored));
	}

	/**
	 * @return self Refusal for a board the caller can manage but does not own.
	 */
	public static function boardNotOwned(): self {
		return new self(DeckMessages::errorBoardNotOwned());
	}
}
