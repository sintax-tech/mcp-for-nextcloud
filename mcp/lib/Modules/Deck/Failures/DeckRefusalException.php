<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Failures;

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
	 * UID whose source READ and EDIT were checked by the native file gateway.
	 *
	 * @var string|null No user-controlled text can initialize this marker.
	 */
	private ?string $inactiveSourceUid = null;
	/**
	 * Native card whose source READ and EDIT preceded the inactive marker.
	 *
	 * @var int|null Internal binding, never serialized or logged.
	 */
	private ?int $inactiveSourceCardId = null;
	/**
	 * Native board observed during the same authorized source read.
	 *
	 * @var int|null Internal binding, never serialized or logged.
	 */
	private ?int $inactiveSourceBoardId = null;

	/**
	 * Mark an inactive file source only after the gateway checked READ and EDIT.
	 *
	 * @param string $uid Authenticated native caller.
	 * @param int $cardId Authorized source card.
	 * @param int $boardId Native board observed by that authorized read.
	 * @return self Sanitized refusal with a private context marker.
	 */
	public static function inactiveFileSource(string $uid, int $cardId, int $boardId): self {
		$failure = new self(\OCA\Mcp\Modules\Deck\Presentation\DeckAttachmentMessages::fileSourceInactive());
		$failure->inactiveSourceUid = $uid;
		$failure->inactiveSourceCardId = $cardId;
		$failure->inactiveSourceBoardId = $boardId;
		return $failure;
	}

	/**
	 * Recognize only a source marker tied to this exact authenticated context.
	 *
	 * @param string $uid Expected authenticated caller.
	 * @param int $cardId Expected source card.
	 * @param int $boardId Expected source board.
	 * @return bool Whether the private marker has this exact binding.
	 */
	public function marksInactiveFileSource(string $uid, int $cardId, int $boardId): bool {
		return $this->inactiveSourceUid === $uid && $this->inactiveSourceCardId === $cardId
			&& $this->inactiveSourceBoardId === $boardId;
	}

	/** Whether the item was deleted after all: the `*ReceivedCards(false)` pair, whose undo failed. */
	private bool $afterWrite = false;

	/**
	 * @return bool true when the refusal came after the delete and the item stayed in the Deck trash, so the tool
	 *     answers with a partial result instead of an error that would invite a retry
	 */
	public function afterWrite(): bool {
		return $this->afterWrite;
	}

	/**
	 * @param int $cards Active and archived cards the list holds.
	 * @return self Refusal worded for a list.
	 */
	public static function stackNotEmpty(int $cards): self {
		return new self(\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::errorStackNotEmpty($cards));
	}

	/**
	 * @param int $cards Active and archived cards the lists of the board hold.
	 * @return self Refusal worded for a board.
	 */
	public static function boardNotEmpty(int $cards): self {
		return new self(\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::errorBoardNotEmpty($cards));
	}

	/**
	 * @param bool $restored Whether the list was brought back; false when it stayed in the Deck trash.
	 * @return self Refusal for a list that received a card while it was being deleted.
	 */
	public static function stackReceivedCards(bool $restored): self {
		$refusal = new self(\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::errorStackReceivedCards($restored));
		$refusal->afterWrite = !$restored;

		return $refusal;
	}

	/**
	 * @param bool $restored Whether the board was brought back; false when it stayed in the Deck trash.
	 * @return self Refusal for a board that received a card while it was being deleted.
	 */
	public static function boardReceivedCards(bool $restored): self {
		$refusal = new self(\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::errorBoardReceivedCards($restored));
		$refusal->afterWrite = !$restored;

		return $refusal;
	}

	/**
	 * @return self Refusal for a board the caller can manage but does not own.
	 */
	public static function boardNotOwned(): self {
		return new self(\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::errorBoardNotOwned());
	}
}
