<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Service\AttachmentService;
use OCA\Deck\Service\PermissionService;
use OCA\Deck\Sharing\DeckShareProvider;
use OCP\Files\File;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IShare;
use OCA\Mcp\Modules\Deck\Attachments\CardFiles;
use OCA\Mcp\Modules\Deck\Failures\DeckConflictException;
use OCA\Mcp\Modules\Deck\Presentation\DeckMessages;
use OCA\Mcp\Modules\Deck\Failures\DeckRefusalException;

/** Native card-file associations; Files visibility remains the responsibility of CardFiles. */
final class DeckCardFileGateway {
	/**
	 * Share the caller-bound native service context and reference boundary of one facade.
	 *
	 * @param DeckServiceContext $services Lazily resolved services from the injected container.
	 * @param CardReferenceFilter $references Fresh READ boundary with operation-local parent listings.
	 */
	public function __construct(private DeckServiceContext $services, private CardReferenceFilter $references) {
	}

	/**
	 * Fresh native parent context for file reads/writes; no native attachment metadata/count is returned.
	 *
	 * An inactive marker binds this native context only after READ and EDIT have both succeeded.
	 *
	 * @param string $userId Authenticated caller.
	 * @param int $cardId Source card.
	 * @param bool $edit Require EDIT and active card/board for a mutation.
	 * @return array{card: Card, boardId: int, board: array} Internal authorized context.
	 * @throws \Throwable On missing/denied card, inactive parents or uncertain lookup.
	 */
	public function cardFileContext(string $userId, int $cardId, bool $edit): array {
		$view = [];
		$source = $this->references->target($userId, $cardId, $view);
		if ($source === null) {
			throw new NoPermissionException('Permission denied');
		}
		$board = $view['boards'][$source['boardId']];
		if ($edit) {
			$this->services->get(PermissionService::class)->checkPermission($this->services->get(CardMapper::class), $cardId, Acl::PERMISSION_EDIT);
			if ($source['card']->getArchived() || $board->getArchived()) {
				throw DeckRefusalException::inactiveFileSource($userId, $cardId, $source['boardId']);
			}
		}
		$owner = (string)$board->getOwner();
		return $source + ['board' => ['owner' => $owner, 'ownerDisplayName' => $this->services->displayName($owner),
			'name' => (string)$board->getTitle()]];
	}

	/**
	 * Native type-12 file shares after source READ. Internal only: callers MUST filter through Files guards.
	 * @param string $userId Authenticated caller.
	 * @param int $cardId Readable source.
	 * @return list<IShare> Native associations; never serialize directly.
	 * @throws \Throwable On denied source or provider failure.
	 */
	public function cardFileShares(string $userId, int $cardId): array {
		$this->cardFileContext($userId, $cardId, false);
		return array_values($this->services->get(DeckShareProvider::class)->getSharedWithByType($cardId, IShare::TYPE_DECK, -1, 0));
	}

	/**
	 * One native file association after source READ and exact type/card identity checks.
	 * @param string $userId Authenticated caller.
	 * @param int $cardId Readable source.
	 * @param int $attachmentId Bare share ID, distinct from fileid.
	 * @return IShare|null Internal association, null only for native ShareNotFound.
	 * @throws \Throwable For wrong-card/type, source denial or uncertain lookup.
	 */
	public function cardFileShare(string $userId, int $cardId, int $attachmentId): ?IShare {
		$this->cardFileContext($userId, $cardId, false);
		try {
			$share = $this->services->get(DeckShareProvider::class)->getShareById((string)$attachmentId);
		} catch (ShareNotFound) {
			return null;
		}
		if ($share->getShareType() !== IShare::TYPE_DECK || (string)$share->getSharedWith() !== (string)$cardId
			|| (int)$share->getId() !== $attachmentId) {
			throw new NoPermissionException('Permission denied');
		}
		return $share;
	}

	/**
	 * Observes a previously authorized association through the provider's unfiltered original-file query.
	 * This avoids mistaking a trash/inaccessible node (ShareNotFound in getShareById) for a deleted share.
	 * @param string $userId Authenticated caller, source READ still required.
	 * @param int $cardId Source of a previously authorized unlink attempt.
	 * @param int $attachmentId Previously authorized share ID.
	 * @param File $node Previously authorized original node; only its stable ID is used after unlink.
	 * @return bool Whether that exact association remains; no other share data leaves the gateway.
	 * @throws \Throwable On uncertain provider lookup or changed source/card binding.
	 */
	public function cardFileShareExists(string $userId, int $cardId, int $attachmentId, File $node): bool {
		$this->cardFileContext($userId, $cardId, false);
		foreach ($this->services->get(DeckShareProvider::class)->getSharesByPath($node) as $share) {
			if ((int)$share->getId() === $attachmentId) {
				if ((string)$share->getSharedWith() !== (string)$cardId || $share->getShareType() !== IShare::TYPE_DECK) {
					throw new NoPermissionException('Permission denied');
				}
				return true;
			}
		}
		return false;
	}

	/**
	 * Revalidates native source and association identity, then uses AttachmentService::delete(..., 'file').
	 * @param string $userId Authenticated caller.
	 * @param int $cardId Editable active source.
	 * @param int $attachmentId Share ID of the visible file association.
	 * @param array<string, mixed> $expected Expected identity from an authorized Files state.
	 * @return void
	 * @throws DeckConflictException Before the native API when identity changed.
	 * @throws \Throwable On denial or native error, possibly after persistence.
	 */
	public function unlinkCardFile(string $userId, int $cardId, int $attachmentId, array $expected): void {
		$this->cardFileContext($userId, $cardId, true);
		$share = $this->cardFileShare($userId, $cardId, $attachmentId);
		if ($share === null || CardFiles::identity($share) !== $expected) {
			throw new DeckConflictException(DeckMessages::filePlanChanged());
		}
		$this->services->get(AttachmentService::class)->delete($cardId, $attachmentId, 'file');
	}
}
