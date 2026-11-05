<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

use OCA\Deck\Sharing\DeckShareProvider;
use OCP\Constants;
use OCP\Files\File;
use OCP\Share\IManager;
use OCP\Share\IShare;
use OCA\Mcp\Tools\Deck\CardFiles;

/**
 * Narrow native type-12 creation and canonical node/card observation, with no file mutations.
 */
final class NativeCardFileLink {
	/**
	 * Maximum actor-initiated independent shares included in a complete authorized preview.
	 */
	private const MAX_VISIBLE_SHARES = 1000;

	/**
	 * Compose native creation and the Deck provider's original-node lookup.
	 *
	 * @param IManager $shares Native sharing manager; always used for creation and policy enforcement.
	 * @param DeckServiceContext $services Caller-bound optional Deck services.
	 * @return void
	 */
	public function __construct(private IManager $shares, private DeckServiceContext $services) {
	}

	/**
	 * Observe the exact canonical node/card association, irrespective of its creator.
	 *
	 * No association for another card is exposed. Any malformed/ambiguous result fails closed.
	 *
	 * @param string $uid Authenticated actor, bound before native provider access.
	 * @param int $cardId Already authorized card.
	 * @param File $file Already authorized canonical file.
	 * @return IShare|null Exact native association or null when absent.
	 * @throws \Throwable On ambiguous or unreadable native state.
	 */
	public function find(string $uid, int $cardId, File $file): ?IShare {
		$this->services->bindUser($uid);
		$found = null;
		foreach ($this->services->get(DeckShareProvider::class)->getSharesByPath($file) as $share) {
			if ($share->getShareType() === IShare::TYPE_DECK && (string)$share->getSharedWith() === (string)$cardId) {
				if ($found !== null || (int)$share->getNodeId() !== (int)$file->getId()) {
					throw CardFiles::unavailable();
				}
				$found = $share;
			}
		}
		return $found;
	}

	/**
	 * List only actor-initiated shares the native manager permits the actor to inspect.
	 *
	 * No provider-wide access list, other initiators, public tokens or download URLs are disclosed.
	 *
	 * @param string $uid Authenticated actor.
	 * @param File $file Already authorized visible file.
	 * @return list<array{id:string,type:int,recipient:?string,permissions:int}> Stable limited share metadata.
	 * @throws \Throwable On incomplete or uncertain native listing.
	 */
	public function visibleShares(string $uid, File $file): array {
		$out = [];
		foreach ([IShare::TYPE_USER, IShare::TYPE_GROUP, IShare::TYPE_LINK] as $type) {
			$items = $this->shares->getSharesBy($uid, $type, $file, false, self::MAX_VISIBLE_SHARES + 1, 0);
			if (count($items) + count($out) > self::MAX_VISIBLE_SHARES) {
				throw CardFiles::unavailable();
			}
			foreach ($items as $share) {
				if ($share->getSharedBy() !== $uid || (int)$share->getNodeId() !== (int)$file->getId()
					|| $share->getShareType() !== $type) {
					throw CardFiles::unavailable();
				}
				$out[] = ['id' => (string)$share->getFullId(), 'type' => $type,
					'recipient' => $type === IShare::TYPE_LINK ? null : $share->getSharedWith(),
					'permissions' => (int)$share->getPermissions()];
			}
		}
		usort($out, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
		return $out;
	}

	/**
	 * Create one native read-only association after the complete confirmed state was revalidated.
	 *
	 * A thrown call may already have persisted; callers must observe rather than retry automatically.
	 *
	 * @param string $uid Authenticated actor.
	 * @param int $cardId Editable active card.
	 * @param File $file Visible canonical node with READ and SHARE.
	 * @return IShare Native created association; its exact ID supports subsequent verification.
	 * @throws \Throwable On native refusal or error, possibly after persistence.
	 */
	public function create(string $uid, int $cardId, File $file): IShare {
		$this->services->bindUser($uid);
		$share = $this->shares->newShare();
		$share->setNode($file)->setShareType(IShare::TYPE_DECK)->setSharedWith((string)$cardId)
			->setSharedBy($uid)->setPermissions(Constants::PERMISSION_READ);
		return $this->shares->createShare($share);
	}
}
