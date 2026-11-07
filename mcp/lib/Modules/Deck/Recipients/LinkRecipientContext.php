<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Recipients;

/**
 * Internal target binding; DK-4b must authorize the Files node before exposing a link plan.
 */
final readonly class LinkRecipientContext {
	/**
	 * Fixed operation audience reused by the existing PlanState HMAC mechanism.
	 */
	public const OPERATION = 'deck_link_card_file';

	/**
	 * Bind identities and the observed file version without reading or mutating Files.
	 *
	 * @param string $actorUid Authenticated caller, never a display name.
	 * @param int $boardId Expected native board, checked freshly by the resolver.
	 * @param int $cardId Expected editable source card.
	 * @param int $fileId Existing file identity, authorized by the future link handler.
	 * @param string $etag File version authorized by the future link handler.
	 * @return void
	 * @throws DeckRefusalException When target identities are invalid.
	 */
	public function __construct(public string $actorUid, public int $boardId, public int $cardId,
		public int $fileId, public string $etag) {
		if ($actorUid === '' || $boardId <= 0 || $cardId <= 0 || $fileId <= 0 || $etag === ''
			|| preg_match('//u', $actorUid) !== 1 || preg_match('//u', $etag) !== 1) {
			throw \OCA\Mcp\Modules\Deck\Attachments\CardFiles::unavailable();
		}
	}

	/**
	 * Return a fixed-order scalar binding suitable for PlanState.
	 *
	 * @return array{operation:string,actorUid:string,boardId:int,cardId:int,fileId:int,etag:string} Internal context.
	 */
	public function state(): array {
		return ['operation' => self::OPERATION, 'actorUid' => $this->actorUid, 'boardId' => $this->boardId,
			'cardId' => $this->cardId, 'fileId' => $this->fileId, 'etag' => $this->etag];
	}
}
